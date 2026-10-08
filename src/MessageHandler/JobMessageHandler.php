<?php

namespace TomAtom\JobQueueBundle\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Process\Process;
use Throwable;
use TomAtom\JobQueueBundle\Entity\Job;
use TomAtom\JobQueueBundle\Message\JobMessage;
use TomAtom\JobQueueBundle\Output\OutputLimiter;
use TomAtom\JobQueueBundle\Output\OutputParamsParser;
use TomAtom\JobQueueBundle\Service\JobOutputWriter;

/**
 * Runs the command of a job in a subprocess and stores its output and result.
 *
 * Rules (see TT-SERVER-CQ):
 * - the handler never holds a database transaction open - all writes go through {@see JobOutputWriter} in autocommit,
 * - the wait loop is throttled: output is written and cancellation checked at most once per poll interval,
 * - a short database outage does not kill the command - output stays buffered and the writes are retried,
 * - a failing command is a job status, not a handler exception; the only exception leaving the handler is a failure
 *   to store the job result, and it is unrecoverable so the command is never run twice by a message retry,
 * - a redelivered message never runs a command again unless rerun_on_redelivery is enabled.
 */
#[AsMessageHandler]
class JobMessageHandler
{
    public const REDELIVERED_MESSAGE = '[JobQueueBundle] message redelivered while job was RUNNING - previous worker died; not re-running';
    public const RERUN_MESSAGE = '[JobQueueBundle] message redelivered while job was RUNNING - previous worker died; running the command again';

    /** Sleep between checks of the subprocess */
    private const LOOP_SLEEP_US = 100_000;
    /** Buffered output size which triggers a write before the poll interval elapses */
    private const FLUSH_THRESHOLD_BYTES = 1_048_576;
    private const FINALIZE_ATTEMPTS = 5;

    private readonly string $workingDirectory;

    /**
     * @param int $pollIntervalMs How often the output is written and the cancellation checked (job_queue.processing.poll_interval_ms)
     * @param int $outputMaxBytes Cap of the stored output, 0 = unlimited (job_queue.processing.output_max_bytes)
     * @param int $dbFailureTolerance Consecutive failed database polls after which the job is given up (job_queue.processing.db_failure_tolerance)
     * @param bool $rerunOnRedelivery Run the command again when the message of a RUNNING job is redelivered (job_queue.processing.rerun_on_redelivery)
     * @param string|null $workingDirectory Directory with bin/console, defaults to the application root
     * @param int $finalizeRetryDelayMs Delay between attempts to store the job result
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly JobOutputWriter        $writer,
        private readonly int                    $pollIntervalMs = 1000,
        private readonly int                    $outputMaxBytes = 4194304,
        private readonly int                    $dbFailureTolerance = 30,
        private readonly bool                   $rerunOnRedelivery = false,
        private readonly ?LoggerInterface       $logger = null,
        ?string                                 $workingDirectory = null,
        private readonly int                    $finalizeRetryDelayMs = 1000,
    )
    {
        $this->workingDirectory = $workingDirectory ?? dirname(__DIR__, 5);
    }

    public function __invoke(JobMessage $message): void
    {
        $connection = $this->writer->getConnection();
        // Transactions opened by a caller (e.g. a doctrine_transaction middleware) are not ours to roll back
        $callerNestingLevel = $connection->getTransactionNestingLevel();

        try {
            $this->handle($message->getJobId());
        } finally {
            // Never leave a transaction (or a PDO handle which believes in one) behind for the worker - its
            // ack/reject/retry send runs on the same connection right after the handler
            $this->writer->resetConnection(false, $callerNestingLevel);
        }
    }

    private function handle(int $jobId): void
    {
        $job = $this->entityManager->find(Job::class, $jobId);
        if (!$job instanceof Job) {
            throw new UnrecoverableMessageHandlingException(sprintf('Job #%d does not exist.', $jobId));
        }

        // Read the status from the database - the identity map of a long-running worker may hold a stale entity
        $status = $this->writer->readStatus($jobId) ?? $job->getStatus();
        $commandLine = $this->buildCommandLine($job);
        $previousStartedAt = $job->getStartedAt();

        // From now on only the job id is used, nothing may flush stale data of this entity
        $this->entityManager->detach($job);
        unset($job);

        $fromStatuses = [Job::STATUS_PLANNED];
        $rerunNote = '';
        if ($status === Job::STATUS_RUNNING) {
            if (!$this->rerunOnRedelivery) {
                $this->logger?->warning('Job #{id} is already RUNNING, the message was redelivered - marking the job as failed.', ['id' => $jobId]);
                $closedAt = new DateTimeImmutable();
                $this->finalizeWithRetry(
                    $jobId,
                    Job::STATUS_FAILED,
                    $closedAt,
                    $previousStartedAt?->diff($closedAt),
                    null,
                    "\n\n" . self::REDELIVERED_MESSAGE
                );
                return;
            }
            $fromStatuses[] = Job::STATUS_RUNNING;
            $rerunNote = "\n\n" . self::RERUN_MESSAGE . "\n\n";
        } elseif ($status !== Job::STATUS_PLANNED) {
            // completed / failed / cancelled - the message was already processed
            $this->logger?->info('Job #{id} is already {status}, ignoring the message.', ['id' => $jobId, 'status' => $status]);
            return;
        }

        $startedAt = new DateTimeImmutable();
        if (!$this->writer->markRunning($jobId, $startedAt, $fromStatuses)) {
            $this->logger?->info('Job #{id} was claimed by another worker, ignoring the message.', ['id' => $jobId]);
            return;
        }

        $limiter = new OutputLimiter($this->outputMaxBytes, $rerunNote === '' ? 0 : $this->safeOutputLength($jobId));
        $parser = new OutputParamsParser();
        $pending = $rerunNote;

        $cancelled = false;
        $error = null;
        $process = null;

        try {
            $process = Process::fromShellCommandline($commandLine);
            $process->setWorkingDirectory($this->workingDirectory);
            $process->enableOutput();
            $process->setTimeout(null);
            $process->start();

            $pollInterval = max(0, $this->pollIntervalMs) / 1000;
            $tolerance = max(1, $this->dbFailureTolerance);
            $lastPoll = microtime(true);
            $dbFailures = 0;

            while ($process->isRunning()) {
                usleep(self::LOOP_SLEEP_US);
                $pending .= $this->collectOutput($process, $parser, $limiter);

                $dueByTime = microtime(true) - $lastPoll >= $pollInterval;
                $dueBySize = $dbFailures === 0 && strlen($pending) >= self::FLUSH_THRESHOLD_BYTES;
                if (!$dueByTime && !$dueBySize) {
                    continue;
                }
                $lastPoll = microtime(true);

                try {
                    if ($pending !== '') {
                        $this->writer->append($jobId, $pending, $this->hardCap());
                        $pending = '';
                    }
                    if ($this->writer->readStatus($jobId) === Job::STATUS_CANCELLED) {
                        $cancelled = true;
                        $process->stop(0);
                        break;
                    }
                    $dbFailures = 0;
                } catch (DBALException $e) {
                    // Transient database problem: the output stays buffered (bounded by the limiter), the command keeps running
                    $dbFailures++;
                    $this->logger?->warning('Job #{id}: database write failed ({failures}/{tolerance}): {message}', [
                        'id' => $jobId,
                        'failures' => $dbFailures,
                        'tolerance' => $tolerance,
                        'message' => $e->getMessage(),
                        'exception' => $e,
                    ]);
                    if ($dbFailures >= $tolerance) {
                        $error = $e;
                        $process->stop(0);
                        break;
                    }
                }
            }
        } catch (Throwable $e) {
            $error = $e;
            $this->logger?->error('Job #{id}: {message}', ['id' => $jobId, 'message' => $e->getMessage(), 'exception' => $e]);
            if ($process !== null && $process->isRunning()) {
                $process->stop(0);
            }
        }

        // Remaining output after the process stopped
        if ($process !== null) {
            try {
                $pending .= $this->collectOutput($process, $parser, $limiter);
            } catch (Throwable) {
            }
        }
        $parser->finish();

        $closedAt = new DateTimeImmutable();
        $suffix = '';
        if ($cancelled) {
            $status = Job::STATUS_CANCELLED;
            $suffix = "\n\n" . Job::JOB_CANCELLED_MESSAGE;
        } elseif ($error !== null) {
            $status = Job::STATUS_FAILED;
            $suffix = "\n\n" . $error->getMessage();
        } else {
            $status = $process !== null && $process->isSuccessful() ? Job::STATUS_COMPLETED : Job::STATUS_FAILED;
        }

        $this->finalizeWithRetry(
            $jobId,
            $status,
            $closedAt,
            $startedAt->diff($closedAt),
            $parser->getOutputParams(),
            $suffix,
            $pending
        );
    }

    /**
     * Stores the job result. This is the most important write of the job, so it is retried with a reset connection.
     *
     * @throws UnrecoverableMessageHandlingException when the result cannot be stored - the message must not be
     *                                               retried, because that would run the command again
     */
    private function finalizeWithRetry(
        int               $jobId,
        string            $status,
        DateTimeImmutable $closedAt,
        ?\DateInterval    $runtime,
        ?string           $outputParams,
        string            $suffix,
        string            $pending = ''
    ): void
    {
        $last = null;
        for ($attempt = 1; $attempt <= self::FINALIZE_ATTEMPTS; $attempt++) {
            try {
                if ($pending !== '') {
                    $this->writer->append($jobId, $pending, $this->hardCap());
                    $pending = '';
                }
                $this->writer->finalize($jobId, $status, $closedAt, $runtime, $outputParams, $suffix);
                return;
            } catch (Throwable $e) {
                $last = $e;
                $this->logger?->warning('Job #{id}: storing the result failed (attempt {attempt}/{attempts}): {message}', [
                    'id' => $jobId,
                    'attempt' => $attempt,
                    'attempts' => self::FINALIZE_ATTEMPTS,
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]);
                $this->writer->resetConnection(JobOutputWriter::isConnectionLost($e));
                if ($attempt < self::FINALIZE_ATTEMPTS && $this->finalizeRetryDelayMs > 0) {
                    usleep($this->finalizeRetryDelayMs * 1000);
                }
            }
        }

        // Clean connection for the worker's reject / failure transport send
        $this->writer->resetConnection(true);

        throw new UnrecoverableMessageHandlingException(
            sprintf('Job #%d: the result (%s) could not be stored: %s', $jobId, $status, $last?->getMessage()),
            0,
            $last
        );
    }

    private function collectOutput(Process $process, OutputParamsParser $parser, OutputLimiter $limiter): string
    {
        $chunk = $process->getIncrementalErrorOutput() . $process->getIncrementalOutput();
        if ($chunk === '') {
            return '';
        }
        $parser->feed($chunk);

        return $limiter->accept($chunk);
    }

    /**
     * Database-side cap of the output column (the limiter keeps the output below it, this is a safety net).
     */
    private function hardCap(): int
    {
        if ($this->outputMaxBytes <= 0) {
            return 0;
        }

        return $this->outputMaxBytes + strlen(OutputLimiter::marker($this->outputMaxBytes)) + strlen(self::RERUN_MESSAGE) + 16;
    }

    private function safeOutputLength(int $jobId): int
    {
        try {
            return $this->writer->readOutputLength($jobId);
        } catch (Throwable) {
            return 0;
        }
    }

    private function buildCommandLine(Job $job): string
    {
        $command = 'php bin/console ' . $job->getCommand();
        $params = $job->getCommandParams();
        if ($params !== null && $params !== [] && $params !== '') {
            if (is_array($params)) {
                $command .= ' ' . implode(' ', $params);
            } else {
                $command .= ' ' . $params;
            }
        }

        return $command;
    }
}

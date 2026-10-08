<?php

namespace TomAtom\JobQueueBundle\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Process\Process;
use Throwable;
use TomAtom\JobQueueBundle\Entity\Job;
use TomAtom\JobQueueBundle\Message\JobMessage;
use TomAtom\JobQueueBundle\Output\OutputLimiter;
use TomAtom\JobQueueBundle\Output\OutputParamsParser;
use TomAtom\JobQueueBundle\Output\Utf8Stream;
use TomAtom\JobQueueBundle\Service\JobOutputWriter;

/**
 * Runs the command of a job in a subprocess and stores its output and result.
 *
 * Rules (see TT-SERVER-CQ):
 * - the handler never holds a database transaction open - all writes go through {@see JobOutputWriter} in autocommit,
 * - the wait loop is throttled: output is written and cancellation checked at most once per poll interval,
 * - a short database outage does not kill the command - output stays buffered and the writes are retried,
 * - a failing command is a job status, not a handler exception; after the command has started, the only exception
 *   leaving the handler is a failure to store the job result, and it is unrecoverable so the command is never run
 *   twice by a message retry (a database failure before the claim is retried locally and then recoverable),
 * - everything written is valid UTF-8 and bounded; output a working database rejects never blocks the job result,
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
    /** Attempts to load and claim the job (nothing has run yet, so retrying is safe) */
    private const PRE_CLAIM_ATTEMPTS = 3;
    /** Consecutive rejections (by a working database) after which a buffered output chunk is replaced by a note */
    private const REJECTED_APPEND_ATTEMPTS = 2;
    /** Longest exception message stored in the output */
    private const MESSAGE_MAX_BYTES = 2048;

    private readonly string $workingDirectory;

    /**
     * @param int $pollIntervalMs How often the output is written and the cancellation checked (job_queue.processing.poll_interval_ms)
     * @param int $outputMaxBytes Cap of the stored output, 0 = unlimited (job_queue.processing.output_max_bytes)
     * @param int $dbFailureTolerance Consecutive failed database polls after which the job is given up (job_queue.processing.db_failure_tolerance)
     * @param bool $rerunOnRedelivery Run the command again when the message of a RUNNING job is redelivered (job_queue.processing.rerun_on_redelivery)
     * @param string|null $workingDirectory Directory with bin/console, defaults to the application root
     * @param int $finalizeRetryDelayMs Delay between attempts to load, claim and finalize the job
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
        // Transactions opened by a caller (e.g. a doctrine_transaction middleware) are not ours to roll back
        $previousCallerNestingLevel = $this->writer->getCallerNestingLevel();
        $this->writer->setCallerNestingLevel($this->writer->getConnection()->getTransactionNestingLevel());

        try {
            $this->handle($message->getJobId());
        } finally {
            // Never leave a transaction (or a PDO handle which believes in one) behind for the worker - its
            // ack/reject/retry send runs on the same connection right after the handler
            $this->writer->resetConnection(false);
            $this->writer->setCallerNestingLevel($previousCallerNestingLevel);
        }
    }

    private function handle(int $jobId): void
    {
        [$status, $commandLine, $previousStartedAt] = $this->loadJob($jobId);

        $fromStatuses = [Job::STATUS_PLANNED];
        $rerunNote = '';
        if ($status === Job::STATUS_RUNNING) {
            // Only a hint that the previous worker died: with several consumers and a job running longer than
            // redeliver_timeout (consumer without --keepalive) the first worker may still be running it (see README)
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
        if (!$this->claim($jobId, $startedAt, $fromStatuses, $rerunNote)) {
            $this->logger?->info('Job #{id} was claimed by another worker, ignoring the message.', ['id' => $jobId]);
            return;
        }

        $limiter = new OutputLimiter($this->outputMaxBytes, $rerunNote === '' ? 0 : $this->safeOutputLength($jobId));
        $streams = ['err' => new Utf8Stream(), 'out' => new Utf8Stream()];
        $parsers = ['err' => new OutputParamsParser(), 'out' => new OutputParamsParser()];
        $pending = '';

        $cancelled = false;
        $deleted = false;
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
            $rejectedAppends = 0;

            while ($process->isRunning()) {
                usleep(self::LOOP_SLEEP_US);
                $pending .= $this->collectOutput($process, $streams, $parsers, $limiter);

                $dueByTime = microtime(true) - $lastPoll >= $pollInterval;
                $dueBySize = $dbFailures === 0 && strlen($pending) >= self::FLUSH_THRESHOLD_BYTES;
                if (!$dueByTime && !$dueBySize) {
                    continue;
                }
                $lastPoll = microtime(true);

                $appending = false;
                try {
                    if ($pending !== '') {
                        $appending = true;
                        $this->writer->append($jobId, $pending, $this->hardCap());
                        $appending = false;
                        $pending = '';
                    }
                    $rejectedAppends = 0;
                    $current = $this->writer->readStatus($jobId);
                    if ($current === Job::STATUS_CANCELLED) {
                        $cancelled = true;
                        $process->stop(0);
                        break;
                    }
                    if ($current === null) {
                        // The job row was deleted while its command runs - nothing can record the result
                        $this->logger?->warning('Job #{id} was deleted while its command was running - stopping the command.', ['id' => $jobId]);
                        $deleted = true;
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
                    // An output chunk the database keeps rejecting although the connection is fine (e.g. a value or
                    // packet size error) is replaced by a note - it must not get a healthy command killed
                    if ($appending && !JobOutputWriter::isConnectionLost($e) && ++$rejectedAppends >= self::REJECTED_APPEND_ATTEMPTS) {
                        $pending = self::outputRejectedNote($e, strlen($pending));
                        $rejectedAppends = 0;
                    }
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

        if ($deleted) {
            return;
        }

        // Remaining output after the process stopped
        if ($process !== null) {
            try {
                $pending .= $this->collectOutput($process, $streams, $parsers, $limiter);
            } catch (Throwable) {
            }
        }
        foreach (['err', 'out'] as $stream) {
            $rest = $streams[$stream]->finish();
            $parsers[$stream]->feed($rest);
            $parsers[$stream]->finish();
            $pending .= $limiter->accept($rest);
        }

        $closedAt = new DateTimeImmutable();
        $suffix = '';
        if ($cancelled) {
            $status = Job::STATUS_CANCELLED;
            $suffix = "\n\n" . Job::JOB_CANCELLED_MESSAGE;
        } elseif ($error !== null) {
            $status = Job::STATUS_FAILED;
            $suffix = "\n\n" . self::safeMessage($error);
        } else {
            $status = $process !== null && $process->isSuccessful() ? Job::STATUS_COMPLETED : Job::STATUS_FAILED;
        }

        $params = array_merge($parsers['out']->getParams(), $parsers['err']->getParams());

        $this->finalizeWithRetry(
            $jobId,
            $status,
            $closedAt,
            $startedAt->diff($closedAt),
            $params === [] ? null : implode(', ', $params),
            $suffix,
            $pending
        );
    }

    /**
     * Reads what the handler needs from the job, retried - nothing has run yet, so a transient database error must
     * not lose the message (with max_retries: 0 it would never be handled again).
     *
     * @return array{0: string, 1: string, 2: DateTimeImmutable|null} status, command line, previous start
     */
    private function loadJob(int $jobId): array
    {
        $last = null;
        for ($attempt = 1; $attempt <= self::PRE_CLAIM_ATTEMPTS; $attempt++) {
            if ($attempt > 1) {
                $this->retryDelay();
            }
            try {
                $job = $this->entityManager->find(Job::class, $jobId);
                if (!$job instanceof Job) {
                    // The transaction creating the job may not be committed yet - look again before giving up
                    $last = null;
                    continue;
                }

                // Read the status from the database - the identity map of a long-running worker may hold a stale entity
                $status = $this->writer->readStatus($jobId) ?? $job->getStatus();
                $result = [$status, $this->buildCommandLine($job), $job->getStartedAt()];

                // From now on only the job id is used, nothing may flush stale data of this entity
                $this->entityManager->detach($job);

                return $result;
            } catch (DBALException $e) {
                $last = $e;
                $this->logger?->warning('Job #{id}: loading the job failed (attempt {attempt}/{attempts}): {message}', [
                    'id' => $jobId,
                    'attempt' => $attempt,
                    'attempts' => self::PRE_CLAIM_ATTEMPTS,
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]);
                $this->writer->resetConnection(JobOutputWriter::isConnectionLost($e));
            }
        }

        if ($last === null) {
            throw new UnrecoverableMessageHandlingException(sprintf('Job #%d does not exist.', $jobId));
        }

        // Nothing has run - the message may safely be handled again later (retried even with max_retries: 0)
        throw new RecoverableMessageHandlingException(sprintf('Job #%d could not be loaded: %s', $jobId, $last->getMessage()), 0, $last);
    }

    /**
     * Claims the job, retried. After a failed claim the job is read first: the UPDATE may have been applied
     * although its response was lost.
     *
     * @param list<string> $fromStatuses
     */
    private function claim(int $jobId, DateTimeImmutable $startedAt, array $fromStatuses, string $rerunNote): bool
    {
        $last = null;
        for ($attempt = 1; $attempt <= self::PRE_CLAIM_ATTEMPTS; $attempt++) {
            try {
                if ($attempt > 1) {
                    $this->retryDelay();
                    $claim = $this->writer->readClaim($jobId);
                    if ($claim === null) {
                        return false;
                    }
                    if ($claim['status'] === Job::STATUS_RUNNING && $claim['startedAt'] === $this->writer->formatDateTime($startedAt)) {
                        return true; // the previous attempt was applied
                    }
                    if (!in_array($claim['status'], $fromStatuses, true)) {
                        return false;
                    }
                }

                return $this->writer->markRunning($jobId, $startedAt, $fromStatuses, $rerunNote);
            } catch (DBALException $e) {
                $last = $e;
                $this->logger?->warning('Job #{id}: claiming the job failed (attempt {attempt}/{attempts}): {message}', [
                    'id' => $jobId,
                    'attempt' => $attempt,
                    'attempts' => self::PRE_CLAIM_ATTEMPTS,
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }

        throw new RecoverableMessageHandlingException(sprintf('Job #%d could not be claimed: %s', $jobId, $last?->getMessage()), 0, $last);
    }

    /**
     * Stores the job result. This is the most important write of the job, so it is retried with a reset connection,
     * and it never depends on less important writes: output the database rejects is replaced by a note, and after a
     * rejected (not lost) result write only the bare result is written.
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
                $pending = $this->appendPending($jobId, $pending);
                $this->writer->finalize($jobId, $status, $closedAt, $runtime, $outputParams, $suffix, $this->finalizeCap());
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
                $lost = JobOutputWriter::isConnectionLost($e);
                if (!$lost) {
                    // Rejected by a working database - keep only what cannot be poisoned by the output
                    $outputParams = null;
                    $suffix = '';
                }
                $this->writer->resetConnection($lost);
                if ($attempt < self::FINALIZE_ATTEMPTS) {
                    $this->retryDelay();
                }
            }
        }

        // Clean connection for the worker's reject / failure transport send (a caller-owned transaction is kept)
        $this->writer->resetConnection($this->writer->getCallerNestingLevel() === 0);

        throw new UnrecoverableMessageHandlingException(
            sprintf('Job #%d: the result (%s) could not be stored: %s', $jobId, $status, $last?->getMessage()),
            0,
            $last
        );
    }

    /**
     * Appends the remaining output. Only a lost connection is thrown (and retried with the output); output which a
     * working database rejects is replaced by a note, which is dropped if it is rejected as well.
     *
     * @return string the output still to append ('' when done)
     */
    private function appendPending(int $jobId, string $pending): string
    {
        if ($pending === '') {
            return '';
        }

        try {
            $this->writer->append($jobId, $pending, $this->hardCap());
        } catch (DBALException $e) {
            if (JobOutputWriter::isConnectionLost($e)) {
                throw $e;
            }
            $this->logger?->warning('Job #{id}: the remaining output was rejected and is replaced by a note: {message}', [
                'id' => $jobId,
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
            try {
                $this->writer->append($jobId, self::outputRejectedNote($e, strlen($pending)), $this->hardCap());
            } catch (DBALException $noteError) {
                if (JobOutputWriter::isConnectionLost($noteError)) {
                    throw $noteError;
                }
            }
        }

        return '';
    }

    /**
     * @param array{err: Utf8Stream, out: Utf8Stream} $streams
     * @param array{err: OutputParamsParser, out: OutputParamsParser} $parsers
     */
    private function collectOutput(Process $process, array $streams, array $parsers, OutputLimiter $limiter): string
    {
        $chunks = ['err' => $process->getIncrementalErrorOutput(), 'out' => $process->getIncrementalOutput()];
        // Symfony Process keeps the whole output otherwise (php://temp - a file growing without bound)
        $process->clearErrorOutput();
        $process->clearOutput();

        $result = '';
        foreach ($chunks as $stream => $chunk) {
            // Each stream separately - a character or an OUTPUT PARAMS line split between two reads stays intact
            $chunk = $streams[$stream]->feed($chunk);
            if ($chunk === '') {
                continue;
            }
            $parsers[$stream]->feed($chunk);
            $result .= $limiter->accept($chunk);
        }

        return $result;
    }

    private static function outputRejectedNote(Throwable $e, int $bytes): string
    {
        // The driver's message - the DBAL 3 message would repeat the rejected chunk as a query parameter
        while ($e->getPrevious() !== null) {
            $e = $e->getPrevious();
        }

        return sprintf("\n[JobQueueBundle: %d bytes of output could not be stored: %s]\n", $bytes, self::safeMessage($e, 500));
    }

    /**
     * Exception message which can be stored: valid UTF-8 and bounded (a DBAL 3 message contains the query parameters,
     * i.e. possibly the whole rejected output chunk).
     */
    private static function safeMessage(Throwable $e, int $maxBytes = self::MESSAGE_MAX_BYTES): string
    {
        $message = Utf8Stream::scrub($e->getMessage());
        if (strlen($message) > $maxBytes) {
            $message = substr($message, 0, Utf8Stream::boundary($message, $maxBytes)) . ' [...]';
        }

        return $message;
    }

    private function retryDelay(): void
    {
        if ($this->finalizeRetryDelayMs > 0) {
            usleep($this->finalizeRetryDelayMs * 1000);
        }
    }

    /**
     * Cap of the output when the result is stored: room for a status message, but never an unbounded append to an
     * output which is already too large (legacy jobs).
     */
    private function finalizeCap(): int
    {
        $hardCap = $this->hardCap();

        return $hardCap === 0 ? 0 : $hardCap + self::MESSAGE_MAX_BYTES + 64;
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

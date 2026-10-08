<?php

namespace TomAtom\JobQueueBundle\Tests\Integration;

use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use TomAtom\JobQueueBundle\Entity\Job;
use TomAtom\JobQueueBundle\Message\JobMessage;
use TomAtom\JobQueueBundle\MessageHandler\JobMessageHandler;
use TomAtom\JobQueueBundle\Output\OutputLimiter;
use TomAtom\JobQueueBundle\Service\JobOutputWriter;
use TomAtom\JobQueueBundle\Tests\Support\DatabaseTestCase;
use TomAtom\JobQueueBundle\Tests\Support\FaultInjector;

/**
 * Runs the real handler with a real subprocess (tests/Fixtures/app/bin/console) against SQLite.
 */
class JobMessageHandlerTest extends DatabaseTestCase
{
    private const OUTPUT_UPDATE = '/^UPDATE job_queue SET output = /';

    private function handler(
        int  $pollIntervalMs = 200,
        int  $outputMaxBytes = 4194304,
        int  $dbFailureTolerance = 30,
        bool $rerunOnRedelivery = false,
    ): JobMessageHandler
    {
        return new JobMessageHandler(
            $this->entityManager,
            new JobOutputWriter($this->entityManager),
            $pollIntervalMs,
            $outputMaxBytes,
            $dbFailureTolerance,
            $rerunOnRedelivery,
            null,
            dirname(__DIR__) . '/Fixtures/app',
            0
        );
    }

    public function testThrottledLoopWritesAllOutput(): void
    {
        $id = $this->createJob('test:lines', ['--count=30', '--sleep=100000']);

        $start = microtime(true);
        ($this->handler(pollIntervalMs: 500))(new JobMessage($id));
        $runtime = microtime(true) - $start;

        $row = $this->fetchJob($id);
        self::assertSame(Job::STATUS_COMPLETED, $row['status']);
        for ($i = 1; $i <= 30; $i++) {
            self::assertStringContainsString("line $i\n", $row['output']);
        }

        // At most one output write and one status read per poll interval (+ the final write)
        $maxPolls = (int)ceil($runtime / 0.5) + 2;
        self::assertLessThanOrEqual($maxPolls, count($this->queries->statements(self::OUTPUT_UPDATE)));
        self::assertLessThanOrEqual($maxPolls, count($this->queries->statements('/^SELECT status FROM job_queue/')));
        self::assertLessThan(30, count($this->queries->statements()), 'busy loop: too many queries');
    }

    public function testNoTransactionIsOpenedWhileTheJobRuns(): void
    {
        $id = $this->createJob('test:lines', ['--count=5', '--sleep=100000']);

        ($this->handler())(new JobMessage($id));

        self::assertSame(Job::STATUS_COMPLETED, $this->fetchJob($id)['status']);
        self::assertSame(0, $this->queries->count('Beginning transaction'));
        self::assertSame(0, $this->queries->count('Committing transaction'));
        $this->assertConnectionUsable();
    }

    public function testFailingCommandIsJobStatusNotException(): void
    {
        $id = $this->createJob('test:fail');

        ($this->handler())(new JobMessage($id));

        $row = $this->fetchJob($id);
        self::assertSame(Job::STATUS_FAILED, $row['status']);
        self::assertStringContainsString('boom', $row['output']);
        self::assertStringContainsString('error output', $row['output']);
        self::assertNotNull($row['closed_at']);
        self::assertNotNull($row['runtime']);
    }

    /**
     * Regression of TT-SERVER-CQ: the connection drops in the middle of the loop and pdo keeps believing in a
     * transaction. The job must survive and the connection must accept a new transaction afterwards
     * (the worker's retry/reject send runs on it).
     */
    public function testConnectionLostMidLoop(): void
    {
        $id = $this->createJob('test:lines', ['--count=12', '--sleep=100000']);
        $outputWrites = 0;
        $this->faults->failWhen(
            static function (string $sql) use (&$outputWrites): bool {
                return preg_match(self::OUTPUT_UPDATE, $sql) && ++$outputWrites === 2;
            },
            FaultInjector::CONNECTION_LOST_CODE,
            true
        );

        ($this->handler())(new JobMessage($id));
        $this->faults->disable();

        self::assertSame(1, $this->faults->injected);
        $row = $this->fetchJob($id);
        self::assertSame(Job::STATUS_COMPLETED, $row['status']);
        for ($i = 1; $i <= 12; $i++) {
            self::assertStringContainsString("line $i\n", $row['output'], 'buffered output was lost');
        }
        self::assertGreaterThanOrEqual(1, $this->queries->count('Disconnecting'));
        $this->assertConnectionUsable();
    }

    public function testDatabaseOutageLongerThanToleranceFailsTheJob(): void
    {
        $id = $this->createJob('test:lines', ['--count=50', '--sleep=100000']);
        // The first status read (before the claim) succeeds, every poll after it fails
        $reads = 0;
        $this->faults->failWhen(static function (string $sql) use (&$reads): bool {
            return preg_match('/^SELECT status FROM job_queue/', $sql) && ++$reads > 1;
        });

        $start = microtime(true);
        ($this->handler(pollIntervalMs: 100, dbFailureTolerance: 3))(new JobMessage($id));
        $this->faults->disable();

        self::assertLessThan(4.0, microtime(true) - $start, 'the command was not stopped');
        $row = $this->fetchJob($id);
        self::assertSame(Job::STATUS_FAILED, $row['status']);
        self::assertStringContainsString('MySQL server has gone away (injected)', $row['output']);
        $this->assertConnectionUsable();
    }

    public function testFinalizeFailureIsUnrecoverableAndLeavesNoTransaction(): void
    {
        $id = $this->createJob('test:lines', ['--count=2', '--sleep=10000']);
        $this->faults->failWhen(static fn(string $sql) => (bool)preg_match('/^UPDATE job_queue SET .*closed_at/', $sql), FaultInjector::CONNECTION_LOST_CODE, true);

        try {
            ($this->handler())(new JobMessage($id));
            self::fail('UnrecoverableMessageHandlingException expected');
        } catch (UnrecoverableMessageHandlingException $e) {
            self::assertStringContainsString('could not be stored', $e->getMessage());
        }
        $this->faults->disable();

        self::assertSame(5, $this->faults->injected, 'finalize is retried');
        self::assertSame(Job::STATUS_RUNNING, $this->fetchJob($id)['status']);
        $this->assertConnectionUsable();
    }

    public function testRedeliveredRunningJobIsNotRunAgain(): void
    {
        $marker = $this->databaseFile . '.ran';
        $id = $this->createJob('test:touch', ['--file=' . $marker], Job::STATUS_RUNNING, "first run output\n");

        ($this->handler())(new JobMessage($id));

        self::assertFileDoesNotExist($marker);
        $row = $this->fetchJob($id);
        self::assertSame(Job::STATUS_FAILED, $row['status']);
        self::assertStringStartsWith("first run output\n", $row['output']);
        self::assertStringContainsString(JobMessageHandler::REDELIVERED_MESSAGE, $row['output']);
        self::assertNotNull($row['closed_at']);
    }

    public function testRedeliveredRunningJobIsRunAgainWhenConfigured(): void
    {
        $marker = $this->databaseFile . '.ran';
        $id = $this->createJob('test:touch', ['--file=' . $marker], Job::STATUS_RUNNING, "first run output\n");

        try {
            ($this->handler(rerunOnRedelivery: true))(new JobMessage($id));

            self::assertFileExists($marker);
            $row = $this->fetchJob($id);
            self::assertSame(Job::STATUS_COMPLETED, $row['status']);
            self::assertStringContainsString(JobMessageHandler::RERUN_MESSAGE, $row['output']);
            self::assertStringContainsString('touched', $row['output']);
        } finally {
            @unlink($marker);
        }
    }

    /**
     * @dataProvider finishedStatuses
     */
    public function testFinishedJobIsNoOp(string $status): void
    {
        $marker = $this->databaseFile . '.ran';
        $id = $this->createJob('test:touch', ['--file=' . $marker], $status, 'old');

        ($this->handler())(new JobMessage($id));

        self::assertFileDoesNotExist($marker);
        self::assertSame($status, $this->fetchJob($id)['status']);
        self::assertSame('old', $this->fetchJob($id)['output']);
        self::assertSame([], $this->queries->statements('/^UPDATE/'));
    }

    public static function finishedStatuses(): array
    {
        return [[Job::STATUS_COMPLETED], [Job::STATUS_FAILED], [Job::STATUS_CANCELLED]];
    }

    public function testCancelStopsTheCommandAndKeepsCancelledStatus(): void
    {
        $id = $this->createJob('test:cancel-self');
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE job_queue SET command_params = ? WHERE id = ?',
            [sprintf('--db=%s,--id=%d', $this->databaseFile, $id), $id]
        );

        $start = microtime(true);
        ($this->handler())(new JobMessage($id));
        $elapsed = microtime(true) - $start;

        self::assertLessThan(3.0, $elapsed, 'the cancelled command was not stopped');
        $row = $this->fetchJob($id);
        self::assertSame(Job::STATUS_CANCELLED, $row['status']);
        self::assertStringContainsString('cancelled myself', $row['output']);
        self::assertStringContainsString(Job::JOB_CANCELLED_MESSAGE, $row['output']);
        self::assertStringNotContainsString('still running 99', $row['output']);
        self::assertNotNull($row['closed_at']);
    }

    public function testMissingJobIsUnrecoverable(): void
    {
        $this->expectException(UnrecoverableMessageHandlingException::class);

        ($this->handler())(new JobMessage(424242));
    }

    public function testOutputIsCapped(): void
    {
        $cap = 10000;
        $id = $this->createJob('test:big', ['--bytes=' . (3 * $cap), '--chunk=1000']);

        ($this->handler(outputMaxBytes: $cap))(new JobMessage($id));

        $row = $this->fetchJob($id);
        self::assertSame(Job::STATUS_COMPLETED, $row['status']);
        self::assertLessThanOrEqual($cap + strlen(OutputLimiter::marker($cap)), strlen($row['output']));
        self::assertStringEndsWith(OutputLimiter::marker($cap), $row['output']);
        self::assertSame(str_repeat('x', $cap), substr($row['output'], 0, $cap));
    }

    public function testUnlimitedOutput(): void
    {
        $id = $this->createJob('test:big', ['--bytes=30000', '--chunk=1000']);

        ($this->handler(outputMaxBytes: 0))(new JobMessage($id));

        self::assertSame(str_repeat('x', 30000), $this->fetchJob($id)['output']);
    }

    public function testOutputParamsAreAccumulatedAcrossChunks(): void
    {
        $id = $this->createJob('test:params');

        ($this->handler())(new JobMessage($id));

        $row = $this->fetchJob($id);
        self::assertSame(Job::STATUS_COMPLETED, $row['status']);
        self::assertSame('1, two, 3', $row['output_params']);
        self::assertStringEndsWith('done', $row['output']);
    }

    public function testManagedEntityIsNotWrittenByLaterFlush(): void
    {
        $id = $this->createJob('test:lines', ['--count=1', '--sleep=1000']);

        ($this->handler())(new JobMessage($id));
        // A flush elsewhere in the same worker must not overwrite the result with stale entity data
        $this->entityManager->flush();

        $job = $this->entityManager->find(Job::class, $id);
        self::assertSame(Job::STATUS_COMPLETED, $job->getStatus());
        self::assertSame("line 1\n", $job->getOutput());
    }
}

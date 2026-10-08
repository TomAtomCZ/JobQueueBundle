<?php

namespace TomAtom\JobQueueBundle\Tests\Integration;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\ConnectionLost;
use TomAtom\JobQueueBundle\Entity\Job;
use TomAtom\JobQueueBundle\Service\JobOutputWriter;
use TomAtom\JobQueueBundle\Tests\Support\DatabaseTestCase;
use TomAtom\JobQueueBundle\Tests\Support\FaultInjector;

class JobOutputWriterTest extends DatabaseTestCase
{
    private JobOutputWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writer = new JobOutputWriter($this->entityManager);
    }

    public function testAppendIsAutocommitAndAppends(): void
    {
        $id = $this->createJob('test:lines');

        $this->writer->append($id, "a\n");
        $this->writer->append($id, "b\n");

        self::assertSame("a\nb\n", $this->fetchJob($id)['output']);
        self::assertSame(0, $this->queries->count('Beginning transaction'));
    }

    public function testAppendRespectsDatabaseCap(): void
    {
        $id = $this->createJob('test:lines');

        $this->writer->append($id, '12345', 8);
        $this->writer->append($id, '6789', 8); // would exceed the cap - not written at all
        $this->writer->append($id, '678', 8);

        self::assertSame('12345678', $this->fetchJob($id)['output']);
    }

    public function testMarkRunningClaimsOnlyOnce(): void
    {
        $id = $this->createJob('test:lines');

        self::assertTrue($this->writer->markRunning($id, new DateTimeImmutable()));
        self::assertFalse($this->writer->markRunning($id, new DateTimeImmutable()));
        self::assertSame(Job::STATUS_RUNNING, $this->writer->readStatus($id));
        self::assertNotNull($this->fetchJob($id)['started_at']);
        self::assertNull($this->writer->readStatus($id + 100));
    }

    public function testRerunClaimAppendsItsNote(): void
    {
        $id = $this->createJob('test:lines', [], Job::STATUS_RUNNING, 'old');
        $startedAt = new DateTimeImmutable();

        self::assertTrue($this->writer->markRunning($id, $startedAt, [Job::STATUS_PLANNED, Job::STATUS_RUNNING], "\nrerun"));

        self::assertSame("old\nrerun", $this->fetchJob($id)['output']);
        self::assertSame(
            ['status' => Job::STATUS_RUNNING, 'startedAt' => $this->writer->formatDateTime($startedAt)],
            $this->writer->readClaim($id)
        );
        self::assertNull($this->writer->readClaim($id + 100));
    }

    public function testCancelOnlyCancelsARunningJob(): void
    {
        $running = $this->createJob('test:lines', [], Job::STATUS_RUNNING);
        $completed = $this->createJob('test:lines', [], Job::STATUS_COMPLETED);

        self::assertTrue($this->writer->cancel($running, new DateTimeImmutable()));
        self::assertFalse($this->writer->cancel($completed, new DateTimeImmutable()));

        self::assertSame(Job::STATUS_CANCELLED, $this->fetchJob($running)['status']);
        self::assertNotNull($this->fetchJob($running)['cancelled_at']);
        self::assertSame(Job::STATUS_COMPLETED, $this->fetchJob($completed)['status']);
        self::assertNull($this->fetchJob($completed)['cancelled_at']);
    }

    public function testFinalizeSkipsAppendBeyondCapButStoresResult(): void
    {
        $id = $this->createJob('test:lines', [], Job::STATUS_RUNNING, str_repeat('x', 20));

        $this->writer->finalize($id, Job::STATUS_FAILED, new DateTimeImmutable(), null, null, "\nmessage", 10);

        $row = $this->fetchJob($id);
        self::assertSame(Job::STATUS_FAILED, $row['status']);
        self::assertSame(str_repeat('x', 20), $row['output']);
        self::assertNotNull($row['closed_at']);
    }

    public function testFailureKeepsCallerTransaction(): void
    {
        $id = $this->createJob('test:lines');
        $this->writer->setCallerNestingLevel(1);
        $this->faults->failWhen(static fn(string $sql) => str_starts_with($sql, 'UPDATE'), 1153);

        $this->connection->beginTransaction();
        try {
            $this->writer->append($id, 'x');
            self::fail('exception expected');
        } catch (\Doctrine\DBAL\Exception) {
        }
        $this->faults->disable();

        self::assertSame(1, $this->connection->getTransactionNestingLevel());
        $this->connection->rollBack();
    }

    public function testFinalizeStoresResult(): void
    {
        $id = $this->createJob('test:lines', [], Job::STATUS_RUNNING, 'out');

        $this->writer->finalize($id, Job::STATUS_COMPLETED, new DateTimeImmutable(), new DateInterval('PT5S'), '1, 2', "\nend");

        $row = $this->fetchJob($id);
        self::assertSame(Job::STATUS_COMPLETED, $row['status']);
        self::assertSame('1, 2', $row['output_params']);
        self::assertSame("out\nend", $row['output']);
        self::assertNotNull($row['closed_at']);
        self::assertNotNull($row['runtime']);

        $job = $this->entityManager->find(Job::class, $id);
        self::assertSame(5, $job->getRuntime()->s);
    }

    public function testFinalizeKeepsCancelledStatus(): void
    {
        $id = $this->createJob('test:lines', [], Job::STATUS_CANCELLED);

        $this->writer->finalize($id, Job::STATUS_COMPLETED, new DateTimeImmutable(), null);

        self::assertSame(Job::STATUS_CANCELLED, $this->fetchJob($id)['status']);
        self::assertNotNull($this->fetchJob($id)['closed_at']);
    }

    public function testConnectionLostResetsStaleTransaction(): void
    {
        $id = $this->createJob('test:lines');
        $this->faults->failWhen(static fn(string $sql) => str_contains($sql, 'SET output'), FaultInjector::CONNECTION_LOST_CODE, true);

        try {
            $this->writer->append($id, 'x');
            self::fail('ConnectionLost expected');
        } catch (ConnectionLost) {
        }
        $this->faults->disable();

        self::assertFalse($this->writer->hasStaleNativeTransaction());
        $this->assertConnectionUsable();
        $this->writer->append($id, 'y');
        self::assertSame('y', $this->fetchJob($id)['output']);
    }

    public function testStaleNativeTransactionBreaksDbalWithoutReset(): void
    {
        // Sanity check of the emulation used above: this is exactly the TT-SERVER-CQ failure
        $this->connection->fetchOne('SELECT 1');
        $this->connection->getNativeConnection()->beginTransaction();

        self::assertTrue($this->writer->hasStaleNativeTransaction());
        try {
            $this->connection->beginTransaction();
            self::fail('Expected "There is already an active transaction"');
        } catch (\Throwable $e) {
            self::assertStringContainsString('already an active transaction', $e->getMessage());
        }

        $this->writer->resetConnection();
        self::assertFalse($this->writer->hasStaleNativeTransaction());
        $this->assertConnectionUsable();
    }

    public function testResetConnectionRollsBackDbalTransaction(): void
    {
        $this->connection->beginTransaction();
        $this->connection->beginTransaction();

        $this->writer->resetConnection();

        self::assertFalse($this->connection->isTransactionActive());
        $this->assertConnectionUsable();
    }

    public function testResetConnectionKeepsCallerTransaction(): void
    {
        $this->connection->beginTransaction();
        $this->connection->beginTransaction();

        $this->writer->resetConnection(false, 1);

        self::assertSame(1, $this->connection->getTransactionNestingLevel());
        $this->connection->rollBack();
    }
}

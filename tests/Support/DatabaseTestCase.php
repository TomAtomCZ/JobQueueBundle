<?php

namespace TomAtom\JobQueueBundle\Tests\Support;

use DateTimeImmutable;
use Doctrine\DBAL\Configuration as DBALConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware as LoggingMiddleware;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use TomAtom\JobQueueBundle\Entity\Job;
use TomAtom\JobQueueBundle\Entity\JobRecurring;

/**
 * Real DBAL/ORM on a temporary SQLite file database with the bundle's XML mapping.
 *
 * A file (not :memory:) is used, because the handler may close and reopen the connection and because
 * subprocesses need to reach the same database.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected string $databaseFile;
    protected Connection $connection;
    protected EntityManagerInterface $entityManager;
    protected QueryRecorder $queries;
    protected FaultInjector $faults;

    protected function setUp(): void
    {
        $this->databaseFile = tempnam(sys_get_temp_dir(), 'jqb_test_') . '.sqlite';
        $this->queries = new QueryRecorder();
        $this->faults = new FaultInjector();

        $dbalConfig = new DBALConfiguration();
        $dbalConfig->setMiddlewares([
            new FaultInjectionMiddleware($this->faults),
            new LoggingMiddleware($this->queries),
        ]);
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->databaseFile], $dbalConfig);

        $ormConfig = ORMSetup::createConfiguration(true);
        if (PHP_VERSION_ID >= 80400 && method_exists($ormConfig, 'enableNativeLazyObjects')) {
            $ormConfig->enableNativeLazyObjects(true);
        }
        $ormConfig->setMetadataDriverImpl(new SimplifiedXmlDriver(
            [dirname(__DIR__, 2) . '/config/doctrine' => 'TomAtom\JobQueueBundle\Entity'],
            '.orm.xml'
        ));
        $this->entityManager = new EntityManager($this->connection, $ormConfig);

        (new SchemaTool($this->entityManager))->createSchema([
            $this->entityManager->getClassMetadata(Job::class),
            $this->entityManager->getClassMetadata(JobRecurring::class),
        ]);
        $this->queries->reset();
    }

    protected function tearDown(): void
    {
        $this->faults->disable();
        $this->connection->close();
        @unlink($this->databaseFile);
        @unlink(substr($this->databaseFile, 0, -strlen('.sqlite')));
    }

    protected function createJob(string $command, array $params = [], string $status = Job::STATUS_PLANNED, ?string $output = null): int
    {
        $job = (new Job())
            ->setCommand($command)
            ->setCommandParams($params)
            ->setStatus($status)
            ->setStartAt(null)
            ->setOutput($output);
        if ($status !== Job::STATUS_PLANNED) {
            $job->setStartedAt(new DateTimeImmutable('-1 minute'));
        }
        $this->entityManager->persist($job);
        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->queries->reset();

        return $job->getId();
    }

    /**
     * Raw row, bypassing the identity map.
     *
     * @return array<string, mixed>
     */
    protected function fetchJob(int $id): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM job_queue WHERE id = ?', [$id]);
        self::assertIsArray($row, sprintf('Job #%d not found', $id));

        return $row;
    }

    /**
     * The regression check of TT-SERVER-CQ: a new transaction can be opened on the connection.
     */
    protected function assertConnectionUsable(): void
    {
        self::assertFalse($this->connection->isTransactionActive(), 'DBAL transaction left open');
        $this->connection->beginTransaction();
        $this->connection->rollBack();
        self::assertSame('1', (string)$this->connection->fetchOne('SELECT 1'));
    }
}

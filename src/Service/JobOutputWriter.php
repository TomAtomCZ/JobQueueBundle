<?php

namespace TomAtom\JobQueueBundle\Service;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use PDO;
use Throwable;
use TomAtom\JobQueueBundle\Entity\Job;

/**
 * Writes the state of a running job straight through DBAL.
 *
 * Every statement runs in autocommit mode - this class never opens a transaction - so a connection dropped
 * in the middle of a write cannot leave a half-open transaction behind (see TT-SERVER-CQ: PDO kept believing
 * in a transaction DBAL had already forgotten, and the next beginTransaction() on the shared connection failed
 * with "There is already an active transaction").
 *
 * When a statement fails with a lost connection, the connection is reset before the exception is rethrown,
 * so the caller can retry and the next query reconnects.
 */
class JobOutputWriter
{
    /** MySQL client error codes of a dropped connection (server has gone away, lost connection, ...) */
    private const CONNECTION_LOST_CODES = [2006, 2013, 2055, 4031];

    private ?string $tableName = null;

    /** @var array<string, string> */
    private array $columns = [];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function getConnection(): Connection
    {
        return $this->entityManager->getConnection();
    }

    /**
     * Appends a chunk to the job output.
     *
     * @param int $maxTotalBytes Hard cap of the whole column checked by the database, 0 = unlimited.
     *                           A chunk which would exceed it is not written at all.
     */
    public function append(int $jobId, string $chunk, int $maxTotalBytes = 0): void
    {
        if ($chunk === '') {
            return;
        }

        $output = $this->column('output');
        $concat = $this->getConnection()->getDatabasePlatform()->getConcatExpression(
            sprintf("COALESCE(%s, '')", $output),
            ':chunk'
        );

        $params = ['chunk' => $chunk, 'id' => $jobId];
        $types = ['chunk' => ParameterType::STRING, 'id' => ParameterType::INTEGER];
        if ($maxTotalBytes > 0) {
            $value = sprintf(
                'CASE WHEN COALESCE(LENGTH(%1$s), 0) + :len <= :cap THEN %2$s ELSE %1$s END',
                $output,
                $concat
            );
            $params += ['len' => strlen($chunk), 'cap' => $maxTotalBytes];
            $types += ['len' => ParameterType::INTEGER, 'cap' => ParameterType::INTEGER];
        } else {
            $value = $concat;
        }

        $this->execute(
            sprintf('UPDATE %s SET %s = %s WHERE %s = :id', $this->table(), $output, $value, $this->column('id')),
            $params,
            $types
        );
    }

    /**
     * Current status straight from the database (cheap - does not load the output column).
     */
    public function readStatus(int $jobId): ?string
    {
        try {
            $status = $this->getConnection()->fetchOne(
                sprintf('SELECT %s FROM %s WHERE %s = ?', $this->column('status'), $this->table(), $this->column('id')),
                [$jobId],
                [ParameterType::INTEGER]
            );
        } catch (Throwable $e) {
            $this->handleFailure($e);
            throw $e;
        }

        return $status === false || $status === null ? null : (string)$status;
    }

    /**
     * Length of the stored output in bytes (characters on SQLite).
     */
    public function readOutputLength(int $jobId): int
    {
        try {
            $length = $this->getConnection()->fetchOne(
                sprintf('SELECT COALESCE(LENGTH(%s), 0) FROM %s WHERE %s = ?', $this->column('output'), $this->table(), $this->column('id')),
                [$jobId],
                [ParameterType::INTEGER]
            );
        } catch (Throwable $e) {
            $this->handleFailure($e);
            throw $e;
        }

        return (int)$length;
    }

    /**
     * Atomically claims the job: switches it to RUNNING only if it is still in one of the given statuses.
     *
     * @param list<string> $fromStatuses
     * @return bool true when this worker owns the job now
     */
    public function markRunning(int $jobId, DateTimeImmutable $startedAt, array $fromStatuses = [Job::STATUS_PLANNED]): bool
    {
        $placeholders = [];
        $params = ['running' => Job::STATUS_RUNNING, 'startedAt' => $startedAt, 'id' => $jobId];
        $types = ['running' => ParameterType::STRING, 'startedAt' => Types::DATETIME_IMMUTABLE, 'id' => ParameterType::INTEGER];
        foreach (array_values($fromStatuses) as $i => $status) {
            $placeholders[] = ':from' . $i;
            $params['from' . $i] = $status;
            $types['from' . $i] = ParameterType::STRING;
        }

        $affected = $this->execute(
            sprintf(
                'UPDATE %s SET %s = :running, %s = :startedAt WHERE %s = :id AND %s IN (%s)',
                $this->table(),
                $this->column('status'),
                $this->column('startedAt'),
                $this->column('id'),
                $this->column('status'),
                implode(', ', $placeholders)
            ),
            $params,
            $types
        );

        return (int)$affected === 1;
    }

    /**
     * Stores the result of the job in one autocommit statement.
     *
     * A job cancelled in the meantime stays CANCELLED (only closedAt, runtime, output params and output are written).
     *
     * @param string|null $outputParams Written only when not null
     * @param string $appendOutput Appended to the output without any cap (status messages)
     */
    public function finalize(
        int               $jobId,
        string            $status,
        DateTimeImmutable $closedAt,
        ?DateInterval     $runtime,
        ?string           $outputParams = null,
        string            $appendOutput = ''
    ): void
    {
        $statusColumn = $this->column('status');
        $sets = [
            sprintf('%1$s = CASE WHEN %1$s = :cancelled THEN %1$s ELSE :status END', $statusColumn),
            sprintf('%s = :closedAt', $this->column('closedAt')),
            sprintf('%s = :runtime', $this->column('runtime')),
        ];
        $params = [
            'cancelled' => Job::STATUS_CANCELLED,
            'status' => $status,
            'closedAt' => $closedAt,
            'runtime' => $runtime,
            'id' => $jobId,
        ];
        $types = [
            'cancelled' => ParameterType::STRING,
            'status' => ParameterType::STRING,
            'closedAt' => Types::DATETIME_IMMUTABLE,
            'runtime' => Types::DATEINTERVAL,
            'id' => ParameterType::INTEGER,
        ];

        if ($outputParams !== null) {
            $sets[] = sprintf('%s = :outputParams', $this->column('outputParams'));
            $params['outputParams'] = $outputParams;
            $types['outputParams'] = ParameterType::STRING;
        }

        if ($appendOutput !== '') {
            $output = $this->column('output');
            $sets[] = sprintf(
                '%s = %s',
                $output,
                $this->getConnection()->getDatabasePlatform()->getConcatExpression(sprintf("COALESCE(%s, '')", $output), ':appendOutput')
            );
            $params['appendOutput'] = $appendOutput;
            $types['appendOutput'] = ParameterType::STRING;
        }

        $this->execute(
            sprintf('UPDATE %s SET %s WHERE %s = :id', $this->table(), implode(', ', $sets), $this->column('id')),
            $params,
            $types
        );
    }

    /**
     * Brings the connection back to a state in which the next beginTransaction() works.
     *
     * - rolls back transactions DBAL knows about (above the given nesting level, e.g. the level a caller owns),
     * - closes the connection when the native PDO handle believes in a transaction DBAL does not know about
     *   (the next query reconnects), or when $forceClose is set.
     *
     * @param int $keepNestingLevel Transactions up to this nesting level belong to the caller and are kept
     */
    public function resetConnection(bool $forceClose = false, int $keepNestingLevel = 0): void
    {
        $connection = $this->getConnection();

        $rollbackFailed = false;
        try {
            while ($connection->isTransactionActive() && $connection->getTransactionNestingLevel() > $keepNestingLevel) {
                $connection->rollBack();
            }
        } catch (Throwable) {
            $rollbackFailed = true;
        }

        if ($keepNestingLevel > 0 && !$rollbackFailed && !$forceClose) {
            // The caller still owns a transaction - closing the connection would destroy it
            return;
        }

        if ($forceClose || $rollbackFailed || $this->hasStaleNativeTransaction($connection)) {
            try {
                // DBAL 3/4: drops the driver connection and resets the nesting level, the next query reconnects
                $connection->close();
            } catch (Throwable) {
            }
        }
    }

    /**
     * True when the driver connection is inside a transaction although DBAL does not know about it.
     */
    public function hasStaleNativeTransaction(?Connection $connection = null): bool
    {
        $connection ??= $this->getConnection();
        if ($connection->isTransactionActive()) {
            return false;
        }

        try {
            if (!$this->isConnected($connection)) {
                return false;
            }
            $native = method_exists($connection, 'getNativeConnection') ? $connection->getNativeConnection() : null;

            return $native instanceof PDO && $native->inTransaction();
        } catch (Throwable) {
            // Cannot tell - treat the handle as broken
            return true;
        }
    }

    public static function isConnectionLost(Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof ConnectionLost || $current instanceof ConnectionException) {
                return true;
            }
            if ($current instanceof DriverException && in_array((int)$current->getCode(), self::CONNECTION_LOST_CODES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resets the connection after a failed statement: closes it when the connection was lost, otherwise only
     * when it was left in an inconsistent transaction state.
     */
    public function handleFailure(Throwable $e): void
    {
        $this->resetConnection(self::isConnectionLost($e));
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $types
     */
    private function execute(string $sql, array $params, array $types): int|string
    {
        try {
            return $this->getConnection()->executeStatement($sql, $params, $types);
        } catch (Throwable $e) {
            $this->handleFailure($e);
            throw $e;
        }
    }

    private function isConnected(Connection $connection): bool
    {
        // DBAL 3 and 4 both have isConnected(); getNativeConnection() would connect otherwise
        return !method_exists($connection, 'isConnected') || $connection->isConnected();
    }

    private function table(): string
    {
        return $this->tableName ??= $this->entityManager->getClassMetadata(Job::class)->getTableName();
    }

    private function column(string $field): string
    {
        return $this->columns[$field] ??= $this->entityManager->getClassMetadata(Job::class)->getColumnName($field);
    }
}

<?php

namespace TomAtom\JobQueueBundle\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * PSR-3 logger for Doctrine\DBAL\Logging\Middleware - records statements and transaction calls (DBAL 3 and 4).
 */
final class QueryRecorder extends AbstractLogger
{
    /** @var list<array{message: string, sql: ?string}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['message' => (string)$message, 'sql' => isset($context['sql']) ? (string)$context['sql'] : null];
    }

    public function reset(): void
    {
        $this->records = [];
    }

    /**
     * @return list<string>
     */
    public function statements(?string $pattern = null): array
    {
        $sql = [];
        foreach ($this->records as $record) {
            if ($record['sql'] !== null && ($pattern === null || preg_match($pattern, $record['sql']))) {
                $sql[] = $record['sql'];
            }
        }

        return $sql;
    }

    public function count(string $messagePrefix): int
    {
        $count = 0;
        foreach ($this->records as $record) {
            if (str_starts_with($record['message'], $messagePrefix)) {
                $count++;
            }
        }

        return $count;
    }
}

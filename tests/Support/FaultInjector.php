<?php

namespace TomAtom\JobQueueBundle\Tests\Support;

use Closure;
use Doctrine\DBAL\Driver\AbstractException;
use PDO;

/**
 * Decides which prepared statements fail (see FaultInjectionMiddleware).
 */
final class FaultInjector
{
    public const CONNECTION_LOST_CODE = 2006;

    /** @var (Closure(string): bool)|null */
    private ?Closure $predicate = null;
    private int $code = 0;
    private bool $leaveStaleTransaction = false;
    public int $injected = 0;

    /**
     * @param Closure(string): bool $predicate receives the SQL of every executed statement
     * @param bool $leaveStaleTransaction emulate pdo_mysql after a dropped connection: the native handle
     *                                    believes in a transaction DBAL does not know about
     */
    public function failWhen(Closure $predicate, int $code = self::CONNECTION_LOST_CODE, bool $leaveStaleTransaction = false): void
    {
        $this->predicate = $predicate;
        $this->code = $code;
        $this->leaveStaleTransaction = $leaveStaleTransaction;
    }

    public function disable(): void
    {
        $this->predicate = null;
    }

    public function maybeFail(string $sql, mixed $native): void
    {
        if ($this->predicate === null || !($this->predicate)($sql)) {
            return;
        }

        $this->injected++;
        if ($this->leaveStaleTransaction && $native instanceof PDO && !$native->inTransaction()) {
            $native->beginTransaction();
        }

        $message = $this->code === self::CONNECTION_LOST_CODE ? 'MySQL server has gone away (injected)' : 'Injected failure';
        throw new class($message, 'HY000', $this->code) extends AbstractException {
        };
    }
}

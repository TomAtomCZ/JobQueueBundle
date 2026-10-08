<?php

namespace TomAtom\JobQueueBundle\Tests\Support;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Query;

/**
 * DBAL driver middleware which makes chosen statements fail like a dropped MySQL connection (code 2006 is converted
 * to Doctrine\DBAL\Exception\ConnectionLost, as the MySQL driver does). Signatures are compatible with DBAL 3 and 4.
 */
final class FaultInjectionMiddleware implements Middleware
{
    public function __construct(private readonly FaultInjector $injector)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        $injector = $this->injector;

        return new class($driver, $injector) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly FaultInjector $injector)
            {
                parent::__construct($driver);
            }

            public function connect(array $params): DriverConnection
            {
                $injector = $this->injector;

                return new class(parent::connect($params), $injector) extends AbstractConnectionMiddleware {
                    public function __construct(private readonly DriverConnection $inner, private readonly FaultInjector $injector)
                    {
                        parent::__construct($inner);
                    }

                    public function prepare(string $sql): Statement
                    {
                        $inner = $this->inner;
                        $injector = $this->injector;

                        return new class(parent::prepare($sql), $sql, $inner, $injector) extends AbstractStatementMiddleware {
                            public function __construct(
                                Statement                         $statement,
                                private readonly string           $sql,
                                private readonly DriverConnection $connection,
                                private readonly FaultInjector    $injector,
                            )
                            {
                                parent::__construct($statement);
                            }

                            public function execute($params = null): Result
                            {
                                $this->injector->maybeFail($this->sql, $this->connection->getNativeConnection());

                                return parent::execute($params);
                            }
                        };
                    }
                };
            }

            public function getExceptionConverter(): ExceptionConverter
            {
                $inner = parent::getExceptionConverter();

                return new class($inner) implements ExceptionConverter {
                    public function __construct(private readonly ExceptionConverter $inner)
                    {
                    }

                    public function convert(DriverExceptionInterface $exception, ?Query $query): DriverException
                    {
                        if ($exception->getCode() === FaultInjector::CONNECTION_LOST_CODE) {
                            return new ConnectionLost($exception, $query);
                        }

                        return $this->inner->convert($exception, $query);
                    }
                };
            }
        };
    }
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Concurrency;

use HangTuah\GateTrail\Concurrency\ClassifiedConcurrencyFailure;
use HangTuah\GateTrail\Concurrency\CoordinationInsertRace;
use HangTuah\GateTrail\Concurrency\CoordinationPlan;
use HangTuah\GateTrail\Concurrency\RetryOrchestrator;
use HangTuah\GateTrail\Exceptions\AuditWriteFailed;
use HangTuah\GateTrail\Exceptions\ConcurrencyRetryExhausted;
use HangTuah\GateTrail\Mutations\MutationName;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use LogicException;
use PDOException;
use ReflectionClass;
use RuntimeException;

/**
 * The retry loop itself: how many attempts it makes, what it retries, what it
 * refuses to touch, and what it keeps afterwards.
 *
 * The attempt closure is the seam. Nothing is mocked, because the orchestrator's
 * whole contract is "what happens when this closure throws that".
 */
final class RetryOrchestratorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);
    }

    public function test_success_on_the_first_attempt_makes_one_attempt(): void
    {
        $attempts = 0;

        $result = $this->orchestrator()->run(
            $this->plan(),
            $this->connection(),
            function () use (&$attempts): string {
                $attempts++;

                return 'committed';
            },
        );

        $this->assertSame('committed', $result);
        $this->assertSame(1, $attempts);
    }

    public function test_a_confirmed_race_then_success_commits_the_second_attempt(): void
    {
        $attempts = 0;

        $result = $this->orchestrator()->run(
            $this->plan(),
            $this->connection(),
            function () use (&$attempts): string {
                $attempts++;

                if ($attempts === 1) {
                    throw CoordinationInsertRace::confirmed($this->race());
                }

                return 'committed';
            },
        );

        $this->assertSame('committed', $result);
        $this->assertSame(2, $attempts);
    }

    public function test_a_classified_deadlock_then_success_retries(): void
    {
        $attempts = 0;

        $result = $this->orchestrator()->run(
            $this->plan(),
            $this->mysqlConnection(),
            function () use (&$attempts): string {
                $attempts++;

                if ($attempts === 1) {
                    throw $this->deadlock();
                }

                return 'committed';
            },
        );

        $this->assertSame('committed', $result);
        $this->assertSame(2, $attempts);
    }

    public function test_exhaustion_uses_every_permitted_attempt(): void
    {
        foreach ([1, 2, 3, 10] as $permitted) {
            $attempts = 0;

            try {
                $this->orchestrator()->run(
                    $this->plan(attempts: $permitted),
                    $this->connection(),
                    function () use (&$attempts): string {
                        $attempts++;

                        throw CoordinationInsertRace::confirmed($this->race());
                    },
                );

                $this->fail('Exhaustion should throw.');
            } catch (ConcurrencyRetryExhausted $exhausted) {
                $this->assertSame($permitted, $attempts);
                $this->assertSame($permitted, $exhausted->attempts);
                $this->assertFalse($exhausted->callerOwned);
                $this->assertSame(
                    MutationName::ROLE_ASSIGNED,
                    $exhausted->operation,
                );
                $this->assertNull($exhausted->getPrevious());
            }
        }
    }

    public function test_a_single_configured_attempt_still_throws_typed_exhaustion(): void
    {
        $attempts = 0;

        try {
            $this->orchestrator()->run(
                $this->plan(attempts: 1),
                $this->mysqlConnection(),
                function () use (&$attempts): string {
                    $attempts++;

                    throw $this->deadlock();
                },
            );

            $this->fail('Exhaustion should throw.');
        } catch (ConcurrencyRetryExhausted $exhausted) {
            // A value of 1 means "do not retry", not "hand the consumer a
            // driver exception instead of GateTrail's contract".
            $this->assertSame(1, $attempts);
            $this->assertSame(1, $exhausted->attempts);
            $this->assertSame('mysql', $exhausted->driver);
            $this->assertSame('deadlock', $exhausted->category);
            $this->assertSame('40001', $exhausted->sqlState);
            $this->assertSame(1213, $exhausted->vendorCode);
        }
    }

    public function test_the_latest_classified_failure_supplies_the_diagnostics(): void
    {
        $attempts = 0;

        try {
            $this->orchestrator()->run(
                $this->plan(attempts: 3),
                $this->mysqlConnection(),
                function () use (&$attempts): string {
                    $attempts++;

                    if ($attempts < 3) {
                        throw CoordinationInsertRace::confirmed($this->race());
                    }

                    throw $this->deadlock();
                },
            );

            $this->fail('Exhaustion should throw.');
        } catch (ConcurrencyRetryExhausted $exhausted) {
            $this->assertSame(3, $exhausted->attempts);
            // The last attempt deadlocked, so that is what is reported.
            $this->assertSame('deadlock', $exhausted->category);
            $this->assertSame('mysql', $exhausted->driver);
            $this->assertSame('40001', $exhausted->sqlState);
        }
    }

    public function test_a_caller_owned_transaction_makes_exactly_one_attempt(): void
    {
        $attempts = 0;

        try {
            $this->orchestrator()->run(
                $this->plan(attempts: 5, callerOwned: true),
                $this->connection(),
                function () use (&$attempts): string {
                    $attempts++;

                    throw CoordinationInsertRace::confirmed($this->race());
                },
            );

            $this->fail('A caller-owned failure should throw.');
        } catch (ConcurrencyRetryExhausted $exhausted) {
            $this->assertSame(1, $attempts);
            $this->assertSame(1, $exhausted->attempts);
            $this->assertTrue($exhausted->callerOwned);
            $this->assertNull($exhausted->getPrevious());
            $this->assertStringContainsString(
                'caller-owned transaction',
                $exhausted->getMessage(),
            );
        }
    }

    public function test_a_caller_owned_success_still_returns_normally(): void
    {
        $result = $this->orchestrator()->run(
            $this->plan(callerOwned: true),
            $this->connection(),
            static fn (): string => 'committed',
        );

        $this->assertSame('committed', $result);
    }

    public function test_an_unclassified_query_exception_propagates_unchanged(): void
    {
        $attempts = 0;
        $planted = $this->queryException('42S02', 1146, "Table doesn't exist");

        try {
            $this->orchestrator()->run(
                $this->plan(attempts: 3),
                $this->connection(),
                function () use (&$attempts, $planted): string {
                    $attempts++;

                    throw $planted;
                },
            );

            $this->fail('An unclassified failure should propagate.');
        } catch (QueryException $thrown) {
            $this->assertSame($planted, $thrown);
            $this->assertSame(1, $attempts, 'It must not be retried.');
            $this->assertNotNull(
                $thrown->getPrevious(),
                'Its own chain stays intact.',
            );
        }
    }

    /**
     * @return array<string, array{\Throwable}>
     */
    public static function neverRetried(): array
    {
        return [
            'audit write failure' => [
                AuditWriteFailed::forEvent(
                    'role.assigned',
                    new RuntimeException('Audit storage is unavailable.'),
                ),
            ],
            'package invariant' => [
                new LogicException('GateTrail invariant violated.'),
            ],
            'invalid argument' => [
                new InvalidArgumentException('Bad input.'),
            ],
            'arbitrary throwable' => [
                new RuntimeException('Something else entirely.'),
            ],
        ];
    }

    public function test_nothing_outside_the_two_retryable_types_is_retried(): void
    {
        foreach (self::neverRetried() as $label => [$failure]) {
            $attempts = 0;

            try {
                $this->orchestrator()->run(
                    $this->plan(attempts: 3),
                    $this->connection(),
                    function () use (&$attempts, $failure): string {
                        $attempts++;

                        throw $failure;
                    },
                );

                $this->fail("[$label] should propagate.");
            } catch (ConcurrencyRetryExhausted) {
                $this->fail("[$label] must never become exhaustion.");
            } catch (\Throwable $thrown) {
                $this->assertSame($failure, $thrown, $label);
                $this->assertSame(1, $attempts, $label);
            }
        }
    }

    public function test_excluded_transient_conditions_are_not_retried(): void
    {
        $excluded = [
            'mysql lock wait timeout' => ['HY000', 1205,
                'Lock wait timeout exceeded; try restarting transaction'],
            'sqlite busy' => ['HY000', 5, 'database is locked'],
            'sqlite locked' => ['HY000', 6, 'database table is locked'],
            'unique violation' => ['23000', 1062,
                "Duplicate entry 'x' for key 'acl_role_name_guard_uq'"],
        ];

        foreach ($excluded as $label => [$sqlState, $code, $text]) {
            $attempts = 0;
            $planted = $this->queryException($sqlState, $code, $text);

            try {
                $this->orchestrator()->run(
                    $this->plan(attempts: 3),
                    $this->mysqlConnection(),
                    function () use (&$attempts, $planted): string {
                        $attempts++;

                        throw $planted;
                    },
                );

                $this->fail("[$label] should propagate.");
            } catch (QueryException $thrown) {
                $this->assertSame($planted, $thrown, $label);
                $this->assertSame(1, $attempts, $label);
            }
        }
    }

    public function test_the_marker_never_escapes_the_orchestrator(): void
    {
        try {
            $this->orchestrator()->run(
                $this->plan(attempts: 2),
                $this->connection(),
                function (): string {
                    throw CoordinationInsertRace::confirmed($this->race());
                },
            );

            $this->fail('Exhaustion should throw.');
        } catch (CoordinationInsertRace) {
            $this->fail('The internal marker must never escape.');
        } catch (ConcurrencyRetryExhausted $exhausted) {
            $this->assertSame('coordination_race', $exhausted->category);
            $this->assertNull($exhausted->getPrevious());
        }
    }

    public function test_the_terminal_exception_carries_only_safe_scalars(): void
    {
        try {
            $this->orchestrator()->run(
                $this->plan(attempts: 1),
                $this->mysqlConnection(),
                function (): string {
                    throw $this->queryException(
                        '40001',
                        1213,
                        'Deadlock found while updating acl_assignment_locks '
                            ."for subject 'administrator' with token hunter2",
                    );
                },
            );

            $this->fail('Exhaustion should throw.');
        } catch (ConcurrencyRetryExhausted $exhausted) {
            $graph = json_encode(
                [
                    'message' => $exhausted->getMessage(),
                    'previous' => $exhausted->getPrevious(),
                    'operation' => $exhausted->operation,
                    'attempts' => $exhausted->attempts,
                    'callerOwned' => $exhausted->callerOwned,
                    'driver' => $exhausted->driver,
                    'category' => $exhausted->category,
                    'sqlState' => $exhausted->sqlState,
                    'vendorCode' => $exhausted->vendorCode,
                ],
                JSON_THROW_ON_ERROR,
            );

            foreach ([
                'Deadlock found',
                'acl_assignment_locks',
                'administrator',
                'hunter2',
                'insert into',
                'select',
            ] as $leak) {
                $this->assertStringNotContainsString($leak, $graph);
            }
        }
    }

    public function test_the_orchestrator_keeps_no_state_between_calls(): void
    {
        $orchestrator = $this->orchestrator();
        $properties = [];

        foreach (
            (new ReflectionClass($orchestrator))->getProperties() as $property
        ) {
            $properties[$property->getName()] = $property->getValue(
                $orchestrator,
            );
        }

        $this->assertSame(
            ['transaction', 'classifier'],
            array_keys($properties),
            'No counter, closure or failure may live on the orchestrator.',
        );

        try {
            $orchestrator->run(
                $this->plan(attempts: 2),
                $this->connection(),
                function (): string {
                    throw CoordinationInsertRace::confirmed($this->race());
                },
            );
        } catch (ConcurrencyRetryExhausted) {
            // The next call must start from a clean count.
        }

        $attempts = 0;
        $orchestrator->run(
            $this->plan(attempts: 2),
            $this->connection(),
            function () use (&$attempts): string {
                $attempts++;

                return 'committed';
            },
        );

        $this->assertSame(1, $attempts);
    }

    public function test_no_backoff_or_sleep_is_introduced(): void
    {
        $source = (string) file_get_contents(
            __DIR__.'/../../src/Concurrency/RetryOrchestrator.php',
        );

        foreach (['sleep(', 'usleep(', 'time_nanosleep', 'Sleep::'] as $delay) {
            $this->assertStringNotContainsString($delay, $source);
        }

        $started = microtime(true);

        try {
            $this->orchestrator()->run(
                $this->plan(attempts: 10),
                $this->connection(),
                function (): string {
                    throw CoordinationInsertRace::confirmed($this->race());
                },
            );
        } catch (ConcurrencyRetryExhausted) {
            // Ten immediate attempts, so this cannot take a human moment.
        }

        $this->assertLessThan(2.0, microtime(true) - $started);
    }

    public function test_the_plan_rejects_a_non_coordinated_mutation(): void
    {
        foreach ([
            MutationName::ROLE_CREATED,
            'role.renamed',
            '',
            ' role.assigned',
        ] as $name) {
            try {
                new CoordinationPlan($name, 3, false);

                $this->fail("[$name] should be rejected.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString(
                    'assignment mutation',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_the_plan_caps_a_caller_owned_run_at_one_attempt(): void
    {
        $owned = new CoordinationPlan(MutationName::ROLE_ASSIGNED, 7, false);
        $callerOwned = new CoordinationPlan(
            MutationName::ROLE_ASSIGNED,
            7,
            true,
        );

        $this->assertSame(7, $owned->permittedAttempts());
        $this->assertSame(1, $callerOwned->permittedAttempts());
    }

    private function orchestrator(): RetryOrchestrator
    {
        return $this->app->make(RetryOrchestrator::class);
    }

    private function plan(
        int $attempts = 3,
        bool $callerOwned = false,
    ): CoordinationPlan {
        return new CoordinationPlan(
            MutationName::ROLE_ASSIGNED,
            $attempts,
            $callerOwned,
        );
    }

    private function race(): ClassifiedConcurrencyFailure
    {
        return ClassifiedConcurrencyFailure::classified(
            'sqlite',
            'coordination_race',
            '23000',
            19,
        );
    }

    private function deadlock(): QueryException
    {
        return $this->queryException(
            '40001',
            1213,
            'Deadlock found when trying to get lock; try restarting transaction',
        );
    }

    private function queryException(
        string $sqlState,
        int $code,
        string $text,
    ): QueryException {
        $previous = new PDOException($text);
        $previous->errorInfo = [$sqlState, $code, $text];

        return new QueryException(
            'testing',
            'insert into "acl_assignment_locks" values (?, ?)',
            ['role', 'a1b2c3'],
            $previous,
        );
    }

    private function connection(): Connection
    {
        $connection = $this->app['db']->connection('testing');

        $this->assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    /**
     * A connection that reports the MySQL driver without ever opening one.
     *
     * Deadlock and serialization retry cannot be exercised on SQLite, and that
     * is the accepted design rather than a gap in the test: ADR 0011 gives
     * SQLite no retryable deadlock or serialization shape, so the classifier is
     * right to reject a `40001`/`1213` triple arriving on a SQLite connection.
     * Only the coordination race is retryable there, and the tests above use
     * the real marker for it.
     *
     * The orchestrator reads exactly two things from the connection it is
     * given — the driver name it must classify against, and the transaction
     * level it must check before retrying. Neither touches the database, and
     * Laravel builds the connection object with a lazy PDO closure, so this
     * never connects to a MySQL server. The attempt itself still runs on the
     * real SQLite connection through `StrictMutationTransaction`.
     */
    private function mysqlConnection(): Connection
    {
        config()->set('database.connections.probe_mysql', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'gatetrail_probe',
            'username' => 'gatetrail',
            'password' => 'gatetrail',
            'prefix' => '',
        ]);

        $connection = $this->app['db']->connection('probe_mysql');

        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertSame(0, $connection->transactionLevel());

        return $connection;
    }
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Concurrency;

use Carbon\CarbonImmutable;
use HangTuah\GateTrail\Assignments\AssignmentLockStore;
use HangTuah\GateTrail\Assignments\AssignmentScope;
use HangTuah\GateTrail\Concurrency\CoordinationInsertRace;
use HangTuah\GateTrail\Concurrency\CoordinationPlan;
use HangTuah\GateTrail\Concurrency\RetryOrchestrator;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Exceptions\ConcurrencyRetryExhausted;
use HangTuah\GateTrail\Exceptions\UnsupportedDatabaseDriver;
use HangTuah\GateTrail\Mutations\MutationName;
use HangTuah\GateTrail\Tests\Fixtures\CoordinationRacePlanter;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\SQLiteConnector;
use Illuminate\Database\QueryException;
use Illuminate\Database\SQLiteConnection;
use PDOException;
use ReflectionProperty;

/**
 * An unsupported driver keeps the package's existing typed contract.
 *
 * Classification cannot answer for a driver GateTrail has never verified, and
 * "cannot answer" must not quietly become "not retryable". Both classification
 * paths therefore let {@see UnsupportedDatabaseDriver} propagate: the
 * coordination-insert path in `AssignmentLockStore`, and the transient path in
 * `RetryOrchestrator`.
 *
 * The seam is honest rather than mocked. Laravel resolves a connection's driver
 * name from its configuration, so the connection below runs real SQLite while
 * reporting a driver GateTrail does not support. Everything else — the real
 * lock store, the real classifiers, the real retry loop — is production code.
 */
final class UnsupportedDriverContractTest extends TestCase
{
    private const DRIVER = 'gatetrail_unsupported';

    private const LOCK_TABLE = 'acl_assignment_locks';

    private string $database = '';

    protected function defineEnvironment($app): void
    {
        // GateTrail's own migrations refuse an unsupported driver, so the
        // schema is created through an ordinary SQLite connection and a second
        // connection over the same file reports the unsupported driver name.
        // Laravel resolves a connection's driver name from its configuration,
        // so this runs real SQLite while calling itself something GateTrail has
        // never verified.
        $this->database = (string) tempnam(sys_get_temp_dir(), 'gatetrail-');

        $app->bind(
            'db.connector.'.self::DRIVER,
            static fn (): SQLiteConnector => new SQLiteConnector,
        );
        Connection::resolverFor(
            self::DRIVER,
            static fn ($pdo, $database, $prefix, $config): SQLiteConnection => new SQLiteConnection(
                $pdo,
                $database,
                $prefix,
                $config,
            ),
        );

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => $this->database,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('database.connections.unsupported', [
            'driver' => self::DRIVER,
            'database' => $this->database,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Laravel offers no way to unregister a connection resolver, and
        // leaking one across the suite is the kind of global state that makes
        // an unrelated test fail later for no visible reason.
        $resolvers = new ReflectionProperty(Connection::class, 'resolvers');
        /** @var array<string, mixed> $registered */
        $registered = $resolvers->getValue();
        unset($registered[self::DRIVER]);
        $resolvers->setValue(null, $registered);

        if ($this->database !== '' && is_file($this->database)) {
            @unlink($this->database);
        }
    }

    public function test_the_environment_really_reports_an_unsupported_driver(): void
    {
        // Without this the rest of the file would prove nothing.
        $this->assertSame(self::DRIVER, $this->connection()->getDriverName());
        $this->assertSame(
            'sqlite',
            $this->app['db']->connection('testing')->getDriverName(),
        );
        $this->assertSame(
            0,
            $this->connection()->table(self::LOCK_TABLE)->count(),
            'Both connections must see the same migrated schema.',
        );
    }

    public function test_the_coordination_insert_path_propagates_the_driver_contract(): void
    {
        // GateTrail's migrations run on its configured connection and refuse
        // an unsupported driver, so the schema is built first and the package
        // is pointed at the unsupported connection afterwards. SchemaContext
        // snapshots configuration when it resolves, so it is rebuilt too.
        config()->set('gatetrail.database.connection', 'unsupported');
        $this->app->forgetInstance(SchemaContext::class);
        $this->app->forgetInstance(AssignmentLockStore::class);

        $scope = AssignmentScope::forRole('user', 42, 7);
        (new CoordinationRacePlanter(
            $this->connection(),
            self::LOCK_TABLE,
            [
                'assignment_kind' => $scope->kind()->value,
                'scope_hash' => $scope->hash(),
                'scope_key' => $scope->key(),
                'created_at' => '2026-08-12 11:59:00',
                'updated_at' => '2026-08-12 11:59:00',
            ],
        ))->attach();

        $store = app(AssignmentLockStore::class);

        try {
            $this->connection()->transaction(
                static fn () => $store->acquire(
                    $scope,
                    CarbonImmutable::parse('2026-08-12 12:00:00'),
                ),
            );

            $this->fail('The unsupported driver should be reported.');
        } catch (CoordinationInsertRace) {
            $this->fail('An unverified driver must not confirm a race.');
        } catch (QueryException) {
            $this->fail('The driver contract must not be downgraded.');
        } catch (UnsupportedDatabaseDriver $exception) {
            $this->assertStringContainsString(
                'does not support',
                $exception->getMessage(),
            );
            $this->assertStringContainsString(
                self::DRIVER,
                $exception->getMessage(),
            );
        }
    }

    public function test_the_transient_path_propagates_the_driver_contract(): void
    {
        $attempts = 0;

        try {
            app(RetryOrchestrator::class)->run(
                new CoordinationPlan(MutationName::ROLE_ASSIGNED, 3, false),
                $this->connection(),
                function () use (&$attempts): string {
                    $attempts++;

                    throw $this->deadlock();
                },
            );

            $this->fail('The unsupported driver should be reported.');
        } catch (ConcurrencyRetryExhausted) {
            $this->fail('An unverified driver must not exhaust retry.');
        } catch (QueryException) {
            $this->fail('The driver contract must not be downgraded.');
        } catch (UnsupportedDatabaseDriver $exception) {
            $this->assertStringContainsString(
                self::DRIVER,
                $exception->getMessage(),
            );
            // Zero retry: the very first classification attempt refuses.
            $this->assertSame(1, $attempts);
        }
    }

    public function test_no_retry_occurs_for_an_unsupported_driver(): void
    {
        foreach ([1, 3, 10] as $permitted) {
            $attempts = 0;

            try {
                app(RetryOrchestrator::class)->run(
                    new CoordinationPlan(
                        MutationName::ROLE_ASSIGNED,
                        $permitted,
                        false,
                    ),
                    $this->connection(),
                    function () use (&$attempts): string {
                        $attempts++;

                        throw $this->deadlock();
                    },
                );

                $this->fail('The unsupported driver should be reported.');
            } catch (UnsupportedDatabaseDriver) {
                $this->assertSame(
                    1,
                    $attempts,
                    sprintf(
                        'A ceiling of %d must still make one attempt.',
                        $permitted,
                    ),
                );
            }
        }
    }

    public function test_a_caller_owned_transaction_reports_it_the_same_way(): void
    {
        $this->expectException(UnsupportedDatabaseDriver::class);

        app(RetryOrchestrator::class)->run(
            new CoordinationPlan(MutationName::ROLE_ASSIGNED, 3, true),
            $this->connection(),
            fn (): string => throw $this->deadlock(),
        );
    }

    public function test_a_successful_attempt_never_consults_the_driver(): void
    {
        // Classification only happens on failure, so an unsupported driver
        // cannot break a mutation that simply works.
        $result = app(RetryOrchestrator::class)->run(
            new CoordinationPlan(MutationName::ROLE_ASSIGNED, 3, false),
            $this->connection(),
            static fn (): string => 'committed',
        );

        $this->assertSame('committed', $result);
    }

    private function deadlock(): QueryException
    {
        $previous = new PDOException(
            'Deadlock found when trying to get lock; try restarting transaction',
        );
        $previous->errorInfo = [
            '40001',
            1213,
            'Deadlock found when trying to get lock; try restarting transaction',
        ];

        return new QueryException(
            'testing',
            'insert into "acl_assignment_locks" values (?, ?)',
            ['role', 'a1b2c3'],
            $previous,
        );
    }

    private function connection(): Connection
    {
        $connection = $this->app['db']->connection('unsupported');

        $this->assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}

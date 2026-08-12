<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use Carbon\CarbonImmutable;
use HangTuah\GateTrail\Assignments\AssignmentLockStore;
use HangTuah\GateTrail\Assignments\AssignmentScope;
use HangTuah\GateTrail\Audit\AccessAuditWriter;
use HangTuah\GateTrail\Audit\AuditFailureReporter;
use HangTuah\GateTrail\Audit\PendingAuditRecord;
use HangTuah\GateTrail\Concurrency\ClassifiedConcurrencyFailure;
use HangTuah\GateTrail\Concurrency\CoordinationInsertRace;
use HangTuah\GateTrail\Concurrency\CoordinationPlan;
use HangTuah\GateTrail\Concurrency\RetryOrchestrator;
use HangTuah\GateTrail\Concurrency\RetryPolicy;
use HangTuah\GateTrail\Events\MutationCommitted;
use HangTuah\GateTrail\Exceptions\AssignmentNotActive;
use HangTuah\GateTrail\Exceptions\AssignmentScopeCollision;
use HangTuah\GateTrail\Exceptions\AuditWriteFailed;
use HangTuah\GateTrail\Exceptions\ConcurrencyRetryExhausted;
use HangTuah\GateTrail\Exceptions\DuplicateActiveAssignment;
use HangTuah\GateTrail\Exceptions\GuardMismatch;
use HangTuah\GateTrail\Exceptions\InvalidActor;
use HangTuah\GateTrail\Exceptions\InvalidAuditMetadata;
use HangTuah\GateTrail\Exceptions\PermissionNotFound;
use HangTuah\GateTrail\Exceptions\ReasonRequired;
use HangTuah\GateTrail\Exceptions\RoleNotFound;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\Mutations\ActorIdentity;
use HangTuah\GateTrail\Mutations\AuditedMutationTransaction;
use HangTuah\GateTrail\Mutations\MutationName;
use HangTuah\GateTrail\Mutations\PreparedMutation;
use HangTuah\GateTrail\Mutations\StrictMutationTransaction;
use HangTuah\GateTrail\Support\ModelRegistry;
use HangTuah\GateTrail\Tests\Fixtures\CoordinationRacePlanter;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * ADR 0011's acceptance matrix: what is retried, what is emphatically not, and
 * what survives an eventual success.
 */
final class RetryAcceptanceTest extends TestCase
{
    private const LOCK_TABLE = 'acl_assignment_locks';

    /**
     * @var list<MutationCommitted>
     */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);

        $this->events = [];
        Event::listen(
            MutationCommitted::class,
            function (MutationCommitted $event): void {
                $this->events[] = $event;
            },
        );
    }

    // ------------------------------------------------- retried, then commits

    public function test_a_serialization_failure_is_retried_and_then_succeeds(): void
    {
        $attempts = 0;

        $result = app(RetryOrchestrator::class)->run(
            new CoordinationPlan(MutationName::ROLE_ASSIGNED, 3, false),
            $this->postgresConnection(),
            function () use (&$attempts): string {
                $attempts++;

                if ($attempts === 1) {
                    throw $this->driverFailure(
                        '40001',
                        7,
                        'could not serialize access due to concurrent update',
                    );
                }

                return 'committed';
            },
        );

        $this->assertSame('committed', $result);
        $this->assertSame(2, $attempts);
    }

    public function test_a_deadlock_is_retried_and_then_succeeds(): void
    {
        $attempts = 0;

        $result = app(RetryOrchestrator::class)->run(
            new CoordinationPlan(MutationName::ROLE_ASSIGNED, 3, false),
            $this->postgresConnection(),
            function () use (&$attempts): string {
                $attempts++;

                if ($attempts === 1) {
                    throw $this->driverFailure(
                        '40P01',
                        7,
                        'deadlock detected',
                    );
                }

                return 'committed';
            },
        );

        $this->assertSame('committed', $result);
        $this->assertSame(2, $attempts);
    }

    public function test_the_coordination_race_obeys_the_same_bounded_policy(): void
    {
        // The marker and a classified driver failure must be interchangeable
        // as far as the ceiling is concerned.
        foreach ([1, 2, 5, 10] as $permitted) {
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

                        throw CoordinationInsertRace::confirmed(
                            ClassifiedConcurrencyFailure::classified(
                                'sqlite',
                                'coordination_race',
                                '23000',
                                19,
                            ),
                        );
                    },
                );

                $this->fail('Exhaustion should throw.');
            } catch (ConcurrencyRetryExhausted $exhausted) {
                $this->assertSame($permitted, $attempts);
                $this->assertSame($permitted, $exhausted->attempts);
            }
        }
    }

    public function test_each_retry_opens_a_fresh_transaction(): void
    {
        $begins = 0;
        $commits = 0;
        $rollbacks = 0;

        Event::listen(
            TransactionBeginning::class,
            static function () use (&$begins): void {
                $begins++;
            },
        );
        Event::listen(
            TransactionCommitted::class,
            static function () use (&$commits): void {
                $commits++;
            },
        );
        Event::listen(
            TransactionRolledBack::class,
            static function () use (&$rollbacks): void {
                $rollbacks++;
            },
        );

        $scope = $this->scope();
        $this->plantRace($scope, times: 2);
        $attempts = 0;
        $levels = [];

        $this->orchestrator()->runCoordinated(
            MutationName::ROLE_ASSIGNED,
            function () use ($scope, &$attempts, &$levels): PreparedMutation {
                $attempts++;
                $levels[] = $this->connection()->transactionLevel();
                $this->acquire($scope);

                return $this->created();
            },
        );

        $this->assertSame(3, $attempts);
        // Three separate transactions: two discarded, one committed.
        $this->assertSame(3, $begins);
        $this->assertSame(2, $rollbacks);
        $this->assertSame(1, $commits);
        // Every attempt ran at the outermost level, never nested in the last.
        $this->assertSame([1, 1, 1], $levels);
    }

    public function test_state_is_re_resolved_on_every_attempt(): void
    {
        $scope = $this->scope();
        $this->plantRace($scope);
        $seen = [];

        $this->orchestrator()->runCoordinated(
            MutationName::ROLE_ASSIGNED,
            function () use ($scope, &$seen): PreparedMutation {
                // A transaction-bound read, repeated per attempt.
                $seen[] = (int) $this->connection()
                    ->table(self::LOCK_TABLE)
                    ->count();
                $this->acquire($scope);

                return $this->created();
            },
        );

        // Attempt 1 saw an empty lock table; attempt 2 saw it empty again
        // because the discarded attempt rolled its coordination row back.
        // Either way the closure re-read rather than reusing a cached answer.
        $this->assertCount(2, $seen);
        $this->assertSame([0, 0], $seen);
    }

    public function test_a_failed_attempt_emits_no_audit_row_and_no_event(): void
    {
        $scope = $this->scope();
        $this->plantRace($scope);
        $auditsPerAttempt = [];

        $result = $this->orchestrator()->runCoordinated(
            MutationName::ROLE_ASSIGNED,
            function () use ($scope, &$auditsPerAttempt): PreparedMutation {
                $auditsPerAttempt[] = (int) $this->connection()
                    ->table('acl_access_audits')
                    ->count();
                $this->acquire($scope);

                return $this->created();
            },
        );

        $this->assertSame([0, 0], $auditsPerAttempt);
        $this->assertSame(MutationOutcome::Created, $result->outcome);
        // Exactly one of each after eventual success.
        $this->assertDatabaseCount('acl_access_audits', 1);
        $this->assertCount(1, $this->events);
        $this->assertSame(
            MutationName::ROLE_ASSIGNED,
            $this->events[0]->mutation,
        );
        $this->assertSame($result->auditId, $this->events[0]->auditId);
    }

    public function test_the_ceiling_supports_the_configured_maximum_of_ten(): void
    {
        config()->set(RetryPolicy::KEY, 10);
        $scope = $this->scope();
        $planter = $this->plantRace($scope, times: 9);
        $attempts = 0;

        $result = $this->orchestrator()->runCoordinated(
            MutationName::ROLE_ASSIGNED,
            function () use ($scope, &$attempts): PreparedMutation {
                $attempts++;
                $this->acquire($scope);

                return $this->created();
            },
        );

        // Nine losses then a win, inside a ceiling of ten.
        $this->assertSame(9, $planter->planted());
        $this->assertSame(10, $attempts);
        $this->assertSame(MutationOutcome::Created, $result->outcome);
        $this->assertDatabaseCount('acl_access_audits', 1);
        $this->assertCount(1, $this->events);
    }

    public function test_exhaustion_at_the_maximum_reports_safe_diagnostics(): void
    {
        config()->set(RetryPolicy::KEY, 10);
        $scope = $this->scope();
        $planter = $this->plantRace($scope, times: 20);

        try {
            $this->orchestrator()->runCoordinated(
                MutationName::ROLE_ASSIGNED,
                function () use ($scope): PreparedMutation {
                    $this->acquire($scope);

                    return $this->created();
                },
            );

            $this->fail('Exhaustion should throw.');
        } catch (ConcurrencyRetryExhausted $exhausted) {
            $this->assertSame(10, $planter->planted());
            $this->assertSame(10, $exhausted->attempts);
            $this->assertFalse($exhausted->callerOwned);
            $this->assertSame('coordination_race', $exhausted->category);
            $this->assertSame('sqlite', $exhausted->driver);
            $this->assertSame('23000', $exhausted->sqlState);
            $this->assertSame(19, $exhausted->vendorCode);
            $this->assertNull($exhausted->getPrevious());
            $this->assertDatabaseCount('acl_access_audits', 0);
            $this->assertSame([], $this->events);
        }
    }

    public function test_a_caller_owned_transaction_never_retries(): void
    {
        config()->set(RetryPolicy::KEY, 10);
        $scope = $this->scope();
        $this->plantRace($scope, times: 20);
        $attempts = 0;
        $orchestrator = $this->orchestrator();

        try {
            $this->connection()->transaction(
                function () use ($orchestrator, $scope, &$attempts): void {
                    $orchestrator->runCoordinated(
                        MutationName::ROLE_ASSIGNED,
                        function () use ($scope, &$attempts): PreparedMutation {
                            $attempts++;
                            $this->acquire($scope);

                            return $this->created();
                        },
                    );
                },
            );

            $this->fail('A caller-owned race should fail closed.');
        } catch (ConcurrencyRetryExhausted $exhausted) {
            $this->assertSame(1, $attempts);
            $this->assertSame(1, $exhausted->attempts);
            $this->assertTrue($exhausted->callerOwned);
        }
    }

    // ------------------------------------------------------- never retried

    /**
     * @return array<string, array{Throwable}>
     */
    public static function domainFailures(): array
    {
        $scope = AssignmentScope::forRole('user', 42, 7);

        return [
            'DuplicateActiveAssignment' => [
                DuplicateActiveAssignment::forScope($scope),
            ],
            'AssignmentNotActive' => [AssignmentNotActive::forRole(7)],
            'AssignmentScopeCollision' => [
                AssignmentScopeCollision::forScope($scope),
            ],
            'GuardMismatch' => [GuardMismatch::forRole('web', 'api', 7)],
            'RoleNotFound' => [RoleNotFound::forGuard('web', 'name')],
            'PermissionNotFound' => [
                PermissionNotFound::forGuard('web', 'name'),
            ],
            'ReasonRequired' => [ReasonRequired::forAssignment()],
            'InvalidActor' => [InvalidActor::emptySystemName()],
            'AuditWriteFailed' => [
                AuditWriteFailed::forEvent(
                    MutationName::ROLE_ASSIGNED,
                    new RuntimeException('Audit storage is unavailable.'),
                ),
            ],
            'InvalidAuditMetadata' => [
                InvalidAuditMetadata::tooLarge(16384),
            ],
        ];
    }

    public function test_no_typed_domain_failure_is_ever_retried(): void
    {
        config()->set(RetryPolicy::KEY, 10);

        foreach (self::domainFailures() as $label => [$failure]) {
            $attempts = 0;

            try {
                $this->orchestrator()->runCoordinated(
                    MutationName::ROLE_ASSIGNED,
                    function () use (&$attempts, $failure): PreparedMutation {
                        $attempts++;

                        throw $failure;
                    },
                );

                $this->fail("[$label] should propagate.");
            } catch (ConcurrencyRetryExhausted) {
                $this->fail("[$label] must never become exhaustion.");
            } catch (Throwable $thrown) {
                $this->assertSame($thrown, $failure, $label);
                $this->assertSame(1, $attempts, $label);
            }

            $this->assertDatabaseCount('acl_access_audits', 0);
            $this->assertSame([], $this->events, $label);
        }
    }

    public function test_an_unclassified_query_exception_propagates_unchanged(): void
    {
        config()->set(RetryPolicy::KEY, 10);
        $attempts = 0;
        $planted = $this->driverFailure(
            '42S02',
            1146,
            "Table 'acl.acl_role_assignments' doesn't exist",
        );

        try {
            $this->orchestrator()->runCoordinated(
                MutationName::ROLE_ASSIGNED,
                function () use (&$attempts, $planted): PreparedMutation {
                    $attempts++;

                    throw $planted;
                },
            );

            $this->fail('An unclassified failure should propagate.');
        } catch (QueryException $thrown) {
            $this->assertSame($planted, $thrown);
            $this->assertSame(1, $attempts);
            $this->assertNotNull($thrown->getPrevious());
        }
    }

    public function test_a_successful_first_attempt_does_not_retry(): void
    {
        $attempts = 0;

        $result = $this->orchestrator()->runCoordinated(
            MutationName::ROLE_ASSIGNED,
            function () use (&$attempts): PreparedMutation {
                $attempts++;
                $this->acquire($this->scope());

                return $this->created();
            },
        );

        $this->assertSame(1, $attempts);
        $this->assertSame(MutationOutcome::Created, $result->outcome);
        $this->assertCount(1, $this->events);
    }

    public function test_an_unchanged_outcome_does_not_retry_or_announce(): void
    {
        $attempts = 0;
        $role = $this->role();

        $result = $this->orchestrator()->runCoordinated(
            MutationName::ROLE_ASSIGNED,
            function () use (&$attempts, $role): PreparedMutation {
                $attempts++;
                $this->acquire($this->scope());

                return new PreparedMutation(
                    $role,
                    MutationOutcome::Unchanged,
                    null,
                );
            },
        );

        $this->assertSame(1, $attempts);
        $this->assertSame(MutationOutcome::Unchanged, $result->outcome);
        $this->assertNull($result->auditId);
        $this->assertDatabaseCount('acl_access_audits', 0);
        $this->assertSame([], $this->events);
    }

    // --------------------------------- validation before any orchestration

    public function test_pre_transaction_validation_never_opens_a_transaction(): void
    {
        $cases = [
            'unknown mutation name' => static fn (
                AuditedMutationTransaction $orchestrator,
                callable $operation,
            ) => $orchestrator->runCoordinated('role.renamed', $operation),
            'definition mutation name' => static fn (
                AuditedMutationTransaction $orchestrator,
                callable $operation,
            ) => $orchestrator->runCoordinated(
                MutationName::ROLE_CREATED,
                $operation,
            ),
            'empty mutation name' => static fn (
                AuditedMutationTransaction $orchestrator,
                callable $operation,
            ) => $orchestrator->runCoordinated('', $operation),
        ];

        foreach ($cases as $label => $invoke) {
            $ran = false;
            $begins = 0;
            Event::listen(
                TransactionBeginning::class,
                static function () use (&$begins): void {
                    $begins++;
                },
            );

            try {
                $invoke(
                    $this->orchestrator(),
                    function () use (&$ran): PreparedMutation {
                        $ran = true;

                        return $this->created();
                    },
                );

                $this->fail("[$label] should be rejected.");
            } catch (ConcurrencyRetryExhausted) {
                $this->fail("[$label] must not become exhaustion.");
            } catch (Throwable) {
                $this->assertFalse($ran, $label);
                $this->assertSame(0, $begins, $label);
            }
        }
    }

    // ------------------------------------------------------------ helpers

    private function orchestrator(): AuditedMutationTransaction
    {
        foreach ([
            ModelRegistry::class,
            AccessAuditWriter::class,
            AuditFailureReporter::class,
            StrictMutationTransaction::class,
            AuditedMutationTransaction::class,
            RetryPolicy::class,
        ] as $service) {
            $this->app->forgetInstance($service);
        }

        return $this->app->make(AuditedMutationTransaction::class);
    }

    private function scope(): AssignmentScope
    {
        return AssignmentScope::forRole('user', 42, 7);
    }

    private function acquire(AssignmentScope $scope): void
    {
        app(AssignmentLockStore::class)->acquire(
            $scope,
            CarbonImmutable::parse('2026-08-12 12:00:00'),
        );
    }

    private function plantRace(
        AssignmentScope $scope,
        int $times = 1,
    ): CoordinationRacePlanter {
        return (new CoordinationRacePlanter(
            $this->connection(),
            self::LOCK_TABLE,
            [
                'assignment_kind' => $scope->kind()->value,
                'scope_hash' => $scope->hash(),
                'scope_key' => $scope->key(),
                'created_at' => '2026-08-12 11:59:00',
                'updated_at' => '2026-08-12 11:59:00',
            ],
            $times,
        ))->attach();
    }

    private function role(): Role
    {
        return Role::query()->create([
            'name' => 'reviewer-'.Role::query()->count(),
            'guard_name' => 'web',
            'description' => null,
        ]);
    }

    /**
     * @return PreparedMutation<Role>
     */
    private function created(): PreparedMutation
    {
        return new PreparedMutation(
            $this->role(),
            MutationOutcome::Created,
            new PendingAuditRecord(
                event: MutationName::ROLE_ASSIGNED,
                actor: new ActorIdentity('gatetrail.system', 'retry-suite'),
                subjectType: 'user',
                subjectId: 42,
                targetType: 'role_assignment',
                targetId: 1,
                before: null,
                after: '{"id":"1"}',
                reason: 'Assign reviewer role',
                context: '{"schema_version":1,"guard_name":"web","metadata":{}}',
                ipAddress: null,
                userAgent: null,
                requestId: null,
                createdAt: CarbonImmutable::parse('2026-08-12 12:00:00')
                    ->toDateTimeImmutable(),
            ),
        );
    }

    private function driverFailure(
        string $sqlState,
        int $code,
        string $text,
    ): QueryException {
        $previous = new PDOException($text);
        $previous->errorInfo = [$sqlState, $code, $text];

        return new QueryException(
            'testing',
            'insert into "acl_role_assignments" values (?, ?)',
            ['user', 42],
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
     * A connection reporting the PostgreSQL driver without opening one.
     *
     * ADR 0011 gives SQLite no retryable deadlock or serialization shape, so
     * those two rows of the matrix can only be exercised against a driver that
     * has them. The orchestrator reads just the driver name and the transaction
     * level from the connection it is handed; the attempt itself still runs on
     * the real SQLite connection.
     */
    private function postgresConnection(): Connection
    {
        config()->set('database.connections.probe_pgsql', [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => '5432',
            'database' => 'gatetrail_probe',
            'username' => 'gatetrail',
            'password' => 'gatetrail',
            'charset' => 'utf8',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);

        $connection = $this->app['db']->connection('probe_pgsql');

        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertSame('pgsql', $connection->getDriverName());

        return $connection;
    }
}

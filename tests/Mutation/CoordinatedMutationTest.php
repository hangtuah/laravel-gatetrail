<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use Carbon\CarbonImmutable;
use HangTuah\GateTrail\Assignments\AssignmentLockStore;
use HangTuah\GateTrail\Assignments\AssignmentScope;
use HangTuah\GateTrail\Audit\AccessAuditWriter;
use HangTuah\GateTrail\Audit\AuditFailureReporter;
use HangTuah\GateTrail\Audit\PendingAuditRecord;
use HangTuah\GateTrail\Concurrency\RetryPolicy;
use HangTuah\GateTrail\Events\AuditPersistenceFailed;
use HangTuah\GateTrail\Events\MutationCommitted;
use HangTuah\GateTrail\Exceptions\ConcurrencyRetryExhausted;
use HangTuah\GateTrail\Exceptions\DuplicateActiveAssignment;
use HangTuah\GateTrail\Exceptions\InvalidConcurrencyConfiguration;
use HangTuah\GateTrail\Models\AccessAudit;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\Mutations\ActorIdentity;
use HangTuah\GateTrail\Mutations\AuditedMutationTransaction;
use HangTuah\GateTrail\Mutations\MutationName;
use HangTuah\GateTrail\Mutations\PreparedMutation;
use HangTuah\GateTrail\Mutations\StrictMutationTransaction;
use HangTuah\GateTrail\Support\ModelRegistry;
use HangTuah\GateTrail\Tests\Fixtures\CoordinationRacePlanter;
use HangTuah\GateTrail\Tests\Fixtures\ForcedRollback;
use HangTuah\GateTrail\Tests\Fixtures\RecordingLogger;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * `runCoordinated()` end to end, across all three audit modes.
 *
 * Races here are real: the coordination row is planted immediately before the
 * store's own insert, so the engine raises a genuine duplicate-key failure at
 * the exact statement ADR 0011 scopes classification to, and the production
 * classifier decides for itself.
 */
final class CoordinatedMutationTest extends TestCase
{
    private const LOCK_TABLE = 'acl_assignment_locks';

    /**
     * @var list<MutationCommitted>
     */
    private array $events = [];

    /**
     * @var list<AuditPersistenceFailed>
     */
    private array $auditFailures = [];

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);

        $this->events = [];
        $this->auditFailures = [];
        $this->logger = new RecordingLogger;
        $this->app->instance(LoggerInterface::class, $this->logger);

        Event::listen(
            MutationCommitted::class,
            function (MutationCommitted $event): void {
                $this->events[] = $event;
            },
        );
        Event::listen(
            AuditPersistenceFailed::class,
            function (AuditPersistenceFailed $event): void {
                $this->auditFailures[] = $event;
            },
        );
    }

    // ------------------------------------------- validation before any work

    public function test_a_non_coordinated_name_fails_before_any_transaction(): void
    {
        foreach ([
            'definition mutation' => MutationName::ROLE_CREATED,
            'unknown mutation' => 'role.renamed',
            'empty string' => '',
            'typo' => 'role.assigne',
            'padded' => ' role.assigned',
            'wrong case' => 'ROLE.ASSIGNED',
        ] as $label => $name) {
            $ran = false;
            $transactions = $this->countTransactions();

            try {
                $this->orchestrator()->runCoordinated(
                    $name,
                    function () use (&$ran): PreparedMutation {
                        $ran = true;

                        return $this->created();
                    },
                );

                $this->fail("[$label] should be rejected.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString(
                    'assignment mutation',
                    $exception->getMessage(),
                );
                $this->assertFalse($ran, "[$label] must not run the operation.");
                $this->assertSame(0, $transactions(), $label);
                $this->assertDatabaseCount('acl_roles', 0);
            }
        }
    }

    public function test_invalid_concurrency_configuration_fails_before_any_transaction(): void
    {
        foreach ([0, 11, '3', 3.0, true, null] as $value) {
            config()->set(RetryPolicy::KEY, $value);
            $ran = false;
            $transactions = $this->countTransactions();

            try {
                $this->orchestrator()->runCoordinated(
                    MutationName::ROLE_ASSIGNED,
                    function () use (&$ran): PreparedMutation {
                        $ran = true;

                        return $this->created();
                    },
                );

                $this->fail('Invalid configuration should be rejected.');
            } catch (InvalidConcurrencyConfiguration $exception) {
                $this->assertSame(RetryPolicy::KEY, $exception->key);
                $this->assertFalse($ran);
                $this->assertSame(0, $transactions());
                $this->assertDatabaseCount('acl_roles', 0);
            }
        }
    }

    public function test_configuration_is_validated_even_when_retry_is_unavailable(): void
    {
        // A caller-owned transaction gets one attempt whatever the count says,
        // but the count is still validated fail-closed before that attempt: a
        // misconfigured package must not run differently depending on who owns
        // the transaction.
        config()->set(RetryPolicy::KEY, 0);
        $orchestrator = $this->orchestrator();
        $ran = false;

        try {
            $this->connection()->transaction(
                function () use ($orchestrator, &$ran): void {
                    $orchestrator->runCoordinated(
                        MutationName::ROLE_ASSIGNED,
                        function () use (&$ran): PreparedMutation {
                            $ran = true;

                            return $this->created();
                        },
                    );
                },
            );

            $this->fail('Invalid configuration should be rejected.');
        } catch (InvalidConcurrencyConfiguration $exception) {
            $this->assertSame(RetryPolicy::KEY, $exception->key);
            $this->assertFalse($ran);
            $this->assertDatabaseCount('acl_roles', 0);
        }
    }

    public function test_a_non_coordinated_name_is_rejected_inside_a_caller_transaction(): void
    {
        $orchestrator = $this->orchestrator();
        $ran = false;

        try {
            $this->connection()->transaction(
                function () use ($orchestrator, &$ran): void {
                    $orchestrator->runCoordinated(
                        MutationName::ROLE_CREATED,
                        function () use (&$ran): PreparedMutation {
                            $ran = true;

                            return $this->created();
                        },
                    );
                },
            );

            $this->fail('A definition name should be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString(
                'assignment mutation',
                $exception->getMessage(),
            );
            $this->assertFalse($ran);
            $this->assertDatabaseCount('acl_roles', 0);
        }
    }

    public function test_every_coordinated_name_is_accepted(): void
    {
        foreach (MutationName::assignments() as $name) {
            Role::query()->forceDelete();

            $result = $this->orchestrator()->runCoordinated(
                $name,
                fn (): PreparedMutation => $this->created(event: $name),
            );

            $this->assertSame(MutationOutcome::Created, $result->outcome);
        }
    }

    // --------------------------------------------------------- strict mode

    public function test_strict_retry_then_success_commits_once(): void
    {
        $scope = $this->scope();
        $planter = $this->plantRace($scope);
        $attempts = 0;

        $result = $this->orchestrator()->runCoordinated(
            MutationName::ROLE_ASSIGNED,
            function () use ($scope, &$attempts): PreparedMutation {
                $attempts++;
                $this->acquire($scope);

                return $this->created(event: MutationName::ROLE_ASSIGNED);
            },
        );

        $this->assertSame(2, $attempts, 'The loser must retry exactly once.');
        $this->assertSame(1, $planter->planted());
        $this->assertSame(MutationOutcome::Created, $result->outcome);
        $this->assertNotNull($result->auditId);

        // One committed mutation, one audit row, one event. The discarded
        // attempt left nothing at all behind.
        $this->assertDatabaseCount('acl_roles', 1);
        $this->assertDatabaseCount('acl_access_audits', 1);
        $this->assertCount(1, $this->events);
        $this->assertSame(
            MutationName::ROLE_ASSIGNED,
            $this->events[0]->mutation,
        );
        $this->assertSame($result->auditId, $this->events[0]->auditId);
    }

    public function test_strict_exhaustion_leaves_no_mutation_audit_or_event(): void
    {
        config()->set(RetryPolicy::KEY, 3);
        $scope = $this->scope();
        $planter = $this->plantRace($scope, times: 10);
        $attempts = 0;

        try {
            $this->orchestrator()->runCoordinated(
                MutationName::ROLE_ASSIGNED,
                function () use ($scope, &$attempts): PreparedMutation {
                    $attempts++;
                    $this->acquire($scope);

                    return $this->created(event: MutationName::ROLE_ASSIGNED);
                },
            );

            $this->fail('Exhaustion should throw.');
        } catch (ConcurrencyRetryExhausted $exhausted) {
            $this->assertSame(3, $attempts);
            $this->assertSame(3, $exhausted->attempts);
            $this->assertSame(3, $planter->planted());
            $this->assertFalse($exhausted->callerOwned);
            $this->assertSame(
                MutationName::ROLE_ASSIGNED,
                $exhausted->operation,
            );
            $this->assertSame('coordination_race', $exhausted->category);
            $this->assertSame('sqlite', $exhausted->driver);
            $this->assertNull($exhausted->getPrevious());

            $this->assertDatabaseCount('acl_roles', 0);
            $this->assertDatabaseCount('acl_access_audits', 0);
            $this->assertDatabaseCount(self::LOCK_TABLE, 0);
            $this->assertSame([], $this->events);
            $this->assertSame([], $this->auditFailures);
            $this->assertSame([], $this->logger->records);
        }
    }

    public function test_a_discarded_attempt_leaves_no_partial_audit(): void
    {
        $scope = $this->scope();
        $this->plantRace($scope);
        $audits = [];
        Event::listen(
            TransactionBeginning::class,
            function () use (&$audits): void {
                $audits[] = AccessAudit::query()->count();
            },
        );

        $this->orchestrator()->runCoordinated(
            MutationName::ROLE_ASSIGNED,
            function () use ($scope): PreparedMutation {
                $this->acquire($scope);

                return $this->created(event: MutationName::ROLE_ASSIGNED);
            },
        );

        // Every attempt started from zero audit rows: the first attempt's
        // strict audit write rolled back with its mutation.
        $this->assertSame([0, 0], $audits);
        $this->assertDatabaseCount('acl_access_audits', 1);
    }

    // ----------------------------------------------------- non-strict mode

    public function test_non_strict_retry_then_success_writes_one_audit(): void
    {
        config()->set('gatetrail.audit.strict', false);
        $scope = $this->scope();
        $this->plantRace($scope);
        $attempts = 0;

        $result = $this->orchestrator()->runCoordinated(
            MutationName::ROLE_ASSIGNED,
            function () use ($scope, &$attempts): PreparedMutation {
                $attempts++;
                $this->acquire($scope);

                return $this->created(event: MutationName::ROLE_ASSIGNED);
            },
        );

        $this->assertSame(2, $attempts);
        $this->assertNotNull($result->auditId);
        $this->assertDatabaseCount('acl_roles', 1);
        $this->assertDatabaseCount('acl_access_audits', 1);
        $this->assertCount(1, $this->events);
        $this->assertSame([], $this->auditFailures);
    }

    public function test_a_non_strict_audit_failure_never_retries_the_mutation(): void
    {
        config()->set('gatetrail.audit.strict', false);
        $scope = $this->scope();
        $attempts = 0;
        $this->breakAuditStorage();

        $result = $this->orchestrator()->runCoordinated(
            MutationName::ROLE_ASSIGNED,
            function () use ($scope, &$attempts): PreparedMutation {
                $attempts++;
                $this->acquire($scope);

                return $this->created(event: MutationName::ROLE_ASSIGNED);
            },
        );

        // The audit write happens after the winning commit and outside the
        // loop, so its failure is reported and never replays the mutation.
        $this->assertSame(1, $attempts);
        $this->assertSame(MutationOutcome::Created, $result->outcome);
        $this->assertNull($result->auditId);
        $this->assertDatabaseCount('acl_roles', 1);
        $this->assertCount(1, $this->auditFailures);
        $this->assertCount(1, $this->events);
    }

    // -------------------------------------------------------- disabled mode

    public function test_disabled_auditing_retries_only_the_mutation(): void
    {
        config()->set('gatetrail.audit.enabled', false);
        $scope = $this->scope();
        $this->plantRace($scope);
        $attempts = 0;

        $result = $this->orchestrator()->runCoordinated(
            MutationName::ROLE_ASSIGNED,
            function () use ($scope, &$attempts): PreparedMutation {
                $attempts++;
                $this->acquire($scope);

                return $this->created(event: MutationName::ROLE_ASSIGNED);
            },
        );

        $this->assertSame(2, $attempts);
        $this->assertNull($result->auditId);
        $this->assertDatabaseCount('acl_roles', 1);
        $this->assertDatabaseCount('acl_access_audits', 0);
        // A winning changed mutation still reaches the event path exactly once.
        $this->assertCount(1, $this->events);
    }

    // --------------------------------------------------------- caller-owned

    public function test_a_caller_owned_race_makes_one_attempt_and_fails_closed(): void
    {
        config()->set(RetryPolicy::KEY, 5);
        $scope = $this->scope();
        $this->plantRace($scope, times: 10);
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

                            return $this->created(
                                event: MutationName::ROLE_ASSIGNED,
                            );
                        },
                    );
                },
            );

            $this->fail('A caller-owned race should fail closed.');
        } catch (ConcurrencyRetryExhausted $exhausted) {
            $this->assertSame(1, $attempts, 'The caller gets one attempt.');
            $this->assertSame(1, $exhausted->attempts);
            $this->assertTrue($exhausted->callerOwned);
            $this->assertNull($exhausted->getPrevious());
            $this->assertSame('coordination_race', $exhausted->category);
            $this->assertDatabaseCount('acl_roles', 0);
            $this->assertDatabaseCount('acl_access_audits', 0);
            $this->assertSame([], $this->events);
        }
    }

    public function test_a_caller_owned_success_still_defers_its_announcement(): void
    {
        config()->set('gatetrail.audit.strict', false);
        $scope = $this->scope();
        $orchestrator = $this->orchestrator();

        $this->connection()->transaction(
            function () use ($orchestrator, $scope): void {
                $result = $orchestrator->runCoordinated(
                    MutationName::ROLE_ASSIGNED,
                    function () use ($scope): PreparedMutation {
                        $this->acquire($scope);

                        return $this->created(
                            event: MutationName::ROLE_ASSIGNED,
                        );
                    },
                );

                $this->assertNull($result->auditId);
                $this->assertSame([], $this->events);
            },
        );

        $this->assertDatabaseCount('acl_roles', 1);
        $this->assertDatabaseCount('acl_access_audits', 1);
        $this->assertCount(1, $this->events);
    }

    public function test_a_caller_rollback_after_a_coordinated_success_announces_nothing(): void
    {
        $scope = $this->scope();
        $orchestrator = $this->orchestrator();

        try {
            $this->connection()->transaction(
                function () use ($orchestrator, $scope): void {
                    $orchestrator->runCoordinated(
                        MutationName::ROLE_ASSIGNED,
                        function () use ($scope): PreparedMutation {
                            $this->acquire($scope);

                            return $this->created(
                                event: MutationName::ROLE_ASSIGNED,
                            );
                        },
                    );

                    throw new ForcedRollback('Roll back outer transaction.');
                },
            );

            $this->fail('The outer transaction should roll back.');
        } catch (ForcedRollback) {
            $this->assertDatabaseCount('acl_roles', 0);
            $this->assertDatabaseCount('acl_access_audits', 0);
            $this->assertSame([], $this->events);
            $this->assertSame([], $this->auditFailures);
        }
    }

    // ------------------------------------------------- boundaries and shape

    public function test_a_domain_exception_is_never_retried(): void
    {
        $scope = $this->scope();
        $attempts = 0;

        try {
            $this->orchestrator()->runCoordinated(
                MutationName::ROLE_ASSIGNED,
                function () use ($scope, &$attempts): PreparedMutation {
                    $attempts++;
                    $this->acquire($scope);

                    throw DuplicateActiveAssignment::forScope($scope);
                },
            );

            $this->fail('A domain conflict should propagate.');
        } catch (DuplicateActiveAssignment) {
            $this->assertSame(1, $attempts);
            $this->assertDatabaseCount('acl_roles', 0);
            $this->assertSame([], $this->events);
        }
    }

    public function test_an_unchanged_outcome_announces_nothing_after_a_retry(): void
    {
        $scope = $this->scope();
        $this->plantRace($scope);
        $role = $this->role();
        $attempts = 0;

        $result = $this->orchestrator()->runCoordinated(
            MutationName::ROLE_ASSIGNED,
            function () use ($scope, $role, &$attempts): PreparedMutation {
                $attempts++;
                $this->acquire($scope);

                return new PreparedMutation(
                    $role,
                    MutationOutcome::Unchanged,
                    null,
                );
            },
        );

        $this->assertSame(2, $attempts);
        $this->assertSame(MutationOutcome::Unchanged, $result->outcome);
        $this->assertNull($result->auditId);
        $this->assertDatabaseCount('acl_access_audits', 0);
        $this->assertSame([], $this->events);
    }

    public function test_the_operation_closure_reuses_its_captured_values(): void
    {
        $scope = $this->scope();
        $this->plantRace($scope);
        // Captured once, before the loop, exactly as a handler captures its
        // normalized command.
        $capturedAt = CarbonImmutable::parse('2026-08-12 09:30:00');
        $seen = [];

        $this->orchestrator()->runCoordinated(
            MutationName::ROLE_ASSIGNED,
            function () use ($scope, $capturedAt, &$seen): PreparedMutation {
                $seen[] = $capturedAt->toDateTimeString();
                $this->acquire($scope, $capturedAt);

                return $this->created(
                    event: MutationName::ROLE_ASSIGNED,
                    createdAt: $capturedAt,
                );
            },
        );

        $this->assertSame(
            ['2026-08-12 09:30:00', '2026-08-12 09:30:00'],
            $seen,
            'Every attempt reuses one clock reading.',
        );
        $this->assertDatabaseHas(self::LOCK_TABLE, [
            'created_at' => '2026-08-12 09:30:00',
        ]);
    }

    public function test_the_uncoordinated_path_is_still_single_attempt(): void
    {
        $scope = $this->scope();
        $this->plantRace($scope, times: 10);
        $attempts = 0;

        try {
            // Definition mutations use run(), so a confirmed race there is an
            // uncaught internal marker rather than a silent retry. Nothing in
            // production reaches this combination: only assignment handlers
            // acquire a coordination row, and all six use runCoordinated().
            $this->orchestrator()->run(
                function () use ($scope, &$attempts): PreparedMutation {
                    $attempts++;
                    $this->acquire($scope);

                    return $this->created();
                },
            );

            $this->fail('run() must not retry.');
        } catch (\Throwable) {
            $this->assertSame(1, $attempts);
        }
    }

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

    private function acquire(
        AssignmentScope $scope,
        ?CarbonImmutable $at = null,
    ): void {
        app(AssignmentLockStore::class)->acquire(
            $scope,
            $at ?? CarbonImmutable::parse('2026-08-12 12:00:00'),
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

    /**
     * @return callable(): int
     */
    private function countTransactions(): callable
    {
        $begins = 0;

        Event::listen(
            TransactionBeginning::class,
            static function () use (&$begins): void {
                $begins++;
            },
        );

        return static fn (): int => $begins;
    }

    private function breakAuditStorage(): void
    {
        config()->set(
            'gatetrail.models.access_audit',
            UnavailableAuditStorage::class,
        );
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
    private function created(
        string $event = MutationName::ROLE_ASSIGNED,
        ?CarbonImmutable $createdAt = null,
    ): PreparedMutation {
        return new PreparedMutation(
            $this->role(),
            MutationOutcome::Created,
            new PendingAuditRecord(
                event: $event,
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
                createdAt: ($createdAt ?? CarbonImmutable::parse(
                    '2026-08-12 12:00:00',
                ))->toDateTimeImmutable(),
            ),
        );
    }

    private function connection(): Connection
    {
        $connection = $this->app['db']->connection('testing');

        $this->assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}

final class UnavailableAuditStorage extends AccessAudit
{
    public function getTable(): string
    {
        return 'unavailable_access_audits';
    }
}

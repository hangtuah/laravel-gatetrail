<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use Carbon\CarbonImmutable;
use HangTuah\GateTrail\Audit\AccessAuditWriter;
use HangTuah\GateTrail\Audit\AuditFailureReporter;
use HangTuah\GateTrail\Audit\PendingAuditRecord;
use HangTuah\GateTrail\Events\AuditPersistenceFailed;
use HangTuah\GateTrail\Exceptions\AuditWriteFailed;
use HangTuah\GateTrail\Exceptions\UnsupportedAuditBoundary;
use HangTuah\GateTrail\Models\AccessAudit;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\Mutations\ActorIdentity;
use HangTuah\GateTrail\Mutations\AuditedMutationTransaction;
use HangTuah\GateTrail\Mutations\PreparedMutation;
use HangTuah\GateTrail\Mutations\StrictMutationTransaction;
use HangTuah\GateTrail\Support\ModelRegistry;
use HangTuah\GateTrail\Tests\Fixtures\ForcedRollback;
use HangTuah\GateTrail\Tests\Fixtures\RecordingLogger;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use LogicException;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class AuditedMutationTransactionTest extends TestCase
{
    /**
     * @var list<AuditPersistenceFailed>
     */
    private array $failures = [];

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);

        $this->failures = [];
        $this->logger = new RecordingLogger;
        $this->app->instance(LoggerInterface::class, $this->logger);

        Event::listen(
            AuditPersistenceFailed::class,
            function (AuditPersistenceFailed $event): void {
                $this->failures[] = $event;
            },
        );
    }

    public function test_strict_success_commits_mutation_and_audit_together(): void
    {
        $result = $this->orchestrator()->run(
            fn (): PreparedMutation => $this->created($this->role()),
        );

        $this->assertSame(MutationOutcome::Created, $result->outcome);
        $this->assertNotNull($result->auditId);
        $this->assertDatabaseCount('acl_roles', 1);
        $this->assertDatabaseCount('acl_access_audits', 1);
        $this->assertSame([], $this->failures);
        $this->assertSame([], $this->logger->errors());
    }

    public function test_strict_audit_failure_rolls_back_the_mutation(): void
    {
        $this->breakAuditStorage();

        try {
            $this->orchestrator()->run(
                fn (): PreparedMutation => $this->created($this->role()),
            );

            $this->fail('Strict auditing should throw.');
        } catch (AuditWriteFailed) {
            $this->assertDatabaseCount('acl_roles', 0);
            // A strict failure is thrown, never reported and swallowed.
            $this->assertSame([], $this->failures);
            $this->assertSame([], $this->logger->errors());
        }
    }

    public function test_strict_mode_uses_exactly_one_transaction(): void
    {
        $orchestrator = $this->orchestrator();
        $boundaries = $this->countTransactions();

        $orchestrator->run(
            fn (): PreparedMutation => $this->created($this->role()),
        );

        $this->assertSame(1, $boundaries('begin'));
        $this->assertSame(1, $boundaries('commit'));
    }

    public function test_owned_non_strict_success_uses_two_transactions(): void
    {
        config()->set('gatetrail.audit.strict', false);
        $orchestrator = $this->orchestrator();
        $boundaries = $this->countTransactions();

        $result = $orchestrator->run(
            fn (): PreparedMutation => $this->created($this->role()),
        );

        $this->assertSame(MutationOutcome::Created, $result->outcome);
        $this->assertNotNull($result->auditId);

        // The mutation commits before the audit is attempted, so the audit
        // runs in a second transaction rather than sharing the first.
        $this->assertSame(2, $boundaries('begin'));
        $this->assertSame(2, $boundaries('commit'));

        $this->assertDatabaseCount('acl_roles', 1);
        $this->assertDatabaseCount('acl_access_audits', 1);
        $this->assertSame([], $this->failures);
    }

    public function test_owned_non_strict_audit_failure_keeps_the_mutation(): void
    {
        config()->set('gatetrail.audit.strict', false);
        $this->breakAuditStorage();

        $result = $this->orchestrator()->run(
            fn (): PreparedMutation => $this->created($this->role()),
        );

        $this->assertSame(MutationOutcome::Created, $result->outcome);
        $this->assertNull($result->auditId);
        $this->assertDatabaseCount('acl_roles', 1);

        $this->assertCount(1, $this->failures);
        $this->assertSame('role.created', $this->failures[0]->event);

        $errors = $this->logger->errors();

        $this->assertCount(1, $errors);
        $this->assertSame(AuditFailureReporter::MESSAGE, $errors[0]['message']);
    }

    public function test_a_failing_logger_cannot_break_a_committed_mutation(): void
    {
        config()->set('gatetrail.audit.strict', false);
        $this->app->instance(
            LoggerInterface::class,
            new RecordingLogger(throws: true),
        );
        $this->breakAuditStorage();

        $result = $this->orchestrator()->run(
            fn (): PreparedMutation => $this->created($this->role()),
        );

        $this->assertSame(MutationOutcome::Created, $result->outcome);
        $this->assertNull($result->auditId);
        $this->assertDatabaseCount('acl_roles', 1);
        // A broken log channel must not stop the operational event either.
        $this->assertCount(1, $this->failures);
    }

    public function test_a_throwing_listener_cannot_break_a_committed_mutation(): void
    {
        config()->set('gatetrail.audit.strict', false);
        Event::listen(
            AuditPersistenceFailed::class,
            static function (): void {
                throw new RuntimeException('Listener exploded.');
            },
        );
        $this->breakAuditStorage();

        $result = $this->orchestrator()->run(
            fn (): PreparedMutation => $this->created($this->role()),
        );

        $this->assertSame(MutationOutcome::Created, $result->outcome);
        $this->assertNull($result->auditId);
        $this->assertDatabaseCount('acl_roles', 1);
        $this->assertCount(1, $this->logger->errors());
    }

    public function test_caller_owned_non_strict_defers_the_audit_to_the_outer_commit(): void
    {
        config()->set('gatetrail.audit.strict', false);
        $orchestrator = $this->orchestrator();

        $this->connection()->transaction(function () use ($orchestrator): void {
            $result = $orchestrator->run(
                fn (): PreparedMutation => $this->created($this->role()),
            );

            $this->assertSame(MutationOutcome::Created, $result->outcome);
            // The audit is pending until the caller's outermost commit.
            $this->assertNull($result->auditId);
            $this->assertDatabaseCount('acl_roles', 1);
            $this->assertDatabaseCount('acl_access_audits', 0);
        });

        $this->assertDatabaseCount('acl_roles', 1);
        $this->assertDatabaseCount('acl_access_audits', 1);
        $this->assertSame([], $this->failures);
    }

    public function test_caller_owned_rollback_writes_neither_mutation_nor_audit(): void
    {
        config()->set('gatetrail.audit.strict', false);
        $orchestrator = $this->orchestrator();

        try {
            $this->connection()->transaction(
                function () use ($orchestrator): void {
                    $orchestrator->run(
                        fn (): PreparedMutation => $this->created(
                            $this->role(),
                        ),
                    );

                    throw new ForcedRollback('Roll back outer transaction.');
                },
            );

            $this->fail('The outer transaction should roll back.');
        } catch (ForcedRollback) {
            $this->assertDatabaseCount('acl_roles', 0);
            $this->assertDatabaseCount('acl_access_audits', 0);
            // A discarded callback is not a failure.
            $this->assertSame([], $this->failures);
            $this->assertSame([], $this->logger->errors());
        }
    }

    public function test_deferred_audit_failure_is_reported_after_the_outer_commit(): void
    {
        config()->set('gatetrail.audit.strict', false);
        $this->breakAuditStorage();
        $orchestrator = $this->orchestrator();

        $this->connection()->transaction(function () use ($orchestrator): void {
            $result = $orchestrator->run(
                fn (): PreparedMutation => $this->created($this->role()),
            );

            $this->assertNull($result->auditId);
            $this->assertSame([], $this->failures);
        });

        $this->assertDatabaseCount('acl_roles', 1);
        $this->assertCount(1, $this->failures);
        $this->assertCount(1, $this->logger->errors());
    }

    public function test_a_connection_without_a_transaction_manager_is_rejected(): void
    {
        config()->set('gatetrail.audit.strict', false);
        $orchestrator = $this->orchestrator();
        $connection = $this->connection();
        $connection->beginTransaction();
        $connection->unsetTransactionManager();

        try {
            $orchestrator->run(
                fn (): PreparedMutation => $this->created($this->role()),
            );

            $this->fail('An unsupported boundary should be rejected.');
        } catch (UnsupportedAuditBoundary $exception) {
            $this->assertSame('testing', $exception->connection);
            $this->assertDatabaseCount('acl_roles', 0);
        } finally {
            $connection->rollBack();
        }
    }

    public function test_a_manager_that_cannot_defer_the_callback_is_rejected(): void
    {
        config()->set('gatetrail.audit.strict', false);
        $orchestrator = $this->orchestrator();
        $connection = $this->connection();

        // The transaction opens without the manager observing it, so the
        // manager exists but would run an after-commit callback immediately.
        // Only probing the behavior — not the method — catches this.
        $connection->unsetTransactionManager();
        $connection->beginTransaction();
        $connection->setTransactionManager($this->app->make('db.transactions'));

        try {
            $orchestrator->run(
                fn (): PreparedMutation => $this->created($this->role()),
            );

            $this->fail('An unsupported boundary should be rejected.');
        } catch (UnsupportedAuditBoundary) {
            $this->assertDatabaseCount('acl_roles', 0);
            $this->assertDatabaseCount('acl_access_audits', 0);
        } finally {
            $connection->rollBack();
        }
    }

    public function test_unchanged_results_never_touch_the_audit_pipeline(): void
    {
        config()->set('gatetrail.audit.strict', false);
        $this->breakAuditStorage();
        $role = $this->role();

        $result = $this->orchestrator()->run(
            fn (): PreparedMutation => new PreparedMutation(
                $role,
                MutationOutcome::Unchanged,
                null,
            ),
        );

        $this->assertSame(MutationOutcome::Unchanged, $result->outcome);
        $this->assertNull($result->auditId);
        $this->assertFalse($result->changed());
        $this->assertSame([], $this->failures);
        $this->assertSame([], $this->logger->errors());
    }

    public function test_unchanged_results_inside_a_caller_transaction_schedule_nothing(): void
    {
        config()->set('gatetrail.audit.strict', false);
        $orchestrator = $this->orchestrator();
        $role = $this->role();

        $this->connection()->transaction(
            function () use ($orchestrator, $role): void {
                $result = $orchestrator->run(
                    fn (): PreparedMutation => new PreparedMutation(
                        $role,
                        MutationOutcome::Unchanged,
                        null,
                    ),
                );

                $this->assertNull($result->auditId);
            },
        );

        $this->assertDatabaseCount('acl_access_audits', 0);
        $this->assertSame([], $this->failures);
        $this->assertSame([], $this->logger->errors());
    }

    public function test_an_unchanged_caller_owned_mutation_schedules_no_callback(): void
    {
        config()->set('gatetrail.audit.strict', false);
        $orchestrator = $this->orchestrator();
        $role = $this->role();

        $this->connection()->transaction(
            function () use ($orchestrator, $role): void {
                $orchestrator->run(
                    fn (): PreparedMutation => new PreparedMutation(
                        $role,
                        MutationOutcome::Unchanged,
                        null,
                    ),
                );

                // Nothing at all is registered: the capability check inspects
                // transaction state instead of registering a probe.
                $this->assertCount(0, $this->pendingCallbacks());
            },
        );

        $this->assertDatabaseCount('acl_access_audits', 0);
    }

    public function test_a_changed_caller_owned_mutation_schedules_one_audit_callback(): void
    {
        config()->set('gatetrail.audit.strict', false);
        $orchestrator = $this->orchestrator();

        $this->connection()->transaction(function () use ($orchestrator): void {
            $orchestrator->run(
                fn (): PreparedMutation => $this->created($this->role()),
            );

            $this->assertCount(1, $this->pendingCallbacks());
        });

        $this->assertDatabaseCount('acl_access_audits', 1);
    }

    public function test_repeated_caller_owned_mutations_schedule_one_callback_each(): void
    {
        config()->set('gatetrail.audit.strict', false);
        $orchestrator = $this->orchestrator();

        $this->connection()->transaction(function () use ($orchestrator): void {
            $orchestrator->run(
                fn (): PreparedMutation => $this->created($this->role()),
            );
            $orchestrator->run(
                fn (): PreparedMutation => new PreparedMutation(
                    Role::query()->sole(),
                    MutationOutcome::Unchanged,
                    null,
                ),
            );

            // The unchanged call adds nothing to the changed call's callback.
            $this->assertCount(1, $this->pendingCallbacks());
        });

        $this->assertDatabaseCount('acl_access_audits', 1);
    }

    public function test_strict_mode_schedules_no_callback_at_all(): void
    {
        $orchestrator = $this->orchestrator();

        $this->connection()->transaction(function () use ($orchestrator): void {
            $result = $orchestrator->run(
                fn (): PreparedMutation => $this->created($this->role()),
            );

            $this->assertNotNull($result->auditId);
            $this->assertCount(0, $this->pendingCallbacks());
            // Strict auditing writes inside the caller transaction, so the
            // audit is already visible before the outer commit.
            $this->assertDatabaseCount('acl_access_audits', 1);
        });
    }

    public function test_an_unsupported_boundary_is_rejected_before_the_operation_runs(): void
    {
        config()->set('gatetrail.audit.strict', false);
        $orchestrator = $this->orchestrator();
        $connection = $this->connection();
        $connection->beginTransaction();
        $connection->unsetTransactionManager();
        $ran = false;

        try {
            $orchestrator->run(
                function () use (&$ran): PreparedMutation {
                    $ran = true;

                    return $this->created($this->role());
                },
            );

            $this->fail('An unsupported boundary should be rejected.');
        } catch (UnsupportedAuditBoundary) {
            $this->assertFalse(
                $ran,
                'The operation closure must never run.',
            );
            $this->assertDatabaseCount('acl_roles', 0);
        } finally {
            $connection->rollBack();
        }
    }

    public function test_a_rolled_back_callback_is_discarded_without_running(): void
    {
        config()->set('gatetrail.audit.strict', false);
        $orchestrator = $this->orchestrator();

        try {
            $this->connection()->transaction(
                function () use ($orchestrator): void {
                    $orchestrator->run(
                        fn (): PreparedMutation => $this->created(
                            $this->role(),
                        ),
                    );

                    $this->assertCount(1, $this->pendingCallbacks());

                    throw new ForcedRollback('Roll back outer transaction.');
                },
            );

            $this->fail('The outer transaction should roll back.');
        } catch (ForcedRollback) {
            $this->assertCount(0, $this->pendingCallbacks());
            $this->assertDatabaseCount('acl_roles', 0);
            $this->assertDatabaseCount('acl_access_audits', 0);
            $this->assertSame([], $this->failures);
            $this->assertSame([], $this->logger->errors());
        }
    }

    public function test_disabled_auditing_is_not_implemented_yet(): void
    {
        config()->set('gatetrail.audit.enabled', false);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires enabled auditing');

        $this->orchestrator()->run(
            fn (): PreparedMutation => $this->created($this->role()),
        );
    }

    public function test_a_prepared_mutation_cannot_misreport_its_evidence(): void
    {
        $role = $this->role();

        try {
            new PreparedMutation(
                $role,
                MutationOutcome::Unchanged,
                $this->record(),
            );

            $this->fail('An unchanged mutation cannot carry evidence.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString(
                'cannot carry audit evidence',
                $exception->getMessage(),
            );
        }

        try {
            new PreparedMutation($role, MutationOutcome::Created, null);

            $this->fail('A changed mutation must carry evidence.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString(
                'must carry audit evidence',
                $exception->getMessage(),
            );
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
        ] as $service) {
            $this->app->forgetInstance($service);
        }

        return $this->app->make(AuditedMutationTransaction::class);
    }

    private function connection(): Connection
    {
        $connection = $this->app['db']->connection('testing');

        $this->assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    /**
     * The callbacks Laravel would execute at the next outermost commit.
     *
     * @return list<callable>
     */
    private function pendingCallbacks(): array
    {
        $applicable = $this->app->make('db.transactions')
            ->callbackApplicableTransactions()
            ->last();

        return $applicable === null ? [] : $applicable->getCallbacks();
    }

    /**
     * Counts real transaction boundaries on the GateTrail connection.
     *
     * @return callable(string): int
     */
    private function countTransactions(): callable
    {
        $counts = ['begin' => 0, 'commit' => 0];

        Event::listen(
            TransactionBeginning::class,
            static function () use (&$counts): void {
                $counts['begin']++;
            },
        );
        Event::listen(
            TransactionCommitted::class,
            static function () use (&$counts): void {
                $counts['commit']++;
            },
        );

        return static function (string $key) use (&$counts): int {
            return $counts[$key];
        };
    }

    private function breakAuditStorage(): void
    {
        config()->set(
            'gatetrail.models.access_audit',
            BrokenAuditStorage::class,
        );
    }

    private function role(): Role
    {
        return Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'web',
            'description' => null,
        ]);
    }

    /**
     * @return PreparedMutation<Role>
     */
    private function created(Role $role): PreparedMutation
    {
        return new PreparedMutation(
            $role,
            MutationOutcome::Created,
            $this->record(),
        );
    }

    private function record(): PendingAuditRecord
    {
        return new PendingAuditRecord(
            event: 'role.created',
            actor: new ActorIdentity('gatetrail.system', 'audit-suite'),
            subjectType: null,
            subjectId: null,
            targetType: 'role',
            targetId: 1,
            before: null,
            after: '{"id":"1","name":"reviewer","guard_name":"web"}',
            reason: 'Create reviewer role',
            context: '{"schema_version":1,"guard_name":"web","metadata":{}}',
            ipAddress: null,
            userAgent: null,
            requestId: null,
            createdAt: CarbonImmutable::parse(
                '2026-07-30 08:00:00',
                'UTC',
            )->toDateTimeImmutable(),
        );
    }
}

final class BrokenAuditStorage extends AccessAudit
{
    public function getTable(): string
    {
        return 'broken_access_audits';
    }
}

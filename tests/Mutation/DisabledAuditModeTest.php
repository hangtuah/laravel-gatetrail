<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use DateTimeImmutable;
use HangTuah\GateTrail\Contracts\AssignmentManager;
use HangTuah\GateTrail\Contracts\PermissionManager;
use HangTuah\GateTrail\Contracts\RoleManager;
use HangTuah\GateTrail\Events\AuditPersistenceFailed;
use HangTuah\GateTrail\Exceptions\DuplicatePermission;
use HangTuah\GateTrail\Exceptions\DuplicateRole;
use HangTuah\GateTrail\Exceptions\InvalidAuditConfiguration;
use HangTuah\GateTrail\Exceptions\ReasonRequired;
use HangTuah\GateTrail\Models\AccessAudit;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\Mutations\MutationReasonValidator;
use HangTuah\GateTrail\PermissionReference;
use HangTuah\GateTrail\RoleChanges;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Concerns\ExercisesEveryMutation;
use HangTuah\GateTrail\Tests\Fixtures\ForcedRollback;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use HangTuah\GateTrail\Tests\Fixtures\RecordingLogger;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Proves every managed mutation still works with auditing switched off, and
 * that nothing in the audit pipeline runs.
 */
final class DisabledAuditModeTest extends TestCase
{
    use ExercisesEveryMutation;

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
        $this->app->instance(
            ClockInterface::class,
            new FrozenClock(
                new DateTimeImmutable('2026-07-30 08:00:00 UTC'),
            ),
        );
        config()->set('gatetrail.audit.enabled', false);

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

    public function test_every_managed_mutation_succeeds_without_an_audit(): void
    {
        $results = $this->everyMutation();

        $this->assertSame(
            self::EVENTS,
            array_keys($results),
            'Every one of the fourteen handlers must run.',
        );

        $expected = [
            'role.created' => MutationOutcome::Created,
            'role.updated' => MutationOutcome::Updated,
            'permission.created' => MutationOutcome::Created,
            'permission.updated' => MutationOutcome::Updated,
            'role.assigned' => MutationOutcome::Created,
            'role.revoked' => MutationOutcome::Revoked,
            'permission.granted_to_subject' => MutationOutcome::Created,
            'permission.revoked_from_subject' => MutationOutcome::Revoked,
            'permission.granted_to_role' => MutationOutcome::Created,
            'permission.revoked_from_role' => MutationOutcome::Revoked,
            'permission.deactivated' => MutationOutcome::Deactivated,
            'permission.restored' => MutationOutcome::Restored,
            'role.deactivated' => MutationOutcome::Deactivated,
            'role.restored' => MutationOutcome::Restored,
        ];

        foreach ($expected as $event => $outcome) {
            $this->assertSame(
                $outcome,
                $results[$event]->outcome,
                "[$event] should report its normal outcome.",
            );
            $this->assertNull(
                $results[$event]->auditId,
                "[$event] must report no audit identifier.",
            );
            $this->assertTrue($results[$event]->resource->exists);
        }

        $this->assertDatabaseCount('acl_access_audits', 0);
        $this->assertSame([], $this->failures);
        $this->assertSame([], $this->logger->records);
    }

    public function test_every_mutation_persists_its_resource_correctly(): void
    {
        $this->everyMutation();

        $role = Role::query()->sole();
        $permission = Permission::query()->sole();

        // The full lifecycle ran: created, renamed description, deactivated,
        // then restored.
        $this->assertSame('reviewer', $role->getAttribute('name'));
        $this->assertSame(
            'Reviews submissions.',
            $role->getAttribute('description'),
        );
        $this->assertNull($role->getAttribute('deleted_at'));
        $this->assertSame(
            'documents.approve',
            $permission->getAttribute('name'),
        );
        $this->assertSame(
            'Approve all documents.',
            $permission->getAttribute('description'),
        );
        $this->assertNull($permission->getAttribute('deleted_at'));

        $this->assertDatabaseCount('acl_role_assignments', 1);
        $this->assertDatabaseCount('acl_permission_assignments', 1);
        $this->assertDatabaseCount('acl_role_permissions', 1);

        foreach ([
            'acl_role_assignments',
            'acl_permission_assignments',
            'acl_role_permissions',
        ] as $table) {
            $this->assertNotNull(
                $this->app['db']->table($table)->value('revoked_at'),
                "[$table] should retain its revocation tuple.",
            );
        }

        $this->assertDatabaseCount('acl_access_audits', 0);
    }

    public function test_every_mutation_survives_unavailable_audit_storage(): void
    {
        config()->set(
            'gatetrail.models.access_audit',
            DisabledModeAccessAudit::class,
        );

        $results = $this->everyMutation();

        foreach ($results as $event => $result) {
            $this->assertNull(
                $result->auditId,
                "[$event] must report no audit identifier.",
            );
        }

        $this->assertSame(1, Role::query()->count());
        $this->assertSame(1, Permission::query()->count());
        $this->assertSame([], $this->failures);
        $this->assertSame([], $this->logger->records);
    }

    public function test_no_statement_ever_targets_the_audit_table(): void
    {
        $statements = [];
        $this->app['db']->connection('testing')->beforeExecuting(
            static function (string $query) use (&$statements): void {
                $statements[] = $query;
            },
        );

        $this->everyMutation();
        $executed = $statements;

        $this->assertNotSame([], $executed);

        foreach ($executed as $statement) {
            $this->assertStringNotContainsString(
                'acl_access_audits',
                $statement,
                'Disabled auditing must never touch audit storage.',
            );
        }
    }

    public function test_a_caller_owned_commit_preserves_every_mutation(): void
    {
        $this->app['db']->connection('testing')->transaction(function (): void {
            $results = $this->everyMutation();

            foreach ($results as $event => $result) {
                $this->assertNull(
                    $result->auditId,
                    "[$event] must report no audit identifier.",
                );
            }

            // One deferred announcement per changed mutation, and nothing
            // else: disabled auditing schedules no audit write.
            $this->assertCount(
                count(self::EVENTS),
                $this->pendingCallbacks(),
            );
        });

        $this->assertSame(1, Role::query()->count());
        $this->assertSame(1, Permission::query()->count());
        $this->assertDatabaseCount('acl_access_audits', 0);
        $this->assertSame([], $this->failures);
        $this->assertSame([], $this->logger->records);
    }

    public function test_a_caller_owned_rollback_removes_every_mutation(): void
    {
        try {
            $this->app['db']->connection('testing')->transaction(
                function (): void {
                    $this->everyMutation();

                    throw new ForcedRollback('Roll back outer transaction.');
                },
            );

            $this->fail('The outer transaction should roll back.');
        } catch (ForcedRollback) {
            $this->assertDatabaseCount('acl_roles', 0);
            $this->assertDatabaseCount('acl_permissions', 0);
            $this->assertDatabaseCount('acl_role_assignments', 0);
            $this->assertDatabaseCount('acl_access_audits', 0);
            $this->assertSame([], $this->failures);
            $this->assertSame([], $this->logger->records);
        }
    }

    public function test_an_unchanged_mutation_remains_a_no_op(): void
    {
        $actor = SystemActor::named('audit-suite');
        $roles = app(RoleManager::class);
        $role = $roles->create(
            'reviewer',
            'web',
            $actor,
            description: 'Reviews documents.',
        )->resource;
        $updatedAt = Role::query()->sole()->getAttribute('updated_at');

        $result = $roles->update(
            $role,
            RoleChanges::description('Reviews documents.'),
            $actor,
        );

        $this->assertSame(MutationOutcome::Unchanged, $result->outcome);
        $this->assertNull($result->auditId);
        $this->assertFalse($result->changed());
        $this->assertEquals(
            $updatedAt,
            Role::query()->sole()->getAttribute('updated_at'),
        );
        $this->assertDatabaseCount('acl_access_audits', 0);
        $this->assertSame([], $this->failures);
        $this->assertSame([], $this->logger->records);
    }

    public function test_validation_failures_still_prevent_mutation(): void
    {
        config()->set('gatetrail.definitions.require_reason', true);
        $this->app->forgetInstance(
            MutationReasonValidator::class,
        );

        try {
            app(RoleManager::class)->create(
                'reviewer',
                'web',
                SystemActor::named('audit-suite'),
            );

            $this->fail('A missing definition reason should be rejected.');
        } catch (ReasonRequired) {
            $this->assertDatabaseCount('acl_roles', 0);
            $this->assertDatabaseCount('acl_access_audits', 0);
        }
    }

    public function test_definition_namespaces_are_still_enforced(): void
    {
        $actor = SystemActor::named('audit-suite');
        app(RoleManager::class)->create('reviewer', 'web', $actor);
        app(PermissionManager::class)->create(
            'documents.approve',
            'web',
            $actor,
        );

        try {
            app(RoleManager::class)->create('reviewer', 'web', $actor);

            $this->fail('A duplicate role namespace should be rejected.');
        } catch (DuplicateRole) {
            $this->assertSame(1, Role::query()->count());
        }

        try {
            app(PermissionManager::class)->create(
                'documents.approve',
                'web',
                $actor,
            );

            $this->fail('A duplicate permission namespace should be rejected.');
        } catch (DuplicatePermission) {
            $this->assertSame(1, Permission::query()->count());
        }

        $this->assertDatabaseCount('acl_access_audits', 0);
    }

    public function test_assignment_idempotency_is_unchanged(): void
    {
        $actor = SystemActor::named('audit-suite');
        $subject = TestSubject::query()->create(['name' => 'Employee']);
        $role = app(RoleManager::class)
            ->create('reviewer', 'web', $actor)
            ->resource;
        $assignments = app(AssignmentManager::class);

        $first = $assignments->assignRole(
            subject: $subject,
            role: $role,
            actor: $actor,
            reason: 'Assigned by administrator',
        );
        $replay = $assignments->assignRole(
            subject: $subject,
            role: $role,
            actor: $actor,
            reason: 'Assigned by administrator',
        );

        $this->assertSame(MutationOutcome::Created, $first->outcome);
        $this->assertSame(MutationOutcome::Unchanged, $replay->outcome);
        $this->assertNull($first->auditId);
        $this->assertNull($replay->auditId);
        $this->assertSame(
            $first->resource->getKey(),
            $replay->resource->getKey(),
        );
        $this->assertDatabaseCount('acl_role_assignments', 1);
        $this->assertDatabaseCount('acl_access_audits', 0);
    }

    public function test_configured_custom_models_work_with_auditing_disabled(): void
    {
        config()->set('gatetrail.models.role', DisabledModeRole::class);
        config()->set(
            'gatetrail.models.permission',
            DisabledModePermission::class,
        );
        $actor = SystemActor::named('audit-suite');

        $role = app(RoleManager::class)->create('reviewer', 'web', $actor);
        $permission = app(PermissionManager::class)->create(
            'documents.approve',
            'web',
            $actor,
        );

        $this->assertInstanceOf(DisabledModeRole::class, $role->resource);
        $this->assertInstanceOf(
            DisabledModePermission::class,
            $permission->resource,
        );
        $this->assertNull($role->auditId);
        $this->assertNull($permission->auditId);
        $this->assertDatabaseCount('acl_access_audits', 0);
    }

    public function test_string_and_integer_references_still_resolve(): void
    {
        $actor = SystemActor::named('audit-suite');
        $permissions = app(PermissionManager::class);
        $permission = $permissions->create(
            'documents.approve',
            'web',
            $actor,
        )->resource;
        $key = $permission->getKey();

        $byInteger = $permissions->deactivate((int) $key, $actor);
        $byStringReference = $permissions->restore(
            PermissionReference::id((string) $key),
            $actor,
        );
        $byName = $permissions->deactivate('documents.approve', $actor);

        $this->assertSame(
            MutationOutcome::Deactivated,
            $byInteger->outcome,
        );
        $this->assertSame(
            MutationOutcome::Restored,
            $byStringReference->outcome,
        );
        $this->assertSame(MutationOutcome::Deactivated, $byName->outcome);

        foreach ([$byInteger, $byStringReference, $byName] as $result) {
            $this->assertNull($result->auditId);
            $this->assertSame($key, $result->resource->getKey());
        }

        $this->assertDatabaseCount('acl_access_audits', 0);
    }

    public function test_a_malformed_flag_still_fails_closed(): void
    {
        config()->set('gatetrail.audit.enabled', 'false');

        try {
            app(RoleManager::class)->create(
                'reviewer',
                'web',
                SystemActor::named('audit-suite'),
            );

            $this->fail('A malformed enabled flag should be rejected.');
        } catch (InvalidAuditConfiguration $exception) {
            $this->assertSame('gatetrail.audit.enabled', $exception->key);
            $this->assertDatabaseCount('acl_roles', 0);
        }
    }

    public function test_a_disabled_strict_flag_is_irrelevant(): void
    {
        foreach ([true, false] as $strict) {
            Role::query()->withTrashed()->forceDelete();
            config()->set('gatetrail.audit.strict', $strict);

            $result = app(RoleManager::class)->create(
                'reviewer',
                'web',
                SystemActor::named('audit-suite'),
            );

            $this->assertSame(MutationOutcome::Created, $result->outcome);
            $this->assertNull($result->auditId);
        }

        $this->assertDatabaseCount('acl_access_audits', 0);
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
}

final class DisabledModeAccessAudit extends AccessAudit
{
    public function getTable(): string
    {
        return 'disabled_mode_access_audits';
    }
}

final class DisabledModeRole extends Role {}

final class DisabledModePermission extends Permission {}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use DateTimeImmutable;
use HangTuah\GateTrail\Audit\SensitiveMetadataRedactor;
use HangTuah\GateTrail\Contracts\AssignmentManager;
use HangTuah\GateTrail\Exceptions\AuditWriteFailed;
use HangTuah\GateTrail\Exceptions\DuplicateRole;
use HangTuah\GateTrail\Exceptions\InvalidActor;
use HangTuah\GateTrail\Exceptions\InvalidAuditMetadata;
use HangTuah\GateTrail\Exceptions\ReasonRequired;
use HangTuah\GateTrail\Exceptions\RoleNotFound;
use HangTuah\GateTrail\Models\AccessAudit;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\Mutations\Commands\CreateRoleCommand;
use HangTuah\GateTrail\Mutations\Commands\DeactivateRoleCommand;
use HangTuah\GateTrail\Mutations\Handlers\CreateRoleHandler;
use HangTuah\GateTrail\Mutations\Handlers\DeactivateRoleHandler;
use HangTuah\GateTrail\Mutations\MutationReasonValidator;
use HangTuah\GateTrail\RoleReference;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Fixtures\ForcedRollback;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

final class DeactivateRoleHandlerTest extends TestCase
{
    private const ACTIVE_SNAPSHOT = '{"id":"1","name":"reviewer",'
        .'"guard_name":"web","description":"Reviews documents.",'
        .'"deleted_at":null}';

    private const DEACTIVATED_SNAPSHOT = '{"id":"1","name":"reviewer",'
        .'"guard_name":"web","description":"Reviews documents.",'
        .'"deleted_at":"2026-07-29T12:00:00.000000Z"}';

    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);
        $this->role = Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'web',
            'description' => 'Reviews documents.',
        ]);
        $this->role->forceFill([
            'created_at' => '2026-07-28 09:00:00',
            'updated_at' => '2026-07-28 09:00:00',
        ])->save();
    }

    public function test_handler_deactivates_with_canonical_audit_evidence(): void
    {
        $result = app(DeactivateRoleHandler::class)->handle($this->command(
            role: $this->role,
            reason: 'Superseded by a narrower role',
            metadata: ['ticket' => 'SEC-42'],
        ));

        $this->assertSame(MutationOutcome::Deactivated, $result->outcome);
        $this->assertTrue($result->changed());
        $this->assertNotNull($result->auditId);

        $persisted = $this->persisted();

        $this->assertSame(
            '2026-07-29 12:00:00',
            $persisted->getAttribute('deleted_at')?->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-07-29 12:00:00',
            $persisted->getAttribute('updated_at')?->format('Y-m-d H:i:s'),
        );
        $this->assertSame('reviewer', $persisted->getAttribute('name'));
        $this->assertSame('web', $persisted->getAttribute('guard_name'));
        $this->assertSame(
            'Reviews documents.',
            $persisted->getAttribute('description'),
        );
        $this->assertSame(
            '2026-07-28 09:00:00',
            $persisted->getAttribute('created_at')?->format('Y-m-d H:i:s'),
        );

        $audit = $this->audit($result->auditId);

        $this->assertSame('role.deactivated', $audit->event);
        $this->assertSame('role', $audit->target_type);
        $this->assertSame(
            (string) $this->role->getKey(),
            (string) $audit->target_id,
        );
        $this->assertNull($audit->subject_type);
        $this->assertNull($audit->subject_id);
        $this->assertSame('gatetrail.system', $audit->actor_type);
        $this->assertSame('definition-suite', $audit->actor_id);
        $this->assertSame(
            'Superseded by a narrower role',
            $audit->reason,
        );
        $this->assertSame(self::ACTIVE_SNAPSHOT, $audit->before);
        $this->assertSame(self::DEACTIVATED_SNAPSHOT, $audit->after);
        $this->assertSame(
            '{"schema_version":1,"guard_name":"web",'
            .'"role_name":"reviewer","metadata":{"ticket":"SEC-42"}}',
            $audit->context,
        );
        $this->assertSame(
            '2026-07-29 12:00:00',
            $audit->created_at,
        );
    }

    public function test_the_returned_resource_is_the_deactivated_role(): void
    {
        $result = app(DeactivateRoleHandler::class)->handle(
            $this->command(role: $this->role),
        );

        $this->assertSame(
            $this->role->getKey(),
            $result->resource->getKey(),
        );
        $this->assertSame(
            '2026-07-29 12:00:00',
            $result->resource->getAttribute('deleted_at')
                ?->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            0,
            Role::query()->whereKey($this->role->getKey())->count(),
        );
        $this->assertSame(
            1,
            Role::query()
                ->withTrashed()
                ->whereKey($this->role->getKey())
                ->count(),
        );
    }

    public function test_integer_id_reference_and_name_inputs_all_resolve(): void
    {
        foreach ([
            'integer' => static fn (Role $role): int => (int) $role->getKey(),
            'reference' => static fn (Role $role): RoleReference => RoleReference::id(
                $role->getKey(),
            ),
            'name' => static fn (Role $role): string => 'reviewer',
        ] as $label => $input) {
            $role = $this->freshActiveRole();

            $result = app(DeactivateRoleHandler::class)->handle(
                $this->command(role: $input($role)),
            );

            $this->assertSame(
                MutationOutcome::Deactivated,
                $result->outcome,
                "The [$label] input should deactivate the role.",
            );
            $this->assertNotNull(
                Role::query()
                    ->withTrashed()
                    ->whereKey($role->getKey())
                    ->first()
                    ?->getAttribute('deleted_at'),
            );
        }
    }

    public function test_explicit_inputs_do_not_depend_on_the_default_guard(): void
    {
        $apiRole = Role::query()->create([
            'name' => 'auditor',
            'guard_name' => 'api',
            'description' => null,
        ]);
        config()->set('gatetrail.guards.default', ' ');

        $result = app(DeactivateRoleHandler::class)->handle(
            $this->command(role: RoleReference::id($apiRole->getKey())),
        );

        $this->assertSame(MutationOutcome::Deactivated, $result->outcome);
        $this->assertSame(
            '{"schema_version":1,"guard_name":"api",'
            .'"role_name":"auditor","metadata":{}}',
            $this->audit($result->auditId)->context,
        );
    }

    public function test_string_name_uses_the_default_guard(): void
    {
        config()->set('gatetrail.guards.default', 'api');
        $apiRole = Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'api',
            'description' => null,
        ]);

        app(DeactivateRoleHandler::class)->handle(
            $this->command(role: 'reviewer'),
        );

        $this->assertNotNull(
            Role::query()
                ->withTrashed()
                ->whereKey($apiRole->getKey())
                ->first()
                ?->getAttribute('deleted_at'),
        );
        $this->assertNull($this->persisted()->getAttribute('deleted_at'));
    }

    public function test_human_actor_is_recorded(): void
    {
        $actor = TestSubject::query()->create(['name' => 'Admin']);

        $result = app(DeactivateRoleHandler::class)->handle($this->command(
            role: $this->role,
            actor: $actor,
        ));
        $audit = $this->audit($result->auditId);

        $this->assertSame($actor->getMorphClass(), $audit->actor_type);
        $this->assertSame((string) $actor->getKey(), $audit->actor_id);
        $this->assertNull($audit->reason);
    }

    public function test_required_definition_reason_fails_before_transaction(): void
    {
        config()->set('gatetrail.definitions.require_reason', true);
        app()->forgetInstance(MutationReasonValidator::class);

        try {
            app(DeactivateRoleHandler::class)->handle(
                $this->command(role: $this->role),
            );

            $this->fail('A missing definition reason should be rejected.');
        } catch (ReasonRequired) {
            $this->assertRoleRemainsActive();
        }
    }

    public function test_sensitive_reason_is_redacted_in_the_audit_only(): void
    {
        config()->set('gatetrail.sensitive_fields', ['reason']);

        $result = app(DeactivateRoleHandler::class)->handle($this->command(
            role: $this->role,
            reason: 'Superseded by a narrower role',
        ));

        $this->assertSame(
            SensitiveMetadataRedactor::MARKER,
            $this->audit($result->auditId)->reason,
        );
    }

    public function test_invalid_metadata_fails_before_transaction(): void
    {
        try {
            app(DeactivateRoleHandler::class)->handle($this->command(
                role: $this->role,
                metadata: ['handle' => fopen('php://memory', 'r')],
            ));

            $this->fail('Invalid metadata should be rejected.');
        } catch (InvalidAuditMetadata) {
            $this->assertRoleRemainsActive();
        }
    }

    public function test_invalid_actor_fails_before_transaction(): void
    {
        try {
            app(DeactivateRoleHandler::class)->handle($this->command(
                role: $this->role,
                actor: new TestSubject,
            ));

            $this->fail('An unpersisted actor should be rejected.');
        } catch (InvalidActor) {
            $this->assertRoleRemainsActive();
        }
    }

    public function test_missing_role_is_rejected(): void
    {
        try {
            app(DeactivateRoleHandler::class)->handle(
                $this->command(role: 4242),
            );

            $this->fail('A missing role should be rejected.');
        } catch (RoleNotFound $exception) {
            $this->assertSame('ID', $exception->referenceType);
            $this->assertNull($exception->guard);
            $this->assertRoleRemainsActive();
        }
    }

    public function test_already_deactivated_role_is_rejected(): void
    {
        app(DeactivateRoleHandler::class)->handle(
            $this->command(role: $this->role),
        );

        try {
            app(DeactivateRoleHandler::class)->handle(
                $this->command(role: RoleReference::id($this->role->getKey())),
            );

            $this->fail('A deactivated role should be rejected.');
        } catch (RoleNotFound $exception) {
            $this->assertSame('web', $exception->guard);
            $this->assertSame('ID', $exception->referenceType);
        }

        $this->assertDatabaseCount('acl_access_audits', 1);
        $this->assertSame(
            '2026-07-29 12:00:00',
            $this->persisted()->getAttribute('deleted_at')
                ?->format('Y-m-d H:i:s'),
        );
    }

    public function test_malformed_explicit_identifier_remains_safe(): void
    {
        try {
            app(DeactivateRoleHandler::class)->handle(
                $this->command(role: RoleReference::id('not-an-integer')),
            );

            $this->fail('A malformed identifier should be rejected.');
        } catch (RoleNotFound $exception) {
            $this->assertSame('ID', $exception->referenceType);
            $this->assertStringNotContainsString(
                'not-an-integer',
                $exception->getMessage(),
            );
            $this->assertRoleRemainsActive();
        }
    }

    public function test_audit_failure_restores_the_active_role(): void
    {
        config()->set(
            'gatetrail.models.access_audit',
            MissingRoleDeactivationAccessAudit::class,
        );

        try {
            app(DeactivateRoleHandler::class)->handle(
                $this->command(role: $this->role),
            );

            $this->fail('Audit persistence should fail.');
        } catch (AuditWriteFailed) {
            $this->assertRoleRemainsActive();
        }
    }

    public function test_caller_owned_rollback_restores_the_role_and_audit(): void
    {
        try {
            $this->app['db']->connection('testing')->transaction(
                function (): void {
                    $result = app(DeactivateRoleHandler::class)->handle(
                        $this->command(role: $this->role),
                    );

                    $this->assertSame(
                        MutationOutcome::Deactivated,
                        $result->outcome,
                    );
                    $this->assertSame(
                        '2026-07-29 12:00:00',
                        $this->persisted()->getAttribute('deleted_at')
                            ?->format('Y-m-d H:i:s'),
                    );
                    $this->assertDatabaseCount('acl_access_audits', 1);

                    throw new ForcedRollback(
                        'Roll back outer transaction.',
                    );
                },
            );

            $this->fail('The outer transaction should roll back.');
        } catch (ForcedRollback) {
            $this->assertRoleRemainsActive();
        }
    }

    public function test_disabled_auditing_persists_without_an_audit(): void
    {
        config()->set('gatetrail.audit.enabled', false);

        $result = app(DeactivateRoleHandler::class)->handle($this->command(role: $this->role));

        $this->assertSame(MutationOutcome::Deactivated, $result->outcome);
        $this->assertNull($result->auditId);
        $this->assertDatabaseCount('acl_access_audits', 0);
    }

    public function test_configured_role_subclass_remains_supported(): void
    {
        config()->set(
            'gatetrail.models.role',
            DeactivateHandlerCustomRole::class,
        );

        $result = app(DeactivateRoleHandler::class)->handle(
            $this->command(role: (int) $this->role->getKey()),
        );

        $this->assertInstanceOf(
            DeactivateHandlerCustomRole::class,
            $result->resource,
        );
        $this->assertSame(MutationOutcome::Deactivated, $result->outcome);
        $this->assertSame(
            self::DEACTIVATED_SNAPSHOT,
            $this->audit($result->auditId)->after,
        );
    }

    public function test_assignments_are_preserved_and_not_revoked(): void
    {
        $subject = TestSubject::query()->create(['name' => 'Employee']);
        $permission = Permission::query()->create([
            'name' => 'documents.review',
            'guard_name' => 'web',
            'description' => null,
        ]);
        $assignments = app(AssignmentManager::class);
        $roleAssignment = $assignments->assignRole(
            subject: $subject,
            role: $this->role,
            actor: SystemActor::named('definition-suite'),
            reason: 'Assigned by administrator',
        )->resource;
        $rolePermission = $assignments->grantPermissionToRole(
            role: $this->role,
            permission: $permission,
            actor: SystemActor::named('definition-suite'),
            reason: 'Granted by administrator',
        )->resource;
        $before = [
            'role_assignments' => $this->rows('acl_role_assignments'),
            'role_permissions' => $this->rows('acl_role_permissions'),
        ];

        app(DeactivateRoleHandler::class)->handle(
            $this->command(role: $this->role),
        );

        $this->assertSame(
            $before['role_assignments'],
            $this->rows('acl_role_assignments'),
        );
        $this->assertSame(
            $before['role_permissions'],
            $this->rows('acl_role_permissions'),
        );
        $this->assertDatabaseCount('acl_role_assignments', 1);
        $this->assertDatabaseCount('acl_role_permissions', 1);
        $this->assertNull(
            $roleAssignment->fresh()?->getAttribute('revoked_at'),
        );
        $this->assertNull(
            $rolePermission->fresh()?->getAttribute('revoked_at'),
        );
        $this->assertSame(
            (string) $this->role->getKey(),
            (string) $roleAssignment->fresh()?->getAttribute('role_id'),
        );
    }

    public function test_deactivated_namespace_still_rejects_creation(): void
    {
        app(DeactivateRoleHandler::class)->handle(
            $this->command(role: $this->role),
        );

        $this->expectException(DuplicateRole::class);

        app(CreateRoleHandler::class)->handle(CreateRoleCommand::capture(
            name: 'reviewer',
            guard: 'web',
            actor: SystemActor::named('definition-suite'),
            reason: null,
            description: null,
            metadata: [],
            clock: $this->clock(),
        ));
    }

    private function freshActiveRole(): Role
    {
        Role::query()->withTrashed()->forceDelete();

        $role = Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'web',
            'description' => 'Reviews documents.',
        ]);

        return $role;
    }

    private function persisted(): Role
    {
        $role = Role::query()
            ->withTrashed()
            ->whereKey($this->role->getKey())
            ->first();

        $this->assertInstanceOf(Role::class, $role);

        return $role;
    }

    private function assertRoleRemainsActive(): void
    {
        $persisted = $this->persisted();

        $this->assertNull($persisted->getAttribute('deleted_at'));
        $this->assertSame(
            '2026-07-28 09:00:00',
            $persisted->getAttribute('updated_at')?->format('Y-m-d H:i:s'),
        );
        $this->assertSame('reviewer', $persisted->getAttribute('name'));
        $this->assertSame('web', $persisted->getAttribute('guard_name'));
        $this->assertSame(
            'Reviews documents.',
            $persisted->getAttribute('description'),
        );
        $this->assertDatabaseCount('acl_access_audits', 0);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $table): array
    {
        return array_map(
            static fn (object $row): array => (array) $row,
            $this->app['db']
                ->table($table)
                ->orderBy('id')
                ->get()
                ->all(),
        );
    }

    private function audit(int|string|null $id): object
    {
        $audit = $this->app['db']
            ->table('acl_access_audits')
            ->where('id', $id)
            ->first();

        $this->assertNotNull($audit);

        return $audit;
    }

    private function clock(): FrozenClock
    {
        return new FrozenClock(
            new DateTimeImmutable('2026-07-29 12:00:00 UTC'),
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function command(
        Role|RoleReference|string|int $role,
        Model|SystemActor|null $actor = null,
        ?string $reason = null,
        array $metadata = [],
    ): DeactivateRoleCommand {
        return DeactivateRoleCommand::capture(
            role: $role,
            actor: $actor ?? SystemActor::named('definition-suite'),
            reason: $reason,
            metadata: $metadata,
            clock: $this->clock(),
        );
    }
}

final class MissingRoleDeactivationAccessAudit extends AccessAudit
{
    public function getTable(): string
    {
        return 'missing_role_deactivation_access_audits';
    }
}

final class DeactivateHandlerCustomRole extends Role {}

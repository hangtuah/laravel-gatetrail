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
use HangTuah\GateTrail\Mutations\Commands\RestoreRoleCommand;
use HangTuah\GateTrail\Mutations\Handlers\CreateRoleHandler;
use HangTuah\GateTrail\Mutations\Handlers\RestoreRoleHandler;
use HangTuah\GateTrail\Mutations\MutationReasonValidator;
use HangTuah\GateTrail\RoleReference;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Fixtures\ForcedRollback;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class RestoreRoleHandlerTest extends TestCase
{
    private const DEACTIVATED_SNAPSHOT = '{"id":"1","name":"reviewer",'
        .'"guard_name":"web","description":"Reviews documents.",'
        .'"deleted_at":"2026-07-29T12:00:00.000000Z"}';

    private const ACTIVE_SNAPSHOT = '{"id":"1","name":"reviewer",'
        .'"guard_name":"web","description":"Reviews documents.",'
        .'"deleted_at":null}';

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
            'updated_at' => '2026-07-29 12:00:00',
            'deleted_at' => '2026-07-29 12:00:00',
        ])->save();
    }

    public function test_handler_restores_with_canonical_audit_evidence(): void
    {
        $result = app(RestoreRoleHandler::class)->handle($this->command(
            role: $this->role,
            reason: 'Reinstated after review',
            metadata: ['ticket' => 'SEC-42'],
        ));

        $this->assertSame(MutationOutcome::Restored, $result->outcome);
        $this->assertTrue($result->changed());
        $this->assertNotNull($result->auditId);

        $persisted = $this->persisted();

        $this->assertNull($persisted->getAttribute('deleted_at'));
        $this->assertSame(
            '2026-07-30 08:00:00',
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

        $this->assertSame('role.restored', $audit->event);
        $this->assertSame('role', $audit->target_type);
        $this->assertSame(
            (string) $this->role->getKey(),
            (string) $audit->target_id,
        );
        $this->assertNull($audit->subject_type);
        $this->assertNull($audit->subject_id);
        $this->assertSame('gatetrail.system', $audit->actor_type);
        $this->assertSame('definition-suite', $audit->actor_id);
        $this->assertSame('Reinstated after review', $audit->reason);
        $this->assertSame(self::DEACTIVATED_SNAPSHOT, $audit->before);
        $this->assertSame(self::ACTIVE_SNAPSHOT, $audit->after);
        $this->assertSame(
            '{"schema_version":1,"guard_name":"web",'
            .'"role_name":"reviewer","metadata":{"ticket":"SEC-42"}}',
            $audit->context,
        );
        $this->assertSame('2026-07-30 08:00:00', $audit->created_at);
    }

    public function test_restored_role_is_visible_to_the_default_scope(): void
    {
        $result = app(RestoreRoleHandler::class)->handle(
            $this->command(role: $this->role),
        );

        $this->assertNull(
            $result->resource->getAttribute('deleted_at'),
        );
        $this->assertSame(
            1,
            Role::query()->whereKey($this->role->getKey())->count(),
        );
        $this->assertSame(1, Role::query()->withTrashed()->count());
    }

    public function test_every_public_input_form_restores_the_role(): void
    {
        foreach ([
            'model' => fn (Role $role): Role => $role,
            'integer' => static fn (Role $role): int => (int) $role->getKey(),
            'reference' => static fn (Role $role): RoleReference => RoleReference::id(
                $role->getKey(),
            ),
            'name' => static fn (Role $role): string => 'reviewer',
        ] as $label => $input) {
            $role = $this->freshDeactivatedRole();

            $result = app(RestoreRoleHandler::class)->handle(
                $this->command(role: $input($role)),
            );

            $this->assertSame(
                MutationOutcome::Restored,
                $result->outcome,
                "The [$label] input should restore the role.",
            );
            $this->assertNull(
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
        $apiRole->delete();
        config()->set('gatetrail.guards.default', ' ');

        $result = app(RestoreRoleHandler::class)->handle(
            $this->command(role: RoleReference::id($apiRole->getKey())),
        );

        $this->assertSame(MutationOutcome::Restored, $result->outcome);
        $this->assertSame(
            '{"schema_version":1,"guard_name":"api",'
            .'"role_name":"auditor","metadata":{}}',
            $this->audit($result->auditId)->context,
        );
    }

    public function test_human_actor_is_recorded(): void
    {
        $actor = TestSubject::query()->create(['name' => 'Admin']);

        $result = app(RestoreRoleHandler::class)->handle($this->command(
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

        foreach ([null, '   '] as $reason) {
            try {
                app(RestoreRoleHandler::class)->handle($this->command(
                    role: $this->role,
                    reason: $reason,
                ));

                $this->fail('A missing definition reason should be rejected.');
            } catch (ReasonRequired) {
                $this->assertRoleRemainsDeactivated();
            }
        }
    }

    public function test_sensitive_reason_is_redacted_in_the_audit_only(): void
    {
        config()->set('gatetrail.sensitive_fields', ['reason']);

        $result = app(RestoreRoleHandler::class)->handle($this->command(
            role: $this->role,
            reason: 'Reinstated after review',
        ));

        $this->assertSame(
            SensitiveMetadataRedactor::MARKER,
            $this->audit($result->auditId)->reason,
        );
    }

    public function test_invalid_metadata_fails_before_transaction(): void
    {
        try {
            app(RestoreRoleHandler::class)->handle($this->command(
                role: $this->role,
                metadata: ['handle' => fopen('php://memory', 'r')],
            ));

            $this->fail('Invalid metadata should be rejected.');
        } catch (InvalidAuditMetadata) {
            $this->assertRoleRemainsDeactivated();
        }
    }

    public function test_invalid_actor_fails_before_transaction(): void
    {
        try {
            app(RestoreRoleHandler::class)->handle($this->command(
                role: $this->role,
                actor: new TestSubject,
            ));

            $this->fail('An unpersisted actor should be rejected.');
        } catch (InvalidActor) {
            $this->assertRoleRemainsDeactivated();
        }
    }

    public function test_missing_role_is_rejected(): void
    {
        try {
            app(RestoreRoleHandler::class)->handle(
                $this->command(role: 4242),
            );

            $this->fail('A missing role should be rejected.');
        } catch (RoleNotFound $exception) {
            $this->assertSame('ID', $exception->referenceType);
            $this->assertNull($exception->guard);
            $this->assertRoleRemainsDeactivated();
        }
    }

    public function test_already_active_role_is_rejected(): void
    {
        app(RestoreRoleHandler::class)->handle(
            $this->command(role: $this->role),
        );

        try {
            app(RestoreRoleHandler::class)->handle(
                $this->command(role: RoleReference::id($this->role->getKey())),
            );

            $this->fail('An active role should be rejected.');
        } catch (RoleNotFound $exception) {
            $this->assertSame('web', $exception->guard);
            $this->assertSame('ID', $exception->referenceType);
        }

        $this->assertDatabaseCount('acl_access_audits', 1);
        $this->assertSame(
            '2026-07-30 08:00:00',
            $this->persisted()->getAttribute('updated_at')
                ?->format('Y-m-d H:i:s'),
        );
    }

    public function test_malformed_explicit_identifier_remains_safe(): void
    {
        try {
            app(RestoreRoleHandler::class)->handle(
                $this->command(role: RoleReference::id('not-an-integer')),
            );

            $this->fail('A malformed identifier should be rejected.');
        } catch (RoleNotFound $exception) {
            $this->assertSame('ID', $exception->referenceType);
            $this->assertStringNotContainsString(
                'not-an-integer',
                $exception->getMessage(),
            );
            $this->assertRoleRemainsDeactivated();
        }
    }

    public function test_handler_requires_strict_auditing(): void
    {
        config()->set('gatetrail.audit.strict', false);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires strict auditing');

        app(RestoreRoleHandler::class)->handle(
            $this->command(role: $this->role),
        );
    }

    public function test_audit_failure_leaves_the_role_deactivated(): void
    {
        config()->set(
            'gatetrail.models.access_audit',
            MissingRoleRestorationAccessAudit::class,
        );

        try {
            app(RestoreRoleHandler::class)->handle(
                $this->command(role: $this->role),
            );

            $this->fail('Audit persistence should fail.');
        } catch (AuditWriteFailed) {
            $this->assertRoleRemainsDeactivated();
        }
    }

    public function test_caller_owned_rollback_leaves_the_role_deactivated(): void
    {
        try {
            $this->app['db']->connection('testing')->transaction(
                function (): void {
                    $result = app(RestoreRoleHandler::class)->handle(
                        $this->command(role: $this->role),
                    );

                    $this->assertSame(
                        MutationOutcome::Restored,
                        $result->outcome,
                    );
                    $this->assertNull(
                        $this->persisted()->getAttribute('deleted_at'),
                    );
                    $this->assertDatabaseCount('acl_access_audits', 1);

                    throw new ForcedRollback(
                        'Roll back outer transaction.',
                    );
                },
            );

            $this->fail('The outer transaction should roll back.');
        } catch (ForcedRollback) {
            $this->assertRoleRemainsDeactivated();
        }
    }

    public function test_configured_role_subclass_remains_supported(): void
    {
        config()->set(
            'gatetrail.models.role',
            RestoreHandlerCustomRole::class,
        );

        $result = app(RestoreRoleHandler::class)->handle(
            $this->command(role: (int) $this->role->getKey()),
        );

        $this->assertInstanceOf(
            RestoreHandlerCustomRole::class,
            $result->resource,
        );
        $this->assertSame(MutationOutcome::Restored, $result->outcome);
        $this->assertSame(
            self::ACTIVE_SNAPSHOT,
            $this->audit($result->auditId)->after,
        );
    }

    public function test_no_eloquent_restoring_or_restored_event_is_emitted(): void
    {
        $observed = [];
        Role::restoring(static function () use (&$observed): void {
            $observed[] = 'restoring';
        });
        Role::restored(static function () use (&$observed): void {
            $observed[] = 'restored';
        });
        Role::updated(static function () use (&$observed): void {
            $observed[] = 'updated';
        });

        app(RestoreRoleHandler::class)->handle(
            $this->command(role: $this->role),
        );

        // `updated` proves the dispatcher is live, so the two missing
        // soft-delete events are a real absence rather than a dead listener.
        $this->assertSame(['updated'], $observed);
    }

    public function test_restore_reactivates_the_row_without_inserting_one(): void
    {
        $statements = [];
        $this->app['db']->connection('testing')->beforeExecuting(
            static function (string $query) use (&$statements): void {
                $statements[] = $query;
            },
        );

        app(RestoreRoleHandler::class)->handle(
            $this->command(role: $this->role),
        );

        $roleInserts = array_filter(
            $statements,
            static fn (string $query): bool => str_starts_with(
                $query,
                'insert',
            ) && str_contains($query, 'acl_roles'),
        );

        $this->assertSame([], $roleInserts);
        $this->assertSame(1, Role::query()->withTrashed()->count());
        $this->assertSame(
            $this->role->getKey(),
            Role::query()->withTrashed()->sole()->getKey(),
        );
    }

    public function test_restored_namespace_still_rejects_creation(): void
    {
        app(RestoreRoleHandler::class)->handle(
            $this->command(role: $this->role),
        );

        try {
            app(CreateRoleHandler::class)->handle(CreateRoleCommand::capture(
                name: 'reviewer',
                guard: 'web',
                actor: SystemActor::named('definition-suite'),
                reason: null,
                description: null,
                metadata: [],
                clock: $this->clock(),
            ));

            $this->fail('The restored namespace should remain occupied.');
        } catch (DuplicateRole) {
            $this->assertSame(1, Role::query()->withTrashed()->count());
            $this->assertDatabaseCount('acl_access_audits', 1);
        }
    }

    public function test_every_assignment_row_survives_restoration_unchanged(): void
    {
        $this->role->forceFill(['deleted_at' => null])->save();

        $keptSubject = TestSubject::query()->create(['name' => 'Kept']);
        $revokedSubject = TestSubject::query()->create(['name' => 'Revoked']);
        $keptPermission = Permission::query()->create([
            'name' => 'documents.review',
            'guard_name' => 'web',
            'description' => null,
        ]);
        $revokedPermission = Permission::query()->create([
            'name' => 'documents.publish',
            'guard_name' => 'web',
            'description' => null,
        ]);
        $assignments = app(AssignmentManager::class);
        $actor = SystemActor::named('definition-suite');

        $assignments->assignRole(
            subject: $keptSubject,
            role: $this->role,
            actor: $actor,
            reason: 'Assigned by administrator',
        );
        $revokedAssignment = $assignments->assignRole(
            subject: $revokedSubject,
            role: $this->role,
            actor: $actor,
            reason: 'Assigned by administrator',
        )->resource;
        $assignments->revokeRole(
            assignment: $revokedAssignment,
            actor: $actor,
            reason: 'Transferred to another unit',
        );

        $assignments->grantPermissionToRole(
            role: $this->role,
            permission: $keptPermission,
            actor: $actor,
            reason: 'Granted by administrator',
        );
        $revokedGrant = $assignments->grantPermissionToRole(
            role: $this->role,
            permission: $revokedPermission,
            actor: $actor,
            reason: 'Granted by administrator',
        )->resource;
        $assignments->revokePermissionFromRole(
            assignment: $revokedGrant,
            actor: $actor,
            reason: 'No longer required',
        );

        $this->role->forceFill([
            'deleted_at' => '2026-07-29 12:00:00',
        ])->save();

        $before = [
            'roles' => $this->rows('acl_role_assignments'),
            'permissions' => $this->rows('acl_role_permissions'),
        ];

        $result = app(RestoreRoleHandler::class)->handle(
            $this->command(role: $this->role),
        );

        $this->assertSame(MutationOutcome::Restored, $result->outcome);
        $this->assertSame(
            $before['roles'],
            $this->rows('acl_role_assignments'),
        );
        $this->assertSame(
            $before['permissions'],
            $this->rows('acl_role_permissions'),
        );
        $this->assertDatabaseCount('acl_role_assignments', 2);
        $this->assertDatabaseCount('acl_role_permissions', 2);

        $revokedRow = $this->rows('acl_role_assignments')[1];
        $revokedGrantRow = $this->rows('acl_role_permissions')[1];

        $this->assertNotNull($revokedRow['revoked_at']);
        $this->assertSame(
            'Transferred to another unit',
            $revokedRow['revocation_reason'],
        );
        $this->assertSame('gatetrail.system', $revokedRow['revoked_by_type']);
        $this->assertSame('definition-suite', $revokedRow['revoked_by_id']);
        $this->assertNotNull($revokedGrantRow['revoked_at']);
        $this->assertSame(
            'No longer required',
            $revokedGrantRow['revocation_reason'],
        );
        $this->assertNull($this->rows('acl_role_assignments')[0]['revoked_at']);
        $this->assertNull($this->rows('acl_role_permissions')[0]['revoked_at']);
    }

    private function freshDeactivatedRole(): Role
    {
        Role::query()->withTrashed()->forceDelete();

        $role = Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'web',
            'description' => 'Reviews documents.',
        ]);
        $role->forceFill([
            'deleted_at' => '2026-07-29 12:00:00',
        ])->save();

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

    private function assertRoleRemainsDeactivated(): void
    {
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
            new DateTimeImmutable('2026-07-30 08:00:00 UTC'),
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
    ): RestoreRoleCommand {
        return RestoreRoleCommand::capture(
            role: $role,
            actor: $actor ?? SystemActor::named('definition-suite'),
            reason: $reason,
            metadata: $metadata,
            clock: $this->clock(),
        );
    }
}

final class MissingRoleRestorationAccessAudit extends AccessAudit
{
    public function getTable(): string
    {
        return 'missing_role_restoration_access_audits';
    }
}

final class RestoreHandlerCustomRole extends Role {}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use DateTimeImmutable;
use HangTuah\GateTrail\Audit\SensitiveMetadataRedactor;
use HangTuah\GateTrail\Contracts\AssignmentManager;
use HangTuah\GateTrail\Exceptions\AuditWriteFailed;
use HangTuah\GateTrail\Exceptions\DuplicatePermission;
use HangTuah\GateTrail\Exceptions\InvalidActor;
use HangTuah\GateTrail\Exceptions\InvalidAuditMetadata;
use HangTuah\GateTrail\Exceptions\InvalidGuard;
use HangTuah\GateTrail\Exceptions\PermissionNotFound;
use HangTuah\GateTrail\Exceptions\ReasonRequired;
use HangTuah\GateTrail\Models\AccessAudit;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\Mutations\Commands\CreatePermissionCommand;
use HangTuah\GateTrail\Mutations\Commands\DeactivatePermissionCommand;
use HangTuah\GateTrail\Mutations\Handlers\CreatePermissionHandler;
use HangTuah\GateTrail\Mutations\Handlers\DeactivatePermissionHandler;
use HangTuah\GateTrail\Mutations\MutationReasonValidator;
use HangTuah\GateTrail\PermissionReference;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Fixtures\ForcedRollback;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class DeactivatePermissionHandlerTest extends TestCase
{
    private const ACTIVE_SNAPSHOT = '{"id":"1","name":"documents.approve",'
        .'"guard_name":"web","description":"Approve submitted documents",'
        .'"deleted_at":null}';

    private const DEACTIVATED_SNAPSHOT = '{"id":"1","name":"documents.approve",'
        .'"guard_name":"web","description":"Approve submitted documents",'
        .'"deleted_at":"2026-07-29T12:00:00.000000Z"}';

    private Permission $permission;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);
        $this->permission = Permission::query()->create([
            'name' => 'documents.approve',
            'guard_name' => 'web',
            'description' => 'Approve submitted documents',
        ]);
        $this->permission->forceFill([
            'created_at' => '2026-07-28 09:00:00',
            'updated_at' => '2026-07-28 09:00:00',
        ])->save();
    }

    public function test_handler_deactivates_with_canonical_audit_evidence(): void
    {
        $result = app(DeactivatePermissionHandler::class)->handle(
            $this->command(
                permission: $this->permission,
                reason: 'Superseded by a narrower permission',
                metadata: ['ticket' => 'SEC-42'],
            ),
        );

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
        $this->assertSame(
            'documents.approve',
            $persisted->getAttribute('name'),
        );
        $this->assertSame('web', $persisted->getAttribute('guard_name'));
        $this->assertSame(
            'Approve submitted documents',
            $persisted->getAttribute('description'),
        );
        $this->assertSame(
            '2026-07-28 09:00:00',
            $persisted->getAttribute('created_at')?->format('Y-m-d H:i:s'),
        );

        $audit = $this->audit($result->auditId);

        $this->assertSame('permission.deactivated', $audit->event);
        $this->assertSame('permission', $audit->target_type);
        $this->assertSame(
            (string) $this->permission->getKey(),
            (string) $audit->target_id,
        );
        $this->assertNull($audit->subject_type);
        $this->assertNull($audit->subject_id);
        $this->assertSame('gatetrail.system', $audit->actor_type);
        $this->assertSame('definition-suite', $audit->actor_id);
        $this->assertSame(
            'Superseded by a narrower permission',
            $audit->reason,
        );
        $this->assertSame(self::ACTIVE_SNAPSHOT, $audit->before);
        $this->assertSame(self::DEACTIVATED_SNAPSHOT, $audit->after);
        $this->assertSame(
            '{"schema_version":1,"guard_name":"web",'
            .'"permission_name":"documents.approve",'
            .'"metadata":{"ticket":"SEC-42"}}',
            $audit->context,
        );
        $this->assertSame('2026-07-29 12:00:00', $audit->created_at);
    }

    public function test_only_the_deletion_state_differs_between_snapshots(): void
    {
        $result = app(DeactivatePermissionHandler::class)->handle(
            $this->command(permission: $this->permission),
        );
        $audit = $this->audit($result->auditId);
        $before = $this->decode((string) $audit->before);
        $after = $this->decode((string) $audit->after);
        $order = ['id', 'name', 'guard_name', 'description', 'deleted_at'];

        $this->assertSame($order, array_keys($before));
        $this->assertSame($order, array_keys($after));

        foreach (['id', 'name', 'guard_name', 'description'] as $field) {
            $this->assertSame(
                $before[$field],
                $after[$field],
                "Field [$field] must not change.",
            );
        }

        $this->assertNull($before['deleted_at']);
        $this->assertSame('2026-07-29T12:00:00.000000Z', $after['deleted_at']);
    }

    public function test_the_returned_resource_is_the_deactivated_permission(): void
    {
        $result = app(DeactivatePermissionHandler::class)->handle(
            $this->command(permission: $this->permission),
        );

        $this->assertSame(
            $this->permission->getKey(),
            $result->resource->getKey(),
        );
        $this->assertSame(
            '2026-07-29 12:00:00',
            $result->resource->getAttribute('deleted_at')
                ?->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            0,
            Permission::query()
                ->whereKey($this->permission->getKey())
                ->count(),
        );
        $this->assertSame(
            1,
            Permission::query()
                ->withTrashed()
                ->whereKey($this->permission->getKey())
                ->count(),
        );
    }

    public function test_every_accepted_input_form_deactivates_the_permission(): void
    {
        $inputs = [
            'model' => static fn (Permission $permission): Permission => $permission,
            'integer' => static fn (Permission $permission): int => (int) $permission
                ->getKey(),
            'reference' => static fn (Permission $permission): PermissionReference => PermissionReference::id(
                $permission->getKey(),
            ),
            'string reference' => static fn (Permission $permission): PermissionReference => PermissionReference::id(
                (string) $permission->getKey(),
            ),
            'name' => static fn (Permission $permission): string => 'documents.approve',
        ];

        foreach ($inputs as $label => $input) {
            $permission = $this->freshActivePermission();

            $result = app(DeactivatePermissionHandler::class)->handle(
                $this->command(permission: $input($permission)),
            );

            $this->assertSame(
                MutationOutcome::Deactivated,
                $result->outcome,
                "The [$label] input should deactivate the permission.",
            );
            $this->assertNotNull(
                Permission::query()
                    ->withTrashed()
                    ->whereKey($permission->getKey())
                    ->first()
                    ?->getAttribute('deleted_at'),
            );
        }
    }

    public function test_a_stale_model_is_reloaded_before_deactivation(): void
    {
        $this->permission->setAttribute(
            'description',
            'Stale in-memory value',
        );

        $result = app(DeactivatePermissionHandler::class)->handle(
            $this->command(permission: $this->permission),
        );

        $this->assertNotSame($this->permission, $result->resource);
        $this->assertSame(
            'Approve submitted documents',
            $this->persisted()->getAttribute('description'),
        );
        $this->assertSame(
            self::ACTIVE_SNAPSHOT,
            $this->audit($result->auditId)->before,
        );
    }

    public function test_explicit_inputs_do_not_depend_on_the_default_guard(): void
    {
        $apiPermission = Permission::query()->create([
            'name' => 'documents.reject',
            'guard_name' => 'api',
            'description' => null,
        ]);
        config()->set('gatetrail.guards.default', ' ');

        $result = app(DeactivatePermissionHandler::class)->handle(
            $this->command(
                permission: PermissionReference::id($apiPermission->getKey()),
            ),
        );

        $this->assertSame(MutationOutcome::Deactivated, $result->outcome);
        $this->assertSame(
            '{"schema_version":1,"guard_name":"api",'
            .'"permission_name":"documents.reject","metadata":{}}',
            $this->audit($result->auditId)->context,
        );
        $this->assertNull($this->persisted()->getAttribute('deleted_at'));
    }

    public function test_explicit_inputs_survive_a_missing_laravel_default_guard(): void
    {
        config()->set('gatetrail.guards.default');
        config()->set('auth.defaults.guard', '');

        $result = app(DeactivatePermissionHandler::class)->handle(
            $this->command(
                permission: (int) $this->permission->getKey(),
            ),
        );

        $this->assertSame(MutationOutcome::Deactivated, $result->outcome);
    }

    public function test_string_name_uses_the_resolved_default_guard(): void
    {
        config()->set('gatetrail.guards.default', 'api');
        $apiPermission = Permission::query()->create([
            'name' => 'documents.approve',
            'guard_name' => 'api',
            'description' => null,
        ]);

        app(DeactivatePermissionHandler::class)->handle(
            $this->command(permission: 'documents.approve'),
        );

        $this->assertNotNull(
            Permission::query()
                ->withTrashed()
                ->whereKey($apiPermission->getKey())
                ->first()
                ?->getAttribute('deleted_at'),
        );
        $this->assertNull($this->persisted()->getAttribute('deleted_at'));
    }

    public function test_string_name_still_requires_a_valid_default_guard(): void
    {
        config()->set('gatetrail.guards.default', ' ');

        try {
            app(DeactivatePermissionHandler::class)->handle(
                $this->command(permission: 'documents.approve'),
            );

            $this->fail('An invalid default guard should be rejected.');
        } catch (InvalidGuard $exception) {
            $this->assertSame(
                'permission definition',
                $exception->contextType,
            );
            $this->assertSame('package default', $exception->source);
            $this->assertPermissionRemainsActive();
        }
    }

    public function test_human_actor_is_recorded(): void
    {
        $actor = TestSubject::query()->create(['name' => 'Admin']);

        $result = app(DeactivatePermissionHandler::class)->handle(
            $this->command(permission: $this->permission, actor: $actor),
        );
        $audit = $this->audit($result->auditId);

        $this->assertSame($actor->getMorphClass(), $audit->actor_type);
        $this->assertSame((string) $actor->getKey(), $audit->actor_id);
        $this->assertNull($audit->reason);
    }

    public function test_blank_reason_normalizes_to_null_when_not_required(): void
    {
        $result = app(DeactivatePermissionHandler::class)->handle(
            $this->command(permission: $this->permission, reason: '   '),
        );

        $this->assertNull($this->audit($result->auditId)->reason);
    }

    public function test_required_definition_reason_fails_before_transaction(): void
    {
        config()->set('gatetrail.definitions.require_reason', true);
        app()->forgetInstance(MutationReasonValidator::class);

        foreach ([null, '   '] as $reason) {
            try {
                app(DeactivatePermissionHandler::class)->handle(
                    $this->command(
                        permission: $this->permission,
                        reason: $reason,
                    ),
                );

                $this->fail('A missing definition reason should be rejected.');
            } catch (ReasonRequired) {
                $this->assertPermissionRemainsActive();
            }
        }
    }

    public function test_sensitive_reason_is_redacted_in_the_audit(): void
    {
        config()->set('gatetrail.sensitive_fields', ['reason']);

        $result = app(DeactivatePermissionHandler::class)->handle(
            $this->command(
                permission: $this->permission,
                reason: 'Confidential justification',
            ),
        );

        $this->assertSame(
            SensitiveMetadataRedactor::MARKER,
            $this->audit($result->auditId)->reason,
        );
    }

    public function test_invalid_metadata_fails_before_transaction(): void
    {
        try {
            app(DeactivatePermissionHandler::class)->handle($this->command(
                permission: $this->permission,
                metadata: ['handle' => fopen('php://memory', 'r')],
            ));

            $this->fail('Invalid metadata should be rejected.');
        } catch (InvalidAuditMetadata) {
            $this->assertPermissionRemainsActive();
        }
    }

    public function test_invalid_actor_fails_before_transaction(): void
    {
        try {
            app(DeactivatePermissionHandler::class)->handle($this->command(
                permission: $this->permission,
                actor: new TestSubject,
            ));

            $this->fail('An unpersisted actor should be rejected.');
        } catch (InvalidActor) {
            $this->assertPermissionRemainsActive();
        }
    }

    public function test_missing_permission_is_rejected(): void
    {
        try {
            app(DeactivatePermissionHandler::class)->handle(
                $this->command(permission: 4242),
            );

            $this->fail('A missing permission should be rejected.');
        } catch (PermissionNotFound $exception) {
            $this->assertSame('ID', $exception->referenceType);
            $this->assertNull($exception->guard);
            $this->assertPermissionRemainsActive();
        }
    }

    public function test_unpersisted_model_is_rejected(): void
    {
        try {
            app(DeactivatePermissionHandler::class)->handle(
                $this->command(permission: new Permission),
            );

            $this->fail('An unpersisted model should be rejected.');
        } catch (PermissionNotFound $exception) {
            $this->assertSame('model', $exception->referenceType);
            $this->assertNull($exception->guard);
            $this->assertPermissionRemainsActive();
        }
    }

    public function test_already_deactivated_permission_is_rejected(): void
    {
        app(DeactivatePermissionHandler::class)->handle(
            $this->command(permission: $this->permission),
        );

        try {
            app(DeactivatePermissionHandler::class)->handle(
                $this->command(
                    permission: PermissionReference::id(
                        $this->permission->getKey(),
                    ),
                ),
            );

            $this->fail('A deactivated permission should be rejected.');
        } catch (PermissionNotFound $exception) {
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
        foreach ([' 1', '1 ', '+1', '-1', '1.0', '1e3', 'abc'] as $id) {
            try {
                app(DeactivatePermissionHandler::class)->handle(
                    $this->command(
                        permission: PermissionReference::id($id),
                    ),
                );

                $this->fail(sprintf(
                    'Identifier [%s] should be rejected.',
                    $id,
                ));
            } catch (PermissionNotFound $exception) {
                $this->assertSame('ID', $exception->referenceType);
                $this->assertNull($exception->guard);
                $this->assertStringNotContainsString(
                    $id,
                    $exception->getMessage(),
                );
            }
        }

        $this->assertPermissionRemainsActive();
    }

    public function test_handler_requires_strict_auditing(): void
    {
        foreach (['gatetrail.audit.enabled', 'gatetrail.audit.strict'] as $key) {
            config()->set('gatetrail.audit.enabled', true);
            config()->set('gatetrail.audit.strict', true);
            config()->set($key, false);

            try {
                app(DeactivatePermissionHandler::class)->handle(
                    $this->command(permission: $this->permission),
                );

                $this->fail('Strict auditing should be required.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString(
                    'requires strict auditing',
                    $exception->getMessage(),
                );
                $this->assertPermissionRemainsActive();
            }
        }
    }

    public function test_audit_failure_restores_the_active_permission(): void
    {
        config()->set(
            'gatetrail.models.access_audit',
            MissingPermissionDeactivationAccessAudit::class,
        );

        try {
            app(DeactivatePermissionHandler::class)->handle(
                $this->command(permission: $this->permission),
            );

            $this->fail('Audit persistence should fail.');
        } catch (AuditWriteFailed) {
            $this->assertPermissionRemainsActive();
        }
    }

    public function test_caller_owned_rollback_restores_the_permission_and_audit(): void
    {
        try {
            $this->app['db']->connection('testing')->transaction(
                function (): void {
                    $result = app(DeactivatePermissionHandler::class)->handle(
                        $this->command(permission: $this->permission),
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
            $this->assertPermissionRemainsActive();
        }
    }

    public function test_configured_permission_subclass_remains_supported(): void
    {
        config()->set(
            'gatetrail.models.permission',
            DeactivateHandlerCustomPermission::class,
        );

        $result = app(DeactivatePermissionHandler::class)->handle(
            $this->command(permission: (int) $this->permission->getKey()),
        );

        $this->assertInstanceOf(
            DeactivateHandlerCustomPermission::class,
            $result->resource,
        );
        $this->assertSame(MutationOutcome::Deactivated, $result->outcome);
        $this->assertSame(
            self::DEACTIVATED_SNAPSHOT,
            $this->audit($result->auditId)->after,
        );
    }

    public function test_every_assignment_row_survives_deactivation_unchanged(): void
    {
        $holder = TestSubject::query()->create(['name' => 'Holder']);
        $revokedHolder = TestSubject::query()->create(['name' => 'Revoked']);
        $role = Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'web',
            'description' => null,
        ]);
        $revokedRole = Role::query()->create([
            'name' => 'auditor',
            'guard_name' => 'web',
            'description' => null,
        ]);
        $assignments = app(AssignmentManager::class);
        $actor = SystemActor::named('definition-suite');

        $direct = $assignments->grantDirectPermission(
            subject: $holder,
            permission: $this->permission,
            actor: $actor,
            reason: 'Grant approval capability',
        )->resource;
        $revokedDirect = $assignments->grantDirectPermission(
            subject: $revokedHolder,
            permission: $this->permission,
            actor: $actor,
            reason: 'Grant approval capability',
        )->resource;
        $assignments->revokeDirectPermission(
            assignment: $revokedDirect,
            actor: $actor,
            reason: 'No longer required',
        );
        $rolePermission = $assignments->grantPermissionToRole(
            role: $role,
            permission: $this->permission,
            actor: $actor,
            reason: 'Grant approval capability',
        )->resource;
        $revokedRolePermission = $assignments->grantPermissionToRole(
            role: $revokedRole,
            permission: $this->permission,
            actor: $actor,
            reason: 'Grant approval capability',
        )->resource;
        $assignments->revokePermissionFromRole(
            assignment: $revokedRolePermission,
            actor: $actor,
            reason: 'No longer required',
        );

        $before = [
            'permission_assignments' => $this->rows(
                'acl_permission_assignments',
            ),
            'role_permissions' => $this->rows('acl_role_permissions'),
        ];
        $auditsBefore = $this->rows('acl_access_audits');

        app(DeactivatePermissionHandler::class)->handle(
            $this->command(permission: $this->permission),
        );

        $this->assertSame(
            $before['permission_assignments'],
            $this->rows('acl_permission_assignments'),
        );
        $this->assertSame(
            $before['role_permissions'],
            $this->rows('acl_role_permissions'),
        );
        $this->assertDatabaseCount('acl_permission_assignments', 2);
        $this->assertDatabaseCount('acl_role_permissions', 2);

        // Existing audit history is appended to, never rewritten.
        $this->assertSame(
            $auditsBefore,
            array_slice($this->rows('acl_access_audits'), 0, count($auditsBefore)),
        );

        $this->assertNull($direct->fresh()?->getAttribute('revoked_at'));
        $this->assertNull($rolePermission->fresh()?->getAttribute('revoked_at'));
        $this->assertNotNull(
            $revokedDirect->fresh()?->getAttribute('revoked_at'),
        );
        $this->assertNotNull(
            $revokedRolePermission->fresh()?->getAttribute('revoked_at'),
        );
        $this->assertSame(
            (string) $this->permission->getKey(),
            (string) $direct->fresh()?->getAttribute('permission_id'),
        );
    }

    public function test_deactivated_namespace_still_rejects_creation(): void
    {
        app(DeactivatePermissionHandler::class)->handle(
            $this->command(permission: $this->permission),
        );

        try {
            app(CreatePermissionHandler::class)->handle(
                CreatePermissionCommand::capture(
                    name: 'documents.approve',
                    guard: 'web',
                    actor: SystemActor::named('definition-suite'),
                    reason: null,
                    description: null,
                    metadata: [],
                    clock: $this->clock(),
                ),
            );

            $this->fail('A deactivated namespace should remain occupied.');
        } catch (DuplicatePermission) {
            $this->assertSame(
                1,
                Permission::query()->withTrashed()->count(),
            );
            $this->assertDatabaseCount('acl_access_audits', 1);
        }
    }

    private function freshActivePermission(): Permission
    {
        Permission::query()->withTrashed()->forceDelete();

        return Permission::query()->create([
            'name' => 'documents.approve',
            'guard_name' => 'web',
            'description' => 'Approve submitted documents',
        ]);
    }

    private function persisted(): Permission
    {
        $permission = Permission::query()
            ->withTrashed()
            ->whereKey($this->permission->getKey())
            ->first();

        $this->assertInstanceOf(Permission::class, $permission);

        return $permission;
    }

    private function assertPermissionRemainsActive(): void
    {
        $persisted = $this->persisted();

        $this->assertNull($persisted->getAttribute('deleted_at'));
        $this->assertSame(
            '2026-07-28 09:00:00',
            $persisted->getAttribute('updated_at')?->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            'documents.approve',
            $persisted->getAttribute('name'),
        );
        $this->assertSame('web', $persisted->getAttribute('guard_name'));
        $this->assertSame(
            'Approve submitted documents',
            $persisted->getAttribute('description'),
        );
        $this->assertDatabaseCount('acl_access_audits', 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $snapshot): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
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

        $this->assertIsObject($audit);

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
        Permission|PermissionReference|string|int $permission,
        Model|SystemActor|null $actor = null,
        ?string $reason = null,
        array $metadata = [],
    ): DeactivatePermissionCommand {
        return DeactivatePermissionCommand::capture(
            permission: $permission,
            actor: $actor ?? SystemActor::named('definition-suite'),
            reason: $reason,
            metadata: $metadata,
            clock: $this->clock(),
        );
    }
}

final class MissingPermissionDeactivationAccessAudit extends AccessAudit
{
    public function getTable(): string
    {
        return 'missing_permission_deactivation_access_audits';
    }
}

final class DeactivateHandlerCustomPermission extends Permission {}

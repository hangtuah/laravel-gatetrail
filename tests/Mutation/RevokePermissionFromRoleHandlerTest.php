<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use DateTimeImmutable;
use HangTuah\GateTrail\AssignmentReference;
use HangTuah\GateTrail\Exceptions\AssignmentNotActive;
use HangTuah\GateTrail\Exceptions\AuditWriteFailed;
use HangTuah\GateTrail\Models\AccessAudit;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\Models\RolePermission;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\Mutations\Commands\RevokePermissionFromRoleCommand;
use HangTuah\GateTrail\Mutations\Handlers\RevokePermissionFromRoleHandler;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Fixtures\ForcedRollback;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use HangTuah\GateTrail\Tests\TestCase;

final class RevokePermissionFromRoleHandlerTest extends TestCase
{
    private Role $role;

    private Permission $permission;

    private RolePermission $assignment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);
        $this->role = Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'web',
            'description' => null,
        ]);
        $this->permission = Permission::query()->create([
            'name' => 'documents.approve',
            'guard_name' => 'web',
        ]);
        $this->assignment = RolePermission::query()->create([
            'role_id' => $this->role->getKey(),
            'permission_id' => $this->permission->getKey(),
            'assigned_at' => '2026-07-29 10:00:00',
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'assignment-suite',
            'assignment_reason' => 'Initial grant',
            'starts_at' => null,
            'expires_at' => null,
        ]);
    }

    public function test_handler_revokes_assignment_with_exact_audit_snapshots(): void
    {
        $result = app(RevokePermissionFromRoleHandler::class)->handle(
            $this->command(
                $this->assignment,
                ['ticket' => 'SEC-42'],
            ),
        );

        $this->assertSame(MutationOutcome::Revoked, $result->outcome);
        $this->assertNotNull($result->auditId);
        $this->assertSame(
            '2026-07-29 12:00:00',
            $result->resource->getAttribute('revoked_at')
                ?->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            'gatetrail.system',
            $result->resource->getAttribute('revoked_by_type'),
        );
        $this->assertSame(
            'revocation-suite',
            $result->resource->getAttribute('revoked_by_id'),
        );
        $this->assertSame(
            'Role no longer approves documents',
            $result->resource->getAttribute('revocation_reason'),
        );

        $audit = $this->app['db']
            ->table('acl_access_audits')
            ->where('id', $result->auditId)
            ->first();
        $before = json_decode(
            $audit->before,
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $after = json_decode(
            $audit->after,
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $context = json_decode(
            $audit->context,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('permission.revoked_from_role', $audit->event);
        $this->assertSame('role_permission', $audit->target_type);
        $this->assertNull($audit->subject_type);
        $this->assertNull($audit->subject_id);
        $this->assertNull($before['revoked_at']);
        $this->assertNull($before['revoked_by_type']);
        $this->assertNull($before['revocation_reason']);
        $this->assertSame(
            '2026-07-29T12:00:00.000000Z',
            $after['revoked_at'],
        );
        $this->assertSame(
            'Role no longer approves documents',
            $after['revocation_reason'],
        );
        $this->assertSame('web', $context['guard_name']);
        $this->assertSame('reviewer', $context['role_name']);
        $this->assertSame(
            'documents.approve',
            $context['permission_name'],
        );
        $this->assertSame(['ticket' => 'SEC-42'], $context['metadata']);
    }

    public function test_scheduled_assignment_can_be_cancelled_before_start(): void
    {
        $this->assignment->forceFill([
            'starts_at' => '2026-08-01 08:00:00',
            'expires_at' => '2026-09-01 08:00:00',
        ])->save();

        $result = app(RevokePermissionFromRoleHandler::class)->handle(
            $this->command(AssignmentReference::id(
                $this->assignment->getKey(),
            )),
        );

        $this->assertSame(MutationOutcome::Revoked, $result->outcome);
    }

    public function test_expired_assignment_is_not_revocable(): void
    {
        $this->assignment->forceFill([
            'expires_at' => '2026-07-29 11:59:59',
        ])->save();

        $this->expectException(AssignmentNotActive::class);

        app(RevokePermissionFromRoleHandler::class)->handle(
            $this->command($this->assignment->getKey()),
        );
    }

    public function test_repeated_revocation_is_not_active(): void
    {
        $handler = app(RevokePermissionFromRoleHandler::class);
        $handler->handle($this->command($this->assignment));

        $this->expectException(AssignmentNotActive::class);

        $handler->handle($this->command(
            (string) $this->assignment->getKey(),
        ));
    }

    public function test_audit_failure_rolls_back_revocation(): void
    {
        config()->set(
            'gatetrail.models.access_audit',
            MissingRolePermissionRevokeAccessAudit::class,
        );

        try {
            app(RevokePermissionFromRoleHandler::class)->handle(
                $this->command($this->assignment),
            );

            $this->fail('Audit persistence should fail.');
        } catch (AuditWriteFailed) {
            $this->assertNull(
                $this->assignment->fresh()?->getAttribute('revoked_at'),
            );
            $this->assertDatabaseCount('acl_access_audits', 0);
        }
    }

    public function test_caller_owned_rollback_removes_revocation_and_audit(): void
    {
        $connection = $this->app['db']->connection('testing');

        try {
            $connection->transaction(function (): void {
                $result = app(RevokePermissionFromRoleHandler::class)
                    ->handle($this->command($this->assignment));

                $this->assertSame(
                    MutationOutcome::Revoked,
                    $result->outcome,
                );
                $this->assertNotNull(
                    $this->assignment->fresh()
                        ?->getAttribute('revoked_at'),
                );
                $this->assertDatabaseCount('acl_access_audits', 1);

                throw new ForcedRollback('Roll back outer transaction.');
            });

            $this->fail('The outer transaction should roll back.');
        } catch (ForcedRollback) {
            $this->assertNull(
                $this->assignment->fresh()?->getAttribute('revoked_at'),
            );
            $this->assertDatabaseCount('acl_access_audits', 0);
        }
    }

    public function test_soft_deleted_definitions_preserve_revocation_and_historical_names(): void
    {
        $this->role->delete();
        $this->permission->delete();

        $result = app(RevokePermissionFromRoleHandler::class)->handle(
            $this->command($this->assignment),
        );
        $audit = $this->app['db']
            ->table('acl_access_audits')
            ->where('id', $result->auditId)
            ->first();
        $context = json_decode(
            $audit->context,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame(MutationOutcome::Revoked, $result->outcome);
        $this->assertSame('web', $context['guard_name']);
        $this->assertSame('reviewer', $context['role_name']);
        $this->assertSame(
            'documents.approve',
            $context['permission_name'],
        );
    }

    public function test_assignment_expiring_exactly_at_request_time_is_expired(): void
    {
        $this->assignment->forceFill([
            'expires_at' => '2026-07-29 12:00:00',
        ])->save();

        $this->expectException(AssignmentNotActive::class);

        app(RevokePermissionFromRoleHandler::class)->handle(
            $this->command($this->assignment),
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function command(
        RolePermission|AssignmentReference|string|int $assignment,
        array $metadata = [],
    ): RevokePermissionFromRoleCommand {
        return RevokePermissionFromRoleCommand::capture(
            assignment: $assignment,
            actor: SystemActor::named('revocation-suite'),
            reason: 'Role no longer approves documents',
            metadata: $metadata,
            clock: new FrozenClock(
                new DateTimeImmutable('2026-07-29 12:00:00 UTC'),
            ),
        );
    }
}

final class MissingRolePermissionRevokeAccessAudit extends AccessAudit
{
    public function getTable(): string
    {
        return 'missing_role_permission_revoke_access_audits';
    }
}

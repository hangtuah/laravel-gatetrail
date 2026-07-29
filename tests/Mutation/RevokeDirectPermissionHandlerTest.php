<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use DateTimeImmutable;
use HangTuah\GateTrail\Exceptions\AssignmentNotActive;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\PermissionAssignment;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\Mutations\Commands\RevokeDirectPermissionCommand;
use HangTuah\GateTrail\Mutations\Handlers\RevokeDirectPermissionHandler;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Fixtures\ForcedRollback;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;

final class RevokeDirectPermissionHandlerTest extends TestCase
{
    private Permission $permission;

    private PermissionAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--database' => 'testing']);
        $s = TestSubject::query()->create(['name' => 'x']);
        $this->permission = Permission::query()->create(['name' => 'documents.approve', 'guard_name' => 'web']);
        $this->assignment = PermissionAssignment::query()->create(['subject_type' => $s->getMorphClass(), 'subject_id' => $s->getKey(), 'permission_id' => $this->permission->getKey(), 'assigned_at' => '2026-07-29 10:00:00', 'assigned_by_type' => 'gatetrail.system', 'assigned_by_id' => 'x']);
    }

    public function test_revokes_with_audit(): void
    {
        $r = app(RevokeDirectPermissionHandler::class)->handle($this->command());
        $this->assertSame(MutationOutcome::Revoked, $r->outcome);
        $this->assertDatabaseHas('acl_access_audits', ['event' => 'permission.revoked_from_subject']);
    }

    public function test_soft_deleted_permission_preserves_revocation_and_historical_names(): void
    {
        $this->permission->delete();
        $r = app(RevokeDirectPermissionHandler::class)->handle($this->command());
        $audit = $this->app['db']->table('acl_access_audits')->where('id', $r->auditId)->first();
        $context = json_decode($audit->context, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(MutationOutcome::Revoked, $r->outcome);
        $this->assertSame('web', $context['guard_name']);
        $this->assertSame('documents.approve', $context['permission_name']);
    }

    public function test_assignment_expiring_exactly_at_request_time_is_expired(): void
    {
        $this->assignment->forceFill(['expires_at' => '2026-07-29 12:00:00'])->save();
        $this->expectException(AssignmentNotActive::class);
        app(RevokeDirectPermissionHandler::class)->handle($this->command());
    }

    public function test_caller_owned_rollback_removes_revocation_and_audit(): void
    {
        try {
            $this->app['db']->connection('testing')->transaction(function (): void {
                $r = app(RevokeDirectPermissionHandler::class)->handle($this->command());
                $this->assertSame(MutationOutcome::Revoked, $r->outcome);
                $this->assertNotNull($this->assignment->fresh()?->getAttribute('revoked_at'));
                $this->assertDatabaseCount('acl_access_audits', 1);
                throw new ForcedRollback('Roll back outer transaction.');
            });
            $this->fail('The outer transaction should roll back.');
        } catch (ForcedRollback) {
            $this->assertNull($this->assignment->fresh()?->getAttribute('revoked_at'));
            $this->assertDatabaseCount('acl_access_audits', 0);
        }
    }

    private function command(): RevokeDirectPermissionCommand
    {
        return RevokeDirectPermissionCommand::capture($this->assignment, SystemActor::named('admin'), 'Remove', [], new FrozenClock(new DateTimeImmutable('2026-07-29 12:00:00 UTC')));
    }
}

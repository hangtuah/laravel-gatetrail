<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use DateTimeImmutable;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\PermissionAssignment;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\Mutations\Commands\RevokeDirectPermissionCommand;
use HangTuah\GateTrail\Mutations\Handlers\RevokeDirectPermissionHandler;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;

final class RevokeDirectPermissionHandlerTest extends TestCase
{
    public function test_revokes_with_audit(): void
    {
        $this->artisan('migrate', ['--database' => 'testing']);
        $s = TestSubject::query()->create(['name' => 'x']);
        $p = Permission::query()->create(['name' => 'x', 'guard_name' => 'web']);
        $a = PermissionAssignment::query()->create(['subject_type' => $s->getMorphClass(), 'subject_id' => $s->getKey(), 'permission_id' => $p->getKey(), 'assigned_at' => '2026-07-29 10:00:00', 'assigned_by_type' => 'gatetrail.system', 'assigned_by_id' => 'x']);
        $c = RevokeDirectPermissionCommand::capture($a, SystemActor::named('admin'), 'Remove', [], new FrozenClock(new DateTimeImmutable('2026-07-29 12:00:00 UTC')));
        $r = app(RevokeDirectPermissionHandler::class)->handle($c);
        $this->assertSame(MutationOutcome::Revoked, $r->outcome);
        $this->assertDatabaseHas('acl_access_audits', ['event' => 'permission.revoked_from_subject']);
    }
}

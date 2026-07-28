<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Models;

use Carbon\CarbonImmutable;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\PermissionAssignment;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\Models\RoleAssignment;
use HangTuah\GateTrail\Models\RolePermission;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

final class AssignmentPeriodScopeTest extends TestCase
{
    private CarbonImmutable $time;

    private Role $role;

    private Permission $permission;

    private TestSubject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);

        $this->time = CarbonImmutable::parse('2026-07-28 12:00:00');
        $this->role = Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'web',
        ]);
        $this->permission = Permission::query()->create([
            'name' => 'documents.review',
            'guard_name' => 'web',
        ]);
        $this->subject = TestSubject::query()->create([
            'name' => 'Reviewer',
        ]);
    }

    public function test_role_assignment_scopes_partition_lifecycle_states(): void
    {
        $active = $this->createRoleAssignment([
            'assigned_at' => $this->time->subHour(),
        ]);
        $scheduled = $this->createRoleAssignment([
            'assigned_at' => $this->time->subHour(),
            'starts_at' => $this->time->addSecond(),
        ]);
        $expired = $this->createRoleAssignment([
            'assigned_at' => $this->time->subHours(2),
            'expires_at' => $this->time,
        ]);
        $revoked = $this->createRoleAssignment([
            'assigned_at' => $this->time->subHours(2),
            'expires_at' => $this->time->addHour(),
            'revoked_at' => $this->time->subMinute(),
            'revoked_by_type' => 'gatetrail.system',
            'revoked_by_id' => 'test-suite',
            'revocation_reason' => 'Lifecycle scope test',
        ]);

        $this->assertSame(
            [$active->getKey()],
            RoleAssignment::query()->activeAt($this->time)->pluck('id')->all(),
        );
        $this->assertSame(
            [$scheduled->getKey()],
            RoleAssignment::query()->scheduledAt($this->time)->pluck('id')->all(),
        );
        $this->assertSame(
            [$expired->getKey()],
            RoleAssignment::query()->expiredAt($this->time)->pluck('id')->all(),
        );
        $this->assertSame(
            [$revoked->getKey()],
            RoleAssignment::query()->revoked()->pluck('id')->all(),
        );
    }

    public function test_start_is_inclusive_and_expiry_is_exclusive(): void
    {
        $startsNow = $this->createRoleAssignment([
            'assigned_at' => $this->time->subHour(),
            'starts_at' => $this->time,
            'expires_at' => $this->time->addHour(),
        ]);
        $expiresNow = $this->createRoleAssignment([
            'assigned_at' => $this->time->subHour(),
            'expires_at' => $this->time,
        ]);

        $this->assertTrue(
            RoleAssignment::query()
                ->activeAt($this->time)
                ->whereKey($startsNow)
                ->exists(),
        );
        $this->assertFalse(
            RoleAssignment::query()
                ->activeAt($this->time)
                ->whereKey($expiresNow)
                ->exists(),
        );
        $this->assertTrue(
            RoleAssignment::query()
                ->expiredAt($this->time)
                ->whereKey($expiresNow)
                ->exists(),
        );
    }

    public function test_assigned_at_is_used_when_start_is_omitted(): void
    {
        $active = $this->createRoleAssignment([
            'assigned_at' => $this->time,
            'starts_at' => null,
            'expires_at' => null,
        ]);
        $scheduled = $this->createRoleAssignment([
            'assigned_at' => $this->time->addSecond(),
            'starts_at' => null,
            'expires_at' => null,
        ]);

        $this->assertTrue(
            RoleAssignment::query()
                ->activeAt($this->time)
                ->whereKey($active)
                ->exists(),
        );
        $this->assertTrue(
            RoleAssignment::query()
                ->scheduledAt($this->time)
                ->whereKey($scheduled)
                ->exists(),
        );
    }

    public function test_revoked_state_takes_precedence_over_time_states(): void
    {
        $assignment = $this->createRoleAssignment([
            'assigned_at' => $this->time->subHour(),
            'expires_at' => $this->time,
            'revoked_at' => $this->time->subMinute(),
            'revoked_by_type' => 'gatetrail.system',
            'revoked_by_id' => 'test-suite',
            'revocation_reason' => 'Lifecycle scope test',
        ]);

        $this->assertFalse(
            RoleAssignment::query()
                ->activeAt($this->time)
                ->whereKey($assignment)
                ->exists(),
        );
        $this->assertFalse(
            RoleAssignment::query()
                ->expiredAt($this->time)
                ->whereKey($assignment)
                ->exists(),
        );
        $this->assertTrue(
            RoleAssignment::query()
                ->revoked()
                ->whereKey($assignment)
                ->exists(),
        );
    }

    public function test_all_assignment_models_expose_the_same_scopes(): void
    {
        $roleAssignment = $this->createRoleAssignment([
            'assigned_at' => $this->time,
        ]);
        $permissionAssignment = PermissionAssignment::query()->create([
            'subject_type' => $this->subject->getMorphClass(),
            'subject_id' => $this->subject->getKey(),
            'permission_id' => $this->permission->getKey(),
            'assigned_at' => $this->time,
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'test-suite',
        ]);
        $rolePermission = RolePermission::query()->create([
            'role_id' => $this->role->getKey(),
            'permission_id' => $this->permission->getKey(),
            'assigned_at' => $this->time,
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'test-suite',
        ]);

        $this->assertTrue($this->isActiveAt($roleAssignment));
        $this->assertTrue($this->isActiveAt($permissionAssignment));
        $this->assertTrue($this->isActiveAt($rolePermission));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createRoleAssignment(array $attributes): RoleAssignment
    {
        return RoleAssignment::query()->create([
            'subject_type' => $this->subject->getMorphClass(),
            'subject_id' => $this->subject->getKey(),
            'role_id' => $this->role->getKey(),
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'test-suite',
            ...$attributes,
        ]);
    }

    private function isActiveAt(Model $assignment): bool
    {
        return $assignment->newQuery()
            ->activeAt($this->time)
            ->whereKey($assignment)
            ->exists();
    }
}

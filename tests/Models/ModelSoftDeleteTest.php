<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Models;

use Carbon\CarbonImmutable;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\PermissionAssignment;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\Models\RoleAssignment;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;

final class ModelSoftDeleteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);
    }

    public function test_soft_deleting_definitions_preserves_assignment_history(): void
    {
        $subject = TestSubject::query()->create(['name' => 'Reviewer']);
        $role = Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'web',
        ]);
        $permission = Permission::query()->create([
            'name' => 'documents.review',
            'guard_name' => 'web',
        ]);
        $assignedAt = CarbonImmutable::parse('2026-07-28 10:00:00');

        $roleAssignment = RoleAssignment::query()->create([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'role_id' => $role->getKey(),
            'assigned_at' => $assignedAt,
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'test-suite',
        ]);
        $permissionAssignment = PermissionAssignment::query()->create([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'permission_id' => $permission->getKey(),
            'assigned_at' => $assignedAt,
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'test-suite',
        ]);

        $role->delete();
        $permission->delete();

        $this->assertSoftDeleted($role);
        $this->assertSoftDeleted($permission);
        $this->assertDatabaseHas('acl_role_assignments', [
            'id' => $roleAssignment->getKey(),
        ]);
        $this->assertDatabaseHas('acl_permission_assignments', [
            'id' => $permissionAssignment->getKey(),
        ]);
        $this->assertTrue($roleAssignment->fresh()->role->is($role));
        $this->assertTrue(
            $permissionAssignment->fresh()->permission->is($permission),
        );

        $role->restore();
        $permission->restore();

        $this->assertFalse($role->fresh()->trashed());
        $this->assertFalse($permission->fresh()->trashed());
    }
}

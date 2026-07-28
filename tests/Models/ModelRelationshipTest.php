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

final class ModelRelationshipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);
    }

    public function test_models_expose_history_preserving_relationships(): void
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
            'assignment_reason' => 'Relationship test',
        ]);
        $permissionAssignment = PermissionAssignment::query()->create([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'permission_id' => $permission->getKey(),
            'assigned_at' => $assignedAt,
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'test-suite',
            'assignment_reason' => 'Relationship test',
        ]);
        $rolePermission = RolePermission::query()->create([
            'role_id' => $role->getKey(),
            'permission_id' => $permission->getKey(),
            'assigned_at' => $assignedAt,
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'test-suite',
            'assignment_reason' => 'Relationship test',
        ]);

        $this->assertTrue($role->subjectAssignments->contains($roleAssignment));
        $this->assertTrue(
            $role->permissionAssignments->contains($rolePermission),
        );
        $this->assertTrue(
            $permission->subjectAssignments->contains($permissionAssignment),
        );
        $this->assertTrue(
            $permission->roleAssignments->contains($rolePermission),
        );
        $this->assertTrue($roleAssignment->subject->is($subject));
        $this->assertTrue($roleAssignment->role->is($role));
        $this->assertTrue($permissionAssignment->subject->is($subject));
        $this->assertTrue($permissionAssignment->permission->is($permission));
        $this->assertTrue($rolePermission->role->is($role));
        $this->assertTrue($rolePermission->permission->is($permission));
    }
}

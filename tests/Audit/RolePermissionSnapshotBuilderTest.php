<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Audit;

use HangTuah\GateTrail\Audit\RolePermissionSnapshotBuilder;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\Models\RolePermission;
use HangTuah\GateTrail\Tests\TestCase;

final class RolePermissionSnapshotBuilderTest extends TestCase
{
    public function test_snapshot_has_role_permission_shape(): void
    {
        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);
        $role = Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'web',
        ]);
        $permission = Permission::query()->create([
            'name' => 'documents.approve',
            'guard_name' => 'web',
        ]);
        $assignment = RolePermission::query()->create([
            'role_id' => $role->getKey(),
            'permission_id' => $permission->getKey(),
            'assigned_at' => '2026-07-29 12:00:00',
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'test-suite',
        ]);

        $snapshot = json_decode(
            app(RolePermissionSnapshotBuilder::class)->build(
                $assignment,
            ),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('1', $snapshot['role_id']);
        $this->assertSame('1', $snapshot['permission_id']);
        $this->assertArrayNotHasKey('subject_type', $snapshot);
        $this->assertArrayNotHasKey('subject_id', $snapshot);
        $this->assertNull($snapshot['revoked_at']);
    }
}

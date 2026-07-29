<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Audit;

use HangTuah\GateTrail\Audit\PermissionAssignmentSnapshotBuilder;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\PermissionAssignment;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;

final class PermissionAssignmentSnapshotBuilderTest extends TestCase
{
    public function test_snapshot_has_permission_assignment_shape(): void
    {
        $this->artisan('migrate', ['--database' => 'testing'])->assertExitCode(0);
        $subject = TestSubject::query()->create(['name' => 'Reviewer']);
        $permission = Permission::query()->create(['name' => 'documents.approve', 'guard_name' => 'web']);
        $assignment = PermissionAssignment::query()->create([
            'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey(),
            'permission_id' => $permission->getKey(), 'assigned_at' => '2026-07-29 12:00:00',
            'assigned_by_type' => 'gatetrail.system', 'assigned_by_id' => 'test-suite',
        ]);

        $snapshot = json_decode(
            app(PermissionAssignmentSnapshotBuilder::class)->build($assignment),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('1', $snapshot['permission_id']);
        $this->assertSame('1', $snapshot['subject_id']);
        $this->assertArrayNotHasKey('role_id', $snapshot);
        $this->assertNull($snapshot['revoked_at']);
    }
}

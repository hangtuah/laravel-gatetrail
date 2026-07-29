<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Audit;

use HangTuah\GateTrail\Audit\RoleAssignmentSnapshotBuilder;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\Models\RoleAssignment;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;
use InvalidArgumentException;

final class RoleAssignmentSnapshotBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);
    }

    public function test_snapshot_has_exact_version_one_shape_and_values(): void
    {
        $assignment = $this->assignment();

        $snapshot = app(RoleAssignmentSnapshotBuilder::class)
            ->build($assignment);

        $this->assertSame(
            '{"id":"1","assigned_at":"2026-07-29T12:00:00.000000Z",'
            .'"assigned_by_type":"gatetrail.system",'
            .'"assigned_by_id":"test-suite",'
            .'"assignment_reason":"Assigned for review",'
            .'"starts_at":null,"expires_at":null,"revoked_at":null,'
            .'"revoked_by_type":null,"revoked_by_id":null,'
            .'"revocation_reason":null,"subject_type":"'
            .str_replace('\\', '\\\\', TestSubject::class)
            .'","subject_id":"1","role_id":"1"}',
            $snapshot,
        );
    }

    public function test_snapshot_reloads_persisted_values(): void
    {
        $assignment = $this->assignment();
        $assignment->setAttribute(
            'assignment_reason',
            'Unsaved in-memory value',
        );

        $snapshot = app(RoleAssignmentSnapshotBuilder::class)
            ->build($assignment);

        $this->assertStringContainsString(
            '"assignment_reason":"Assigned for review"',
            $snapshot,
        );
        $this->assertStringNotContainsString(
            'Unsaved in-memory value',
            $snapshot,
        );
    }

    public function test_unpersisted_assignment_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unpersisted role assignment');

        app(RoleAssignmentSnapshotBuilder::class)
            ->build(new RoleAssignment);
    }

    private function assignment(): RoleAssignment
    {
        $subject = TestSubject::query()->create(['name' => 'Reviewer']);
        $role = Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'web',
            'description' => null,
        ]);

        return RoleAssignment::query()->create([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'role_id' => $role->getKey(),
            'assigned_at' => '2026-07-29 12:00:00',
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'test-suite',
            'assignment_reason' => 'Assigned for review',
            'starts_at' => null,
            'expires_at' => null,
            'revoked_at' => null,
            'revoked_by_type' => null,
            'revoked_by_id' => null,
            'revocation_reason' => null,
        ]);
    }
}

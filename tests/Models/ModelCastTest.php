<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Models;

use Carbon\CarbonImmutable;
use HangTuah\GateTrail\Models\AccessAudit;
use HangTuah\GateTrail\Models\RoleAssignment;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;

final class ModelCastTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);
    }

    public function test_assignment_timestamps_are_immutable_datetimes(): void
    {
        $subject = TestSubject::query()->create(['name' => 'Reviewer']);
        $roleId = $this->app['db']->table('acl_roles')->insertGetId([
            'name' => 'reviewer',
            'guard_name' => 'web',
            'description' => null,
            'created_at' => '2026-07-28 10:00:00',
            'updated_at' => '2026-07-28 10:00:00',
            'deleted_at' => null,
        ]);

        $assignment = RoleAssignment::query()->create([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'role_id' => $roleId,
            'assigned_at' => '2026-07-28 10:00:00',
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'test-suite',
            'starts_at' => '2026-07-28 11:00:00',
            'expires_at' => '2026-07-29 11:00:00',
        ])->fresh();

        $this->assertInstanceOf(CarbonImmutable::class, $assignment->assigned_at);
        $this->assertInstanceOf(CarbonImmutable::class, $assignment->starts_at);
        $this->assertInstanceOf(CarbonImmutable::class, $assignment->expires_at);
        $this->assertInstanceOf(CarbonImmutable::class, $assignment->created_at);
        $this->assertInstanceOf(CarbonImmutable::class, $assignment->updated_at);
        $this->assertNull($assignment->revoked_at);
    }

    public function test_audit_documents_cast_from_portable_text_storage(): void
    {
        $audit = AccessAudit::query()->create([
            'event' => 'role.created',
            'actor_type' => 'gatetrail.system',
            'actor_id' => 'test-suite',
            'target_type' => 'role',
            'target_id' => 1,
            'before' => null,
            'after' => [
                'id' => '1',
                'name' => 'reviewer',
                'guard_name' => 'web',
            ],
            'context' => [
                'schema_version' => 1,
                'guard_name' => 'web',
                'metadata' => [],
            ],
            'reason' => 'Cast test',
        ])->fresh();

        $this->assertNull($audit->before);
        $this->assertSame('reviewer', $audit->after['name']);
        $this->assertSame(1, $audit->context['schema_version']);
        $this->assertInstanceOf(CarbonImmutable::class, $audit->created_at);
        $this->assertNull($audit->updated_at);
    }
}

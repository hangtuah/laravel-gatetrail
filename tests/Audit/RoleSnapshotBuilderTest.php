<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Audit;

use HangTuah\GateTrail\Audit\RoleSnapshotBuilder;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\Tests\TestCase;
use InvalidArgumentException;

final class RoleSnapshotBuilderTest extends TestCase
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
        $role = Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'web',
            'description' => null,
        ]);

        $this->assertSame(
            '{"id":"1","name":"reviewer","guard_name":"web",'
            .'"description":null,"deleted_at":null}',
            app(RoleSnapshotBuilder::class)->build($role),
        );
    }

    public function test_snapshot_preserves_non_null_description(): void
    {
        $role = Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'web',
            'description' => 'Reviews documents.',
        ]);

        $this->assertSame(
            '{"id":"1","name":"reviewer","guard_name":"web",'
            .'"description":"Reviews documents.","deleted_at":null}',
            app(RoleSnapshotBuilder::class)->build($role),
        );
    }

    public function test_snapshot_reloads_persisted_values(): void
    {
        $role = Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'web',
            'description' => null,
        ]);
        $role->setAttribute('name', 'unsaved-in-memory-name');

        $this->assertStringContainsString(
            '"name":"reviewer"',
            app(RoleSnapshotBuilder::class)->build($role),
        );
    }

    public function test_unpersisted_role_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unpersisted role');

        app(RoleSnapshotBuilder::class)->build(new Role);
    }
}

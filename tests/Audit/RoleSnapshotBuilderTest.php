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

    public function test_soft_deleted_role_is_reloaded_from_storage(): void
    {
        $role = Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'web',
            'description' => 'Reviews documents.',
        ]);
        $role->forceFill([
            'deleted_at' => '2026-07-29 12:00:00',
            'updated_at' => '2026-07-29 12:00:00',
        ])->save();
        $role->setAttribute('description', 'Stale in-memory value');

        $this->assertSame(
            '{"id":"1","name":"reviewer","guard_name":"web",'
            .'"description":"Reviews documents.",'
            .'"deleted_at":"2026-07-29T12:00:00.000000Z"}',
            app(RoleSnapshotBuilder::class)->build($role),
        );
    }

    public function test_canonical_key_order_is_stable_for_both_states(): void
    {
        $role = Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'web',
            'description' => null,
        ]);
        $builder = app(RoleSnapshotBuilder::class);
        $expected = ['id', 'name', 'guard_name', 'description', 'deleted_at'];

        $this->assertSame($expected, $this->keys($builder->build($role)));

        $role->forceFill(['deleted_at' => '2026-07-29 12:00:00'])->save();

        $this->assertSame($expected, $this->keys($builder->build($role)));
    }

    public function test_configured_role_subclass_remains_supported(): void
    {
        config()->set(
            'gatetrail.models.role',
            SnapshotCustomRole::class,
        );
        $role = SnapshotCustomRole::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'web',
            'description' => null,
        ]);
        $role->delete();

        $this->assertStringContainsString(
            '"name":"reviewer"',
            app(RoleSnapshotBuilder::class)->build($role),
        );
        $this->assertStringNotContainsString(
            '"deleted_at":null',
            app(RoleSnapshotBuilder::class)->build($role),
        );
    }

    public function test_unpersisted_role_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unpersisted role');

        app(RoleSnapshotBuilder::class)->build(new Role);
    }

    public function test_missing_persisted_role_is_rejected(): void
    {
        $role = Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'web',
            'description' => null,
        ]);
        Role::query()->withTrashed()->forceDelete();

        $this->assertTrue($role->exists);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unpersisted role');

        app(RoleSnapshotBuilder::class)->build($role);
    }

    /**
     * @return list<string>
     */
    private function keys(string $snapshot): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(
            $snapshot,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return array_keys($decoded);
    }
}

final class SnapshotCustomRole extends Role {}

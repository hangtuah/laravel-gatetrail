<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Audit;

use HangTuah\GateTrail\Audit\PermissionSnapshotBuilder;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Tests\TestCase;
use InvalidArgumentException;

final class PermissionSnapshotBuilderTest extends TestCase
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
        $permission = $this->permission(null);

        $this->assertSame(
            '{"id":"1","name":"documents.approve","guard_name":"web",'
            .'"description":null,"deleted_at":null}',
            app(PermissionSnapshotBuilder::class)->build($permission),
        );
    }

    public function test_snapshot_preserves_non_null_description(): void
    {
        $permission = $this->permission('Approve submitted documents');

        $this->assertSame(
            '{"id":"1","name":"documents.approve","guard_name":"web",'
            .'"description":"Approve submitted documents",'
            .'"deleted_at":null}',
            app(PermissionSnapshotBuilder::class)->build($permission),
        );
    }

    public function test_snapshot_preserves_an_explicit_empty_description(): void
    {
        $permission = $this->permission('');

        $this->assertSame(
            '{"id":"1","name":"documents.approve","guard_name":"web",'
            .'"description":"","deleted_at":null}',
            app(PermissionSnapshotBuilder::class)->build($permission),
        );
    }

    public function test_snapshot_reloads_persisted_values(): void
    {
        $permission = $this->permission(null);
        $permission->setAttribute('name', 'unsaved-in-memory-name');
        $permission->setAttribute('description', 'Stale in-memory value');

        $this->assertSame(
            '{"id":"1","name":"documents.approve","guard_name":"web",'
            .'"description":null,"deleted_at":null}',
            app(PermissionSnapshotBuilder::class)->build($permission),
        );
    }

    public function test_canonical_key_order_is_stable_for_both_states(): void
    {
        $permission = $this->permission(null);
        $builder = app(PermissionSnapshotBuilder::class);
        $expected = ['id', 'name', 'guard_name', 'description', 'deleted_at'];

        $this->assertSame($expected, $this->keys($builder->build($permission)));

        // Permission deactivation is not implemented yet. Writing the storage
        // column directly proves the unscoped reload already supports the
        // future lifecycle slices without depending on them.
        $permission->forceFill([
            'deleted_at' => '2026-07-29 12:00:00',
        ])->save();

        $this->assertSame($expected, $this->keys($builder->build($permission)));
    }

    public function test_soft_deleted_permission_is_reloaded_from_storage(): void
    {
        $permission = $this->permission('Approve submitted documents');
        $permission->forceFill([
            'deleted_at' => '2026-07-29 12:00:00',
            'updated_at' => '2026-07-29 12:00:00',
        ])->save();
        $permission->setAttribute('description', 'Stale in-memory value');

        $this->assertSame(
            '{"id":"1","name":"documents.approve","guard_name":"web",'
            .'"description":"Approve submitted documents",'
            .'"deleted_at":"2026-07-29T12:00:00.000000Z"}',
            app(PermissionSnapshotBuilder::class)->build($permission),
        );
    }

    public function test_configured_permission_subclass_remains_supported(): void
    {
        config()->set(
            'gatetrail.models.permission',
            SnapshotCustomPermission::class,
        );
        $permission = SnapshotCustomPermission::query()->create([
            'name' => 'documents.approve',
            'guard_name' => 'web',
            'description' => null,
        ]);

        $this->assertSame(
            '{"id":"1","name":"documents.approve","guard_name":"web",'
            .'"description":null,"deleted_at":null}',
            app(PermissionSnapshotBuilder::class)->build($permission),
        );
    }

    public function test_unpersisted_permission_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unpersisted permission');

        app(PermissionSnapshotBuilder::class)->build(new Permission);
    }

    public function test_missing_persisted_permission_is_rejected(): void
    {
        $permission = $this->permission(null);
        Permission::query()->withTrashed()->forceDelete();

        $this->assertTrue($permission->exists);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unpersisted permission');

        app(PermissionSnapshotBuilder::class)->build($permission);
    }

    private function permission(?string $description): Permission
    {
        return Permission::query()->create([
            'name' => 'documents.approve',
            'guard_name' => 'web',
            'description' => $description,
        ]);
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

final class SnapshotCustomPermission extends Permission {}

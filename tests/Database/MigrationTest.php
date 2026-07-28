<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Database;

use HangTuah\GateTrail\Exceptions\MigrationDependencyError;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

final class MigrationTest extends TestCase
{
    public function test_fresh_migration_creates_every_table(): void
    {
        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);

        foreach ($this->tableNames() as $table) {
            $this->assertTrue(
                Schema::connection('testing')->hasTable($table),
                sprintf('Expected table [%s] to exist.', $table),
            );
        }
    }

    public function test_full_rollback_drops_children_before_parents(): void
    {
        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);

        $this->artisan('migrate:rollback', [
            '--database' => 'testing',
        ])->assertExitCode(0);

        foreach ($this->tableNames() as $table) {
            $this->assertFalse(
                Schema::connection('testing')->hasTable($table),
                sprintf('Expected table [%s] to be removed.', $table),
            );
        }
    }

    public function test_child_migration_fails_clearly_when_parent_is_missing(): void
    {
        $migration = require __DIR__
            .'/../../database/migrations/'
            .'2026_07_28_000006_create_gatetrail_role_permissions_table.php';

        $this->expectException(MigrationDependencyError::class);
        $this->expectExceptionMessage(
            'required parent table [acl_roles] does not exist',
        );

        $migration->up();
    }

    /**
     * @return list<string>
     */
    private function tableNames(): array
    {
        return [
            'acl_roles',
            'acl_permissions',
            'acl_assignment_locks',
            'acl_role_assignments',
            'acl_permission_assignments',
            'acl_role_permissions',
            'acl_access_audits',
        ];
    }
}

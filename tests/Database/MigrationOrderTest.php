<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Database;

use PHPUnit\Framework\TestCase;

final class MigrationOrderTest extends TestCase
{
    public function test_migration_filenames_encode_parent_before_child_order(): void
    {
        $files = glob(__DIR__.'/../../database/migrations/*.php');

        $this->assertIsArray($files);
        $this->assertSame([
            '2026_07_28_000001_create_gatetrail_roles_table.php',
            '2026_07_28_000002_create_gatetrail_permissions_table.php',
            '2026_07_28_000003_create_gatetrail_assignment_locks_table.php',
            '2026_07_28_000004_create_gatetrail_role_assignments_table.php',
            '2026_07_28_000005_create_gatetrail_permission_assignments_table.php',
            '2026_07_28_000006_create_gatetrail_role_permissions_table.php',
            '2026_07_28_000007_create_gatetrail_access_audits_table.php',
        ], array_map(basename(...), $files));
    }
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Integration\MySql;

final class PackageManagedSchemaTest extends MySqlIntegrationTestCase
{
    public function test_migrations_install_inspect_and_rollback(): void
    {
        $this->artisan('migrate', [
            '--database' => self::CONNECTION,
        ])->assertExitCode(0);

        $this->assertGateTrailTablesExist(true);
        $this->assertInspectionPasses($this->inspectSchema());

        $this->artisan('migrate:rollback', [
            '--database' => self::CONNECTION,
        ])->assertExitCode(0);

        $this->assertGateTrailTablesExist(false);
    }
}

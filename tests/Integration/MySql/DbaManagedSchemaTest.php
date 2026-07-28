<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Integration\MySql;

final class DbaManagedSchemaTest extends MySqlIntegrationTestCase
{
    public function test_artifacts_install_inspect_and_rollback(): void
    {
        $this->artisan('migrate', [
            '--database' => self::CONNECTION,
        ])->assertExitCode(0);

        $this->assertGateTrailTablesExist(false);

        $this->executeArtifact('install');
        $this->assertGateTrailTablesExist(true);
        $this->assertInspectionPasses($this->inspectSchema());

        $this->executeArtifact('rollback');
        $this->assertGateTrailTablesExist(false);
    }

    protected function provisioningMode(): string
    {
        return 'dba';
    }
}

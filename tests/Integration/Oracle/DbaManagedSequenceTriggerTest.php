<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Integration\Oracle;

final class DbaManagedSequenceTriggerTest extends OracleIntegrationTestCase
{
    public function test_artifacts_generate_ids_inspect_and_rollback(): void
    {
        $this->artisan('migrate', [
            '--database' => self::CONNECTION,
        ])->assertExitCode(0);

        $this->assertGateTrailTablesExist(false);

        $this->executeArtifact('install');
        $this->assertGateTrailTablesExist(true);
        $this->assertInspectionPasses($this->inspectSchema());

        $this->assertRoleGeneratedKeysWork();

        $this->executeArtifact('rollback');
        $this->assertGateTrailTablesExist(false);
    }
}

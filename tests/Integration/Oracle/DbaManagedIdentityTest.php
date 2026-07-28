<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Integration\Oracle;

final class DbaManagedIdentityTest extends OracleIntegrationTestCase
{
    public function test_artifacts_generate_ids_inspect_and_rollback(): void
    {
        $this->artisan('migrate', [
            '--database' => self::CONNECTION,
        ])->assertExitCode(0);

        $this->assertGateTrailTablesExist(false);

        $this->executeArtifact('install');
        $this->assertGateTrailTablesExist(true);
        $this->assertTrue(
            $this->objectExists(
                'user_tab_identity_cols',
                'table_name',
                'acl_roles',
            ),
        );
        $this->assertInspectionPasses($this->inspectSchema());

        $this->assertRoleGeneratedKeysWork();

        $this->executeArtifact('rollback');
        $this->assertGateTrailTablesExist(false);
        $this->assertFalse(
            $this->objectExists(
                'user_tab_identity_cols',
                'table_name',
                'acl_roles',
            ),
        );
    }

    protected function generatedKeyStrategy(): string
    {
        return 'identity';
    }
}

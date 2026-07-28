<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Integration\Oracle;

final class PackageManagedIdentityTest extends OracleIntegrationTestCase
{
    public function test_migrations_install_inspect_and_rollback(): void
    {
        $this->artisan('migrate', [
            '--database' => self::CONNECTION,
        ])->assertExitCode(0);

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

        $this->artisan('migrate:rollback', [
            '--database' => self::CONNECTION,
        ])->assertExitCode(0);

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

    protected function provisioningMode(): string
    {
        return 'package';
    }
}

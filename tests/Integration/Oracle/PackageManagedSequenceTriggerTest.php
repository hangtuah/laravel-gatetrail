<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Integration\Oracle;

final class PackageManagedSequenceTriggerTest extends OracleIntegrationTestCase
{
    public function test_migrations_install_inspect_and_rollback(): void
    {
        $this->artisan('migrate', [
            '--database' => self::CONNECTION,
        ])->assertExitCode(0);

        $this->assertGateTrailTablesExist(true);
        $this->assertTrue(
            $this->objectExists(
                'user_sequences',
                'sequence_name',
                'acl_roles_seq',
            ),
        );
        $this->assertTrue(
            $this->objectExists(
                'user_triggers',
                'trigger_name',
                'acl_roles_trg',
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
                'user_sequences',
                'sequence_name',
                'acl_roles_seq',
            ),
        );
        $this->assertFalse(
            $this->objectExists(
                'user_triggers',
                'trigger_name',
                'acl_roles_trg',
            ),
        );
    }

    protected function provisioningMode(): string
    {
        return 'package';
    }
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Integration\PostgreSql;

/**
 * Inspection against a configured table prefix.
 *
 * The committed artifacts carry no prefix, so this lane provisions through the
 * package migrations, which resolve every table name from the same configured
 * context the reader binds into its catalog queries.
 */
final class ConfiguredPrefixTest extends PostgreSqlIntegrationTestCase
{
    public function test_migrations_install_and_inspect_prefixed_tables(): void
    {
        $this->artisan('migrate', [
            '--database' => self::CONNECTION,
        ])->assertExitCode(0);

        $this->assertGateTrailTablesExist(true);
        $this->assertTrue($this->tableExists('gt_acl_roles'));
        $this->assertFalse($this->tableExists('acl_roles'));
        $this->assertInspectionPasses($this->inspectSchema());

        $this->artisan('migrate:rollback', [
            '--database' => self::CONNECTION,
        ])->assertExitCode(0);

        $this->assertGateTrailTablesExist(false);
    }

    public function test_drift_on_a_prefixed_table_is_reported_against_its_physical_name(): void
    {
        $this->artisan('migrate', [
            '--database' => self::CONNECTION,
        ])->assertExitCode(0);

        $this->connection()->statement(
            'DROP TABLE "gt_acl_access_audits"',
        );

        $inspection = $this->inspectSchema();
        $objects = array_map(
            static fn ($issue): string => $issue->object,
            $inspection->issues,
        );

        $this->assertContains(
            'missing_table',
            $this->inspectionCodes($inspection),
        );
        $this->assertContains('gt_acl_access_audits', $objects);
        $this->assertFalse($inspection->passes());
    }

    protected function configuredPrefix(): ?string
    {
        return 'gt_';
    }
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Integration\PostgreSql;

/**
 * Inspection against a configured non-default schema.
 *
 * The connection's `search_path` points at the same schema, so the release
 * `0001` artifacts — which are deliberately unqualified — install there, and
 * the reader resolves the configured schema without falling back to
 * `current_schema()`.
 */
final class ConfiguredSchemaTest extends PostgreSqlIntegrationTestCase
{
    public function test_artifacts_install_and_inspect_inside_a_configured_schema(): void
    {
        $this->executeArtifact('install');

        $this->assertGateTrailTablesExist(true);
        $this->assertSame('security', $this->activeSchema());
        $this->assertFalse($this->publicSchemaHasGateTrailTables());
        $this->assertInspectionPasses($this->inspectSchema());

        $this->executeArtifact('rollback');
        $this->assertGateTrailTablesExist(false);
    }

    public function test_drift_inside_a_configured_schema_is_reported(): void
    {
        $this->executeArtifact('install');
        $this->connection()->statement(
            'DROP INDEX "security"."acl_audit_created_ix"',
        );

        $inspection = $this->inspectSchema();

        $this->assertContains(
            'missing_index',
            $this->inspectionCodes($inspection),
        );
        $this->assertFalse($inspection->passes());
    }

    protected function provisioningMode(): string
    {
        return 'dba';
    }

    protected function configuredSchema(): ?string
    {
        return 'security';
    }

    private function publicSchemaHasGateTrailTables(): bool
    {
        $row = $this->connection()->selectOne(
            <<<'SQL'
                SELECT COUNT(*) AS aggregate
                FROM pg_catalog.pg_class c
                INNER JOIN pg_catalog.pg_namespace n
                    ON n.oid = c.relnamespace
                WHERE n.nspname = 'public'
                  AND c.relkind = 'r'
                  AND c.relname LIKE 'acl\_%'
                SQL,
        );

        return (int) $this->rowValue($row, 'aggregate') > 0;
    }
}

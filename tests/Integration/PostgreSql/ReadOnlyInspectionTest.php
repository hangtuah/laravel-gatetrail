<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Integration\PostgreSql;

use HangTuah\GateTrail\Tests\Concerns\AssertsReadOnlySql;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Read-only proof for the live PostgreSQL inspector.
 *
 * A connection-level query listener records every statement the reader and
 * inspector actually execute against PostgreSQL 14. The recorded SQL must be
 * `SELECT` only, and the catalog must be byte-identical before and after.
 *
 * Only the SQL text is recorded. Bindings are deliberately never captured,
 * stored or asserted on, because they carry configured schema, prefix and
 * table identifiers belonging to the consumer.
 */
final class ReadOnlyInspectionTest extends PostgreSqlIntegrationTestCase
{
    use AssertsReadOnlySql;

    public function test_a_passing_inspection_executes_only_catalog_reads(): void
    {
        $this->executeArtifact('install');

        $queries = $this->record(function (): void {
            $this->assertInspectionPasses($this->inspectSchema());
        });

        $this->assertNotEmpty($queries);
        $this->assertReadOnlyStatements($queries);
    }

    public function test_a_failing_inspection_also_executes_only_catalog_reads(): void
    {
        $this->executeArtifact('install');
        $this->connection()->statement('DROP INDEX "acl_ra_subject_ix"');
        $this->connection()->statement(
            'ALTER TABLE "acl_role_assignments" '
            .'DROP CONSTRAINT "acl_ra_role_fk"',
        );

        $before = $this->objectNames();

        $queries = $this->record(function (): void {
            $inspection = $this->inspectSchema();

            $this->assertFalse($inspection->passes());
        });

        $this->assertNotEmpty($queries);
        $this->assertReadOnlyStatements($queries);
        $this->assertSame($before, $this->objectNames());
    }

    public function test_the_listener_proof_fails_when_a_mutating_statement_is_introduced(): void
    {
        $this->executeArtifact('install');

        $queries = $this->record(function (): void {
            $this->inspectSchema();
            $this->connection()->statement(
                'CREATE INDEX "acl_proof_guard_ix" ON "acl_roles" ("name")',
            );
        });

        $failed = false;

        try {
            $this->assertReadOnlyStatements($queries);
        } catch (AssertionFailedError) {
            $failed = true;
        }

        $this->assertTrue(
            $failed,
            'The read-only proof must fail when a mutating statement is introduced.',
        );
        $this->assertTrue($this->indexExists('acl_proof_guard_ix'));
    }

    protected function provisioningMode(): string
    {
        return 'dba';
    }

    /**
     * @return list<string>
     */
    private function objectNames(): array
    {
        $names = [];

        foreach ($this->connection()->select(
            <<<'SQL'
                SELECT c.relname || ':' || c.relkind AS value
                FROM pg_catalog.pg_class c
                INNER JOIN pg_catalog.pg_namespace n
                    ON n.oid = c.relnamespace
                WHERE n.nspname = ? AND c.relkind IN ('r', 'i', 'S')
                ORDER BY c.relname
                SQL,
            [$this->activeSchema()],
        ) as $row) {
            $names[] = (string) $this->rowValue($row, 'value');
        }

        return $names;
    }
}

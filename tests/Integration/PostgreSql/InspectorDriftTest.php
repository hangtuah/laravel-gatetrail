<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Integration\PostgreSql;

use HangTuah\GateTrail\Database\Schema\Inspection\SchemaInspection;

/**
 * Live drift detection against a real PostgreSQL 14 catalog.
 *
 * Each test installs the canonical release `0001` schema, seeds exactly one
 * drift with its own DDL, and asserts that the inspector reports it and leaves
 * it in place.
 */
final class InspectorDriftTest extends PostgreSqlIntegrationTestCase
{
    public function test_a_missing_table_is_reported_without_repair(): void
    {
        $this->executeArtifact('install');
        $this->connection()->statement(
            'DROP TABLE "acl_access_audits"',
        );

        $inspection = $this->inspectSchema();

        $this->assertContains(
            'missing_table',
            $this->inspectionCodes($inspection),
        );
        $this->assertFalse($inspection->passes());
        $this->assertFalse($this->tableExists('acl_access_audits'));
    }

    public function test_a_missing_explicit_index_is_reported_without_repair(): void
    {
        $this->executeArtifact('install');
        $this->connection()->statement(
            'DROP INDEX "acl_ra_subject_ix"',
        );

        $inspection = $this->inspectSchema();

        $this->assertContains(
            'missing_index',
            $this->inspectionCodes($inspection),
        );
        $this->assertFalse($inspection->passes());
        $this->assertFalse($this->indexExists('acl_ra_subject_ix'));
    }

    public function test_an_exact_string_collation_change_is_reported(): void
    {
        $this->executeArtifact('install');
        $this->connection()->statement(
            'ALTER TABLE "acl_roles" '
            .'ALTER COLUMN "name" TYPE VARCHAR(191) COLLATE "default"',
        );

        $inspection = $this->inspectSchema();
        $codes = $this->inspectionCodes($inspection);

        $this->assertContains('column_collation_mismatch', $codes);
        $this->assertFalse($inspection->passes());
        $this->assertSame(
            'acl_roles.name',
            $this->firstObjectFor($inspection, 'column_collation_mismatch'),
        );
    }

    public function test_a_cascading_foreign_key_is_reported_without_repair(): void
    {
        $this->executeArtifact('install');
        $this->connection()->statement(
            'ALTER TABLE "acl_role_assignments" '
            .'DROP CONSTRAINT "acl_ra_role_fk"',
        );
        $this->connection()->statement(
            'ALTER TABLE "acl_role_assignments" '
            .'ADD CONSTRAINT "acl_ra_role_fk" '
            .'FOREIGN KEY ("role_id") REFERENCES "acl_roles" ("id") '
            .'ON DELETE CASCADE ON UPDATE CASCADE',
        );

        $inspection = $this->inspectSchema();

        $this->assertContains(
            'foreign_rule_mismatch',
            $this->inspectionCodes($inspection),
        );
        $this->assertFalse($inspection->passes());
        $this->assertSame('CASCADE', $this->deleteRule('acl_ra_role_fk'));
    }

    public function test_a_generated_key_removed_from_a_column_is_reported(): void
    {
        $this->executeArtifact('install');
        $this->connection()->statement(
            'ALTER TABLE "acl_roles" ALTER COLUMN "id" DROP IDENTITY',
        );

        $inspection = $this->inspectSchema();
        $codes = $this->inspectionCodes($inspection);

        $this->assertContains('generated_key_mismatch', $codes);
        $this->assertFalse($inspection->passes());
        $this->assertSame(
            'acl_roles.id',
            $this->firstObjectFor($inspection, 'generated_key_mismatch'),
        );
    }

    /**
     * `GENERATED ALWAYS` still auto-assigns an identifier, so a schema carrying
     * it looks healthy until something inserts an explicit one. GateTrail's own
     * insert paths do, so it is drift rather than an equivalent representation.
     */
    public function test_an_always_identity_is_reported_as_generated_key_drift(): void
    {
        $this->executeArtifact('install');
        $this->connection()->statement(
            'ALTER TABLE "acl_roles" '
            .'ALTER COLUMN "id" SET GENERATED ALWAYS',
        );

        $inspection = $this->inspectSchema();

        $this->assertContains(
            'generated_key_mismatch',
            $this->inspectionCodes($inspection),
        );
        $this->assertFalse($inspection->passes());
        $this->assertSame('ALWAYS', $this->identityGeneration('acl_roles'));
    }

    /**
     * The strongest read-only claim these tests can make: after inspection has
     * reported drift, the catalog still shows exactly the drifted objects, and
     * a second inspection reports exactly the same issues.
     */
    public function test_inspection_repairs_nothing_it_reports(): void
    {
        $this->executeArtifact('install');
        $this->connection()->statement('DROP INDEX "acl_ra_subject_ix"');
        $this->connection()->statement('DROP TABLE "acl_access_audits"');
        $this->connection()->statement(
            'ALTER TABLE "acl_roles" ALTER COLUMN "id" DROP IDENTITY',
        );

        $before = $this->catalogFingerprint();
        $first = $this->inspectionCodes($this->inspectSchema());
        $second = $this->inspectionCodes($this->inspectSchema());
        $after = $this->catalogFingerprint();

        $this->assertSame($first, $second);
        $this->assertSame($before, $after);
        $this->assertContains('missing_index', $first);
        $this->assertContains('missing_table', $first);
        $this->assertContains('generated_key_mismatch', $first);

        $this->assertFalse($this->indexExists('acl_ra_subject_ix'));
        $this->assertFalse($this->tableExists('acl_access_audits'));
        $this->assertNull($this->identityGeneration('acl_roles'));
    }

    protected function provisioningMode(): string
    {
        return 'dba';
    }

    private function deleteRule(string $constraint): ?string
    {
        $row = $this->connection()->selectOne(
            <<<'SQL'
                SELECT rc.delete_rule
                FROM information_schema.referential_constraints rc
                WHERE rc.constraint_schema = ?
                  AND rc.constraint_name = ?
                SQL,
            [$this->activeSchema(), $constraint],
        );

        $value = $this->rowValue($row, 'delete_rule');

        return $value === null ? null : (string) $value;
    }

    private function identityGeneration(string $table): ?string
    {
        $row = $this->connection()->selectOne(
            <<<'SQL'
                SELECT identity_generation
                FROM information_schema.columns
                WHERE table_schema = ?
                  AND table_name = ?
                  AND column_name = 'id'
                SQL,
            [$this->activeSchema(), $table],
        );

        $value = $this->rowValue($row, 'identity_generation');

        return $value === null ? null : (string) $value;
    }

    private function firstObjectFor(
        SchemaInspection $inspection,
        string $code,
    ): ?string {
        foreach ($inspection->issues as $issue) {
            if ($issue->code === $code) {
                return $issue->object;
            }
        }

        return null;
    }
}

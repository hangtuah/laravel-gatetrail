<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Integration\MySql;

final class InspectorDriftTest extends MySqlIntegrationTestCase
{
    public function test_a_missing_index_is_reported_without_repair(): void
    {
        $this->executeArtifact('install');
        $this->connection()->statement(
            'ALTER TABLE acl_role_assignments DROP INDEX acl_ra_subject_ix',
        );

        $inspection = $this->inspectSchema();

        $this->assertContains(
            'missing_index',
            $this->inspectionCodes($inspection),
        );
        $this->assertFalse($inspection->passes());
        $this->assertFalse(
            $this->indexExists(
                'acl_role_assignments',
                'acl_ra_subject_ix',
            ),
        );
    }

    public function test_an_exact_name_collation_change_is_reported(): void
    {
        $this->executeArtifact('install');
        $this->connection()->statement(
            'ALTER TABLE acl_roles '
            .'MODIFY name VARCHAR(191) '
            .'COLLATE utf8mb4_unicode_ci NOT NULL',
        );

        $inspection = $this->inspectSchema();

        $this->assertContains(
            'column_collation_mismatch',
            $this->inspectionCodes($inspection),
        );
        $this->assertFalse($inspection->passes());
    }

    public function test_a_cascading_foreign_key_is_reported(): void
    {
        $this->executeArtifact('install');
        $this->connection()->statement(
            'ALTER TABLE acl_role_assignments '
            .'DROP FOREIGN KEY acl_ra_role_fk',
        );
        $this->connection()->statement(
            'ALTER TABLE acl_role_assignments '
            .'ADD CONSTRAINT acl_ra_role_fk '
            .'FOREIGN KEY (role_id) REFERENCES acl_roles (id) '
            .'ON DELETE CASCADE ON UPDATE CASCADE',
        );

        $inspection = $this->inspectSchema();

        $this->assertContains(
            'foreign_rule_mismatch',
            $this->inspectionCodes($inspection),
        );
        $this->assertFalse($inspection->passes());
    }

    protected function provisioningMode(): string
    {
        return 'dba';
    }

    private function indexExists(string $table, string $index): bool
    {
        return $this->connection()->table('information_schema.statistics')
            ->where('table_schema', $this->connection()->getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
}

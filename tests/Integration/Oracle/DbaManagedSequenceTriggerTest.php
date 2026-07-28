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

        $this->connection()->beginTransaction();

        try {
            $this->insertRole('generated-role');

            $generatedId = $this->connection()
                ->table('acl_roles')
                ->where('name', 'generated-role')
                ->value('id');

            $this->assertIsNumeric($generatedId);
            $this->assertGreaterThan(0, (int) $generatedId);
        } finally {
            $this->connection()->rollBack();
        }

        $this->assertFalse(
            $this->connection()
                ->table('acl_roles')
                ->where('name', 'generated-role')
                ->exists(),
        );

        $this->connection()->beginTransaction();

        try {
            $this->insertRole('explicit-role', 9001);

            $explicitId = $this->connection()
                ->table('acl_roles')
                ->where('name', 'explicit-role')
                ->value('id');

            $this->assertSame(9001, (int) $explicitId);
        } finally {
            $this->connection()->rollBack();
        }

        $this->executeArtifact('rollback');
        $this->assertGateTrailTablesExist(false);
    }

    private function insertRole(string $name, ?int $id = null): void
    {
        $values = [
            'name' => $name,
            'guard_name' => 'web',
            'description' => null,
            'created_at' => $this->connection()->raw('SYSTIMESTAMP'),
            'updated_at' => $this->connection()->raw('SYSTIMESTAMP'),
            'deleted_at' => null,
        ];

        if ($id !== null) {
            $values['id'] = $id;
        }

        $this->connection()->table('acl_roles')->insert($values);
    }
}

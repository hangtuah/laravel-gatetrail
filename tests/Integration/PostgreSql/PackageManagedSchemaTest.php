<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Integration\PostgreSql;

/**
 * Package-managed provisioning: `php artisan migrate` installs the schema.
 *
 * This is inspection evidence for the migration lane only. Application runtime
 * compatibility on PostgreSQL is not claimed or exercised here.
 */
final class PackageManagedSchemaTest extends PostgreSqlIntegrationTestCase
{
    public function test_migrations_install_inspect_and_rollback(): void
    {
        $this->artisan('migrate', [
            '--database' => self::CONNECTION,
        ])->assertExitCode(0);

        $this->assertGateTrailTablesExist(true);
        $this->assertInspectionPasses($this->inspectSchema());

        $this->artisan('migrate:rollback', [
            '--database' => self::CONNECTION,
        ])->assertExitCode(0);

        $this->assertGateTrailTablesExist(false);
    }

    /**
     * Laravel's PostgreSQL grammar emits `bigserial` and cannot name a primary
     * key, so this lane installs the sequence-default representation of the
     * generated key and PostgreSQL's own `<table>_pkey` names. The inspector
     * accepts both, and this test pins that they are what actually lands.
     */
    public function test_the_migration_lane_installs_serial_keys_and_default_primary_key_names(): void
    {
        $this->artisan('migrate', [
            '--database' => self::CONNECTION,
        ])->assertExitCode(0);

        $identity = $this->connection()->selectOne(
            <<<'SQL'
                SELECT is_identity, column_default
                FROM information_schema.columns
                WHERE table_schema = ?
                  AND table_name = ?
                  AND column_name = 'id'
                SQL,
            [$this->activeSchema(), 'acl_roles'],
        );

        $this->assertSame(
            'NO',
            $this->rowValue($identity, 'is_identity'),
        );
        $this->assertStringStartsWith(
            'nextval(',
            (string) $this->rowValue($identity, 'column_default'),
        );

        $primary = $this->connection()->selectOne(
            <<<'SQL'
                SELECT con.conname
                FROM pg_catalog.pg_constraint con
                INNER JOIN pg_catalog.pg_class c ON c.oid = con.conrelid
                INNER JOIN pg_catalog.pg_namespace n
                    ON n.oid = c.relnamespace
                WHERE n.nspname = ?
                  AND c.relname = ?
                  AND con.contype = 'p'
                SQL,
            [$this->activeSchema(), 'acl_roles'],
        );

        $this->assertSame(
            'acl_roles_pkey',
            $this->rowValue($primary, 'conname'),
        );
        $this->assertInspectionPasses($this->inspectSchema());
    }
}

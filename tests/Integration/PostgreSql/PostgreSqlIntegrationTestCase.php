<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Integration\PostgreSql;

use HangTuah\GateTrail\Database\Schema\Inspection\SchemaInspection;
use HangTuah\GateTrail\Database\Schema\Inspection\SchemaInspectorFactory;
use HangTuah\GateTrail\Database\Schema\Manifest\SchemaManifest;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use RuntimeException;

/**
 * Live PostgreSQL 14 evidence for read-only schema inspection.
 *
 * # What these tests establish, and what they do not
 *
 * They establish that a GateTrail schema installed by either provisioning mode
 * is readable through PostgreSQL's own catalog and that the inspector agrees
 * with the canonical manifest about it, and that seeded drift is reported
 * rather than repaired.
 *
 * They establish nothing about PostgreSQL **application runtime** behaviour.
 * No mutation service, resolver, audit writer or concurrency path runs here.
 * Those remain outside this increment.
 *
 * Provisioning is always named explicitly by the subclass so the two lanes
 * never blur:
 *
 * - {@see PackageManagedSchemaTest} installs through `php artisan migrate`;
 * - {@see DbaManagedSchemaTest} installs release `0001`'s committed artifacts.
 */
abstract class PostgreSqlIntegrationTestCase extends TestCase
{
    protected const CONNECTION = 'pgsql_integration';

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', self::CONNECTION);
        $app['config']->set(
            'database.connections.'.self::CONNECTION,
            [
                'driver' => 'pgsql',
                'host' => $this->environment(
                    'GATETRAIL_POSTGRES_HOST',
                    '127.0.0.1',
                ),
                'port' => $this->environment(
                    'GATETRAIL_POSTGRES_PORT',
                    '54329',
                ),
                'database' => $this->environment(
                    'GATETRAIL_POSTGRES_DATABASE',
                    'gatetrail_test',
                ),
                'username' => $this->environment(
                    'GATETRAIL_POSTGRES_USERNAME',
                    'gatetrail',
                ),
                'password' => $this->environment(
                    'GATETRAIL_POSTGRES_PASSWORD',
                    'gatetrail_test',
                ),
                'charset' => 'utf8',
                'prefix' => '',
                'prefix_indexes' => true,
                'search_path' => $this->searchPath(),
                'sslmode' => 'prefer',
            ],
        );
        $app['config']->set(
            'gatetrail.database.connection',
            self::CONNECTION,
        );
        $app['config']->set(
            'gatetrail.database.provisioning',
            $this->provisioningMode(),
        );
        $app['config']->set(
            'gatetrail.database.schema',
            $this->configuredSchema(),
        );
        $app['config']->set(
            'gatetrail.database.prefix',
            $this->configuredPrefix(),
        );
    }

    protected function defineDatabaseMigrations(): void {}

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSafeTarget();
        $this->prepareSchema();
        $this->resetGateTrailSchema();
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) {
            $this->resetGateTrailSchema();
        }

        parent::tearDown();
    }

    /**
     * `'package'` installs through the package migrations; `'dba'` leaves DDL
     * to the committed release `0001` artifacts.
     */
    protected function provisioningMode(): string
    {
        return 'package';
    }

    protected function configuredSchema(): ?string
    {
        return null;
    }

    protected function configuredPrefix(): ?string
    {
        return null;
    }

    private function searchPath(): string
    {
        return $this->configuredSchema() ?? 'public';
    }

    protected function connection(): Connection
    {
        return $this->app['db']->connection(self::CONNECTION);
    }

    protected function schema(): Builder
    {
        return $this->connection()->getSchemaBuilder();
    }

    protected function assertGateTrailTablesExist(bool $expected): void
    {
        foreach ($this->tableNames() as $table) {
            $this->assertSame(
                $expected,
                $this->tableExists($table),
                sprintf(
                    'Expected PostgreSQL table [%s] existence to be [%s].',
                    $table,
                    $expected ? 'true' : 'false',
                ),
            );
        }
    }

    protected function tableExists(string $table): bool
    {
        $row = $this->connection()->selectOne(
            <<<'SQL'
                SELECT COUNT(*) AS aggregate
                FROM pg_catalog.pg_class c
                INNER JOIN pg_catalog.pg_namespace n
                    ON n.oid = c.relnamespace
                WHERE n.nspname = ?
                  AND c.relname = ?
                  AND c.relkind = 'r'
                SQL,
            [$this->activeSchema(), $table],
        );

        return (int) $this->rowValue($row, 'aggregate') > 0;
    }

    protected function indexExists(string $index): bool
    {
        $row = $this->connection()->selectOne(
            <<<'SQL'
                SELECT COUNT(*) AS aggregate
                FROM pg_catalog.pg_class c
                INNER JOIN pg_catalog.pg_namespace n
                    ON n.oid = c.relnamespace
                WHERE n.nspname = ?
                  AND c.relname = ?
                  AND c.relkind = 'i'
                SQL,
            [$this->activeSchema(), $index],
        );

        return (int) $this->rowValue($row, 'aggregate') > 0;
    }

    protected function activeSchema(): string
    {
        return $this->configuredSchema() ?? 'public';
    }

    protected function inspectSchema(): SchemaInspection
    {
        return $this->app->make(SchemaInspectorFactory::class)
            ->forDriver('pgsql', $this->connection())
            ->inspect(
                $this->app->make(SchemaManifest::class),
                $this->app->make(SchemaContext::class),
            );
    }

    protected function assertInspectionPasses(
        SchemaInspection $inspection,
    ): void {
        $messages = array_map(
            static fn ($issue): string => sprintf(
                '%s: %s',
                $issue->code,
                $issue->message,
            ),
            $inspection->issues,
        );

        $this->assertTrue(
            $inspection->passes(),
            implode(PHP_EOL, $messages),
        );
    }

    /**
     * @return list<string>
     */
    protected function inspectionCodes(
        SchemaInspection $inspection,
    ): array {
        return array_map(
            static fn ($issue): string => $issue->code,
            $inspection->issues,
        );
    }

    /**
     * Runs one of the committed release `0001` artifacts.
     *
     * The artifacts are deliberately unqualified, so the connection's
     * `search_path` decides which schema they land in.
     */
    protected function executeArtifact(string $direction): void
    {
        $path = dirname(__DIR__, 3)
            .'/database/ddl/postgresql/0001/'
            .$direction.'.sql';
        $sql = file_get_contents($path);

        if (! is_string($sql)) {
            throw new RuntimeException(
                sprintf('Unable to read PostgreSQL artifact [%s].', $path),
            );
        }

        $this->connection()->unprepared($sql);
    }

    /**
     * @return list<string>
     */
    protected function tableNames(bool $rollback = false): array
    {
        $manifest = $this->app->make(SchemaManifest::class);
        $context = $this->app->make(SchemaContext::class);
        $keys = $rollback
            ? $manifest->rollbackOrder()
            : $manifest->creationOrder();

        return array_map(
            static fn (string $key): string => $context->tableName($key),
            $keys,
        );
    }

    protected function rowValue(?object $row, string $key): int|string|null
    {
        if ($row === null) {
            return null;
        }

        foreach ((array) $row as $column => $value) {
            if (
                strcasecmp((string) $column, $key) === 0
                && (is_int($value) || is_string($value))
            ) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Refuses any endpoint that is not an obviously disposable local test
     * database, and refuses to run at all if the live server is not
     * PostgreSQL 14.
     */
    private function assertSafeTarget(): void
    {
        $host = (string) $this->connection()->getConfig('host');
        $database = $this->connection()->getDatabaseName();

        if (! in_array($host, ['127.0.0.1', 'localhost', 'postgres'], true)) {
            throw new RuntimeException(
                sprintf(
                    'GateTrail PostgreSQL integration tests refuse host [%s].',
                    $host,
                ),
            );
        }

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException(
                sprintf(
                    'GateTrail PostgreSQL integration tests refuse database [%s]; the name must end with [_test].',
                    $database,
                ),
            );
        }

        $row = $this->connection()->selectOne(
            <<<'SQL'
                SELECT
                    current_database() AS database_name,
                    current_setting('server_version_num') AS version_num
                SQL,
        );
        $actualDatabase = (string) $this->rowValue($row, 'database_name');
        $version = (int) $this->rowValue($row, 'version_num');

        if ($actualDatabase !== $database) {
            throw new RuntimeException(
                'The active PostgreSQL database does not match the configured integration database.',
            );
        }

        if ($version < 140000 || $version >= 150000) {
            throw new RuntimeException(
                sprintf(
                    'GateTrail PostgreSQL integration tests target PostgreSQL 14; found server_version_num [%d].',
                    $version,
                ),
            );
        }
    }

    private function prepareSchema(): void
    {
        $schema = $this->configuredSchema();

        if ($schema === null) {
            return;
        }

        $this->connection()->statement(
            sprintf('CREATE SCHEMA IF NOT EXISTS "%s"', $schema),
        );
    }

    private function resetGateTrailSchema(): void
    {
        foreach ($this->tableNames(rollback: true) as $table) {
            $this->connection()->statement(
                sprintf(
                    'DROP TABLE IF EXISTS "%s"."%s" CASCADE',
                    $this->activeSchema(),
                    $table,
                ),
            );
        }

        $this->connection()->statement(
            sprintf(
                'DROP TABLE IF EXISTS "%s"."migrations" CASCADE',
                $this->activeSchema(),
            ),
        );
    }

    private function environment(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== ''
            ? $value
            : $default;
    }
}

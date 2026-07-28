<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Integration\MySql;

use HangTuah\GateTrail\Database\Schema\Inspection\SchemaInspection;
use HangTuah\GateTrail\Database\Schema\Inspection\SchemaInspectorFactory;
use HangTuah\GateTrail\Database\Schema\Manifest\SchemaManifest;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use RuntimeException;

abstract class MySqlIntegrationTestCase extends TestCase
{
    protected const CONNECTION = 'mysql_integration';

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', self::CONNECTION);
        $app['config']->set(
            'database.connections.'.self::CONNECTION,
            [
                'driver' => 'mysql',
                'host' => $this->environment(
                    'GATETRAIL_MYSQL_HOST',
                    '127.0.0.1',
                ),
                'port' => $this->environment(
                    'GATETRAIL_MYSQL_PORT',
                    '33061',
                ),
                'database' => $this->environment(
                    'GATETRAIL_MYSQL_DATABASE',
                    'gatetrail_test',
                ),
                'username' => $this->environment(
                    'GATETRAIL_MYSQL_USERNAME',
                    'gatetrail',
                ),
                'password' => $this->environment(
                    'GATETRAIL_MYSQL_PASSWORD',
                    'gatetrail_test',
                ),
                'unix_socket' => '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
                'engine' => null,
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
    }

    protected function defineDatabaseMigrations(): void {}

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSafeTarget();
        $this->resetGateTrailSchema();
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) {
            $this->resetGateTrailSchema();
        }

        parent::tearDown();
    }

    protected function provisioningMode(): string
    {
        return 'package';
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
                $this->schema()->hasTable($table),
                sprintf(
                    'Expected table [%s] existence to be [%s].',
                    $table,
                    $expected ? 'true' : 'false',
                ),
            );
        }
    }

    protected function inspectSchema(): SchemaInspection
    {
        return $this->app->make(SchemaInspectorFactory::class)
            ->forDriver('mysql', $this->connection())
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

    protected function executeArtifact(string $direction): void
    {
        $path = dirname(__DIR__, 3)
            .'/database/ddl/mysql/0001/'
            .$direction.'.sql';
        $sql = file_get_contents($path);

        if (! is_string($sql)) {
            throw new RuntimeException(
                sprintf('Unable to read MySQL artifact [%s].', $path),
            );
        }

        $sql = preg_replace('/^--.*$/m', '', $sql) ?? $sql;
        $statements = preg_split('/;\s*(?:\R|$)/', trim($sql));

        if (! is_array($statements)) {
            throw new RuntimeException(
                sprintf('Unable to parse MySQL artifact [%s].', $path),
            );
        }

        foreach ($statements as $statement) {
            if (trim($statement) !== '') {
                $this->connection()->unprepared($statement);
            }
        }
    }

    private function assertSafeTarget(): void
    {
        $host = (string) $this->connection()->getConfig('host');
        $database = $this->connection()->getDatabaseName();

        if (! in_array($host, ['127.0.0.1', 'localhost', 'mysql'], true)) {
            throw new RuntimeException(
                sprintf(
                    'GateTrail MySQL integration tests refuse host [%s].',
                    $host,
                ),
            );
        }

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException(
                sprintf(
                    'GateTrail MySQL integration tests refuse database [%s]; the name must end with [_test].',
                    $database,
                ),
            );
        }

        $actual = $this->connection()->selectOne(
            'SELECT DATABASE() AS database_name',
        );
        $actualDatabase = is_object($actual)
            ? ((array) $actual)['database_name'] ?? null
            : null;

        if ($actualDatabase !== $database) {
            throw new RuntimeException(
                'The active MySQL database does not match the configured integration database.',
            );
        }
    }

    private function resetGateTrailSchema(): void
    {
        foreach ($this->tableNames(rollback: true) as $table) {
            $this->schema()->dropIfExists($table);
        }

        $this->schema()->dropIfExists('migrations');
    }

    /**
     * @return list<string>
     */
    private function tableNames(bool $rollback = false): array
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

    private function environment(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== ''
            ? $value
            : $default;
    }
}

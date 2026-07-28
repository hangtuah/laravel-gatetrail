<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Integration\Oracle;

use HangTuah\GateTrail\Database\Schema\Inspection\SchemaInspection;
use HangTuah\GateTrail\Database\Schema\Inspection\SchemaInspectorFactory;
use HangTuah\GateTrail\Database\Schema\Manifest\SchemaManifest;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\GateTrailServiceProvider;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Connection;
use RuntimeException;
use Yajra\Oci8\Oci8ServiceProvider;

abstract class OracleIntegrationTestCase extends TestCase
{
    protected const CONNECTION = 'oracle_integration';

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            Oci8ServiceProvider::class,
            GateTrailServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $service = $this->environment(
            'GATETRAIL_ORACLE_SERVICE',
            'FREEPDB1',
        );

        $app['config']->set('database.default', self::CONNECTION);
        $app['config']->set(
            'database.connections.'.self::CONNECTION,
            [
                'driver' => 'oracle',
                'tns' => '',
                'host' => $this->environment(
                    'GATETRAIL_ORACLE_HOST',
                    '127.0.0.1',
                ),
                'port' => $this->environment(
                    'GATETRAIL_ORACLE_PORT',
                    '15211',
                ),
                'database' => $service,
                'service_name' => $service,
                'username' => $this->environment(
                    'GATETRAIL_ORACLE_USERNAME',
                    'gatetrail',
                ),
                'password' => $this->environment(
                    'GATETRAIL_ORACLE_PASSWORD',
                    'gatetrail_test',
                ),
                'charset' => 'AL32UTF8',
                'prefix' => '',
                'prefix_schema' => '',
                'edition' => 'ora$base',
                'server_version' => '23c',
                'load_balance' => 'yes',
                'max_name_len' => 30,
            ],
        );
        $app['config']->set(
            'gatetrail.database.connection',
            self::CONNECTION,
        );
        $app['config']->set('gatetrail.database.schema', 'GATETRAIL');
        $app['config']->set(
            'gatetrail.database.provisioning',
            $this->provisioningMode(),
        );
        $app['config']->set(
            'gatetrail.oracle.generated_keys',
            $this->generatedKeyStrategy(),
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

    protected function connection(): Connection
    {
        return $this->app['db']->connection(self::CONNECTION);
    }

    protected function generatedKeyStrategy(): string
    {
        return 'sequence_trigger';
    }

    protected function provisioningMode(): string
    {
        return 'dba';
    }

    protected function assertGateTrailTablesExist(bool $expected): void
    {
        foreach ($this->tableNames() as $table) {
            $this->assertSame(
                $expected,
                $this->objectExists(
                    'user_tables',
                    'table_name',
                    $table,
                ),
                sprintf(
                    'Expected Oracle table [%s] existence to be [%s].',
                    $table,
                    $expected ? 'true' : 'false',
                ),
            );
        }
    }

    protected function inspectSchema(): SchemaInspection
    {
        return $this->app->make(SchemaInspectorFactory::class)
            ->forDriver('oracle', $this->connection())
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
            .'/database/ddl/oracle/0001/'
            .$this->artifactVariant().'/'
            .$direction.'.sql';
        $sql = file_get_contents($path);

        if (! is_string($sql)) {
            throw new RuntimeException(
                sprintf('Unable to read Oracle artifact [%s].', $path),
            );
        }

        foreach ($this->oracleStatements($sql, $path) as $statement) {
            $this->connection()->unprepared($statement);
        }
    }

    protected function objectExists(
        string $view,
        string $column,
        string $name,
    ): bool {
        $result = $this->connection()->selectOne(
            sprintf(
                'SELECT COUNT(*) AS aggregate FROM %s WHERE %s = ?',
                $view,
                $column,
            ),
            [strtoupper($name)],
        );

        return (int) $this->rowValue($result, 'aggregate') > 0;
    }

    protected function assertRoleGeneratedKeysWork(): void
    {
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
    }

    private function artifactVariant(): string
    {
        return match ($this->generatedKeyStrategy()) {
            'identity' => 'identity',
            'sequence_trigger' => 'sequence-trigger',
            default => throw new RuntimeException(
                sprintf(
                    'Unsupported Oracle integration generated-key strategy [%s].',
                    $this->generatedKeyStrategy(),
                ),
            ),
        };
    }

    private function assertSafeTarget(): void
    {
        $host = (string) $this->connection()->getConfig('host');
        $service = strtoupper(
            (string) $this->connection()->getConfig('service_name'),
        );
        $username = strtoupper(
            (string) $this->connection()->getConfig('username'),
        );

        if (! in_array($host, ['127.0.0.1', 'localhost', 'oracle'], true)) {
            throw new RuntimeException(
                sprintf(
                    'GateTrail Oracle integration tests refuse host [%s].',
                    $host,
                ),
            );
        }

        if ($service !== 'FREEPDB1') {
            throw new RuntimeException(
                sprintf(
                    'GateTrail Oracle integration tests refuse service [%s].',
                    $service,
                ),
            );
        }

        if (
            $username !== 'GATETRAIL'
            || in_array($username, ['SYS', 'SYSTEM'], true)
        ) {
            throw new RuntimeException(
                sprintf(
                    'GateTrail Oracle integration tests refuse user [%s].',
                    $username,
                ),
            );
        }

        $actual = $this->connection()->selectOne(
            <<<'SQL'
                SELECT
                    SYS_CONTEXT('USERENV', 'CURRENT_SCHEMA') AS schema_name,
                    SYS_CONTEXT('USERENV', 'SERVICE_NAME') AS service_name
                FROM dual
                SQL,
        );
        $actualSchema = strtoupper(
            (string) $this->rowValue($actual, 'schema_name'),
        );
        $actualService = strtoupper(
            (string) $this->rowValue($actual, 'service_name'),
        );

        if ($actualSchema !== $username || $actualService !== $service) {
            throw new RuntimeException(
                'The active Oracle schema or service does not match the configured integration target.',
            );
        }
    }

    private function resetGateTrailSchema(): void
    {
        $context = $this->app->make(SchemaContext::class);
        $manifest = $this->app->make(SchemaManifest::class);
        $options = $context->oracleOptions();

        foreach ($this->generatedTableKeys($manifest) as $tableKey) {
            $this->dropIfExists(
                'TRIGGER',
                $options->trigger($tableKey),
                'user_triggers',
                'trigger_name',
            );
        }

        $this->dropIfExists(
            'TRIGGER',
            'migrations_id_trg',
            'user_triggers',
            'trigger_name',
        );

        foreach ($this->tableNames(rollback: true) as $table) {
            $this->dropIfExists(
                'TABLE',
                $table,
                'user_tables',
                'table_name',
                ' CASCADE CONSTRAINTS PURGE',
            );
        }

        foreach ($this->generatedTableKeys($manifest) as $tableKey) {
            $this->dropIfExists(
                'SEQUENCE',
                $options->sequence($tableKey),
                'user_sequences',
                'sequence_name',
            );
        }

        $this->dropIfExists(
            'TABLE',
            'migrations',
            'user_tables',
            'table_name',
            ' CASCADE CONSTRAINTS PURGE',
        );
        $this->dropIfExists(
            'SEQUENCE',
            'migrations_id_seq',
            'user_sequences',
            'sequence_name',
        );
    }

    /**
     * @return list<string>
     */
    private function generatedTableKeys(SchemaManifest $manifest): array
    {
        $keys = [];

        foreach ($manifest->tables() as $table) {
            foreach ($table->columns as $column) {
                if ($column->generated) {
                    $keys[] = $table->key;

                    break;
                }
            }
        }

        return $keys;
    }

    private function dropIfExists(
        string $type,
        string $name,
        string $view,
        string $column,
        string $suffix = '',
    ): void {
        if (! $this->objectExists($view, $column, $name)) {
            return;
        }

        $this->connection()->unprepared(
            sprintf('DROP %s %s%s', $type, $name, $suffix),
        );
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

    /**
     * @return list<string>
     */
    private function oracleStatements(string $sql, string $path): array
    {
        $statements = [];
        $buffer = [];
        $trigger = false;

        foreach (preg_split('/\R/', $sql) ?: [] as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }

            if ($trigger && $trimmed === '/') {
                $statements[] = implode(PHP_EOL, $buffer);
                $buffer = [];
                $trigger = false;

                continue;
            }

            if ($buffer === []) {
                $trigger = str_starts_with(
                    strtoupper($trimmed),
                    'CREATE TRIGGER ',
                );
            }

            $buffer[] = $line;

            if (! $trigger && str_ends_with($trimmed, ';')) {
                $statement = implode(PHP_EOL, $buffer);
                $statements[] = substr(rtrim($statement), 0, -1);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            throw new RuntimeException(
                sprintf('Unable to parse Oracle artifact [%s].', $path),
            );
        }

        return $statements;
    }

    private function rowValue(?object $row, string $key): int|string|null
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

    private function environment(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== ''
            ? $value
            : $default;
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

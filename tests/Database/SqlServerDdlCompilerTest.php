<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Database;

use HangTuah\GateTrail\Database\Schema\Ddl\DdlCompiler;
use HangTuah\GateTrail\Database\Schema\Ddl\DdlCompilerFactory;
use HangTuah\GateTrail\Database\Schema\Ddl\SqlServer\SqlServerDdlCompiler;
use HangTuah\GateTrail\Database\Schema\Manifest\ColumnType;
use HangTuah\GateTrail\Database\Schema\Manifest\SchemaManifest;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Exceptions\InvalidDatabaseIdentifier;
use HangTuah\GateTrail\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

final class SqlServerDdlCompilerTest extends TestCase
{
    public function test_factory_resolves_the_sql_server_compiler(): void
    {
        $this->assertInstanceOf(SqlServerDdlCompiler::class, $this->compiler());
    }

    public function test_install_ddl_follows_manifest_dependency_order(): void
    {
        $statements = $this->install();
        $sql = implode("\n", $statements);

        $this->assertCount(22, $statements);
        $this->assertSame(7, substr_count($sql, 'CREATE TABLE'));
        $this->assertSame(15, substr_count($sql, 'CREATE INDEX'));
        $this->assertLessThan(
            strpos($sql, 'CREATE TABLE [acl_role_assignments]'),
            strpos($sql, 'CREATE TABLE [acl_roles]'),
        );
        $this->assertLessThan(
            strpos($sql, 'CREATE TABLE [acl_permission_assignments]'),
            strpos($sql, 'CREATE TABLE [acl_permissions]'),
        );
        $this->assertStringContainsString(
            'REFERENCES [acl_roles] ([id])',
            $sql,
        );
        $this->assertStringContainsString(
            'REFERENCES [acl_permissions] ([id])',
            $sql,
        );
    }

    public function test_rollback_ddl_is_the_exact_reverse_of_the_creation_order(): void
    {
        $rollback = $this->compiler()->compileRollback(
            $this->manifest(),
            $this->context(),
        );
        $created = [];

        foreach ($this->install() as $statement) {
            if (preg_match('/^CREATE TABLE (\[[a-z_]+\])/', $statement, $match) === 1) {
                $created[] = $match[1];
            }
        }

        $dropped = array_map(
            static fn (string $statement): string => (string) preg_replace(
                '/^DROP TABLE (\[[a-z_]+\]);$/',
                '$1',
                $statement,
            ),
            $rollback,
        );

        $this->assertCount(7, $rollback);
        $this->assertSame(array_reverse($created), $dropped);
        $this->assertSame('DROP TABLE [acl_access_audits];', $rollback[0]);
        $this->assertSame('DROP TABLE [acl_roles];', $rollback[6]);
    }

    public function test_identifiers_are_bracketed_and_never_quoted_another_way(): void
    {
        $sql = implode("\n", $this->install());

        $this->assertStringContainsString('CREATE TABLE [acl_roles]', $sql);
        $this->assertStringNotContainsString('"', $sql);
        $this->assertStringNotContainsString('`', $sql);
    }

    public function test_a_configured_schema_and_prefix_are_bracketed_per_component(): void
    {
        $this->app['config']->set('gatetrail.database.schema', 'security');
        $this->app['config']->set('gatetrail.database.prefix', 'app_');

        $sql = implode("\n", $this->install());

        $this->assertStringContainsString(
            'CREATE TABLE [security].[app_acl_roles]',
            $sql,
        );
        $this->assertStringContainsString(
            'CREATE INDEX [acl_ra_role_ix] ON [security].[app_acl_role_assignments]',
            $sql,
        );
        $this->assertStringContainsString(
            'REFERENCES [security].[app_acl_roles] ([id])',
            $sql,
        );
        $this->assertStringContainsString(
            'DROP TABLE [security].[app_acl_roles];',
            implode("\n", $this->compiler()->compileRollback(
                $this->manifest(),
                $this->context(),
            )),
        );
        $this->assertStringNotContainsString(
            '[security.app_acl_roles]',
            $sql,
        );
    }

    public function test_columns_use_unicode_types_identity_and_exact_collation(): void
    {
        $sql = implode("\n", $this->install());

        $this->assertStringContainsString(
            '[id] BIGINT IDENTITY(1,1) NOT NULL',
            $sql,
        );
        $this->assertStringContainsString(
            '[assigned_at] DATETIME2(0) NOT NULL',
            $sql,
        );
        $this->assertStringContainsString(
            '[deleted_at] DATETIME2(0) NULL',
            $sql,
        );
        $this->assertStringContainsString(
            '[scope_hash] NCHAR(64) COLLATE Latin1_General_100_BIN2 NOT NULL',
            $sql,
        );
        $this->assertStringContainsString(
            '[assigned_by_id] NVARCHAR(191) COLLATE Latin1_General_100_BIN2 NOT NULL',
            $sql,
        );
        $this->assertStringContainsString(
            '[assignment_kind] NVARCHAR(32) COLLATE Latin1_General_100_BIN2 NOT NULL',
            $sql,
        );
        $this->assertStringContainsString('[context] NVARCHAR(MAX) NULL', $sql);
        $this->assertStringContainsString(
            '[scope_key] NVARCHAR(MAX) NOT NULL',
            $sql,
        );

        // Exactly the manifest's generated columns get IDENTITY, no more.
        $generated = 0;

        foreach ($this->manifest()->tables() as $table) {
            foreach ($table->columns as $column) {
                if ($column->generated) {
                    $generated++;
                }
            }
        }

        $this->assertGreaterThan(0, $generated);
        $this->assertSame($generated, substr_count($sql, 'IDENTITY(1,1)'));
    }

    /**
     * Every character column is Unicode. A non-Unicode `VARCHAR`/`CHAR`, or the
     * deprecated `TEXT`/`NTEXT`, would silently change what the schema can
     * store.
     */
    public function test_no_non_unicode_or_deprecated_character_type_is_emitted(): void
    {
        $sql = implode("\n", $this->install());

        foreach ([
            ' VARCHAR(',
            ' CHAR(',
            ' TEXT',
            ' NTEXT',
            'LONGTEXT',
            'CLOB',
        ] as $rejected) {
            $this->assertStringNotContainsString($rejected, $sql, $rejected);
        }

        // Walk the manifest rather than counting occurrences, so the rule
        // holds for any column the manifest gains later.
        $compiled = $this->compiledColumns();
        $characterColumns = 0;

        foreach ($this->manifest()->tables() as $table) {
            $physicalTable = $this->context()->tableName($table->key);

            foreach ($table->columns as $column) {
                $definition = $compiled[$physicalTable.'.'.$column->name] ?? null;

                $this->assertNotNull($definition, $column->name);

                $unicode = match ($column->type) {
                    ColumnType::FixedString => 'NCHAR(',
                    ColumnType::String => 'NVARCHAR(',
                    ColumnType::Text => 'NVARCHAR(MAX)',
                    default => null,
                };

                if ($unicode === null) {
                    continue;
                }

                $characterColumns++;
                $this->assertStringContainsString(
                    $unicode,
                    $definition,
                    $physicalTable.'.'.$column->name,
                );
            }
        }

        $this->assertGreaterThan(0, $characterColumns);
    }

    /**
     * Only the columns the manifest marks for exact comparison are pinned to the
     * binary collation. Everything else inherits the deployment's own default,
     * which is the accepted design.
     */
    public function test_only_exact_comparison_columns_are_binary_collated(): void
    {
        $sql = implode("\n", $this->install());
        $compiled = $this->compiledColumns();
        $collated = 0;

        foreach ($this->manifest()->tables() as $table) {
            $physicalTable = $this->context()->tableName($table->key);

            foreach ($table->columns as $column) {
                $object = $physicalTable.'.'.$column->name;
                $definition = $compiled[$object] ?? null;

                $this->assertNotNull($definition, $object);

                $expected = $column->exactComparison && in_array(
                    $column->type,
                    [ColumnType::FixedString, ColumnType::String],
                    true,
                );

                if ($expected) {
                    $collated++;
                    $this->assertStringContainsString(
                        'COLLATE Latin1_General_100_BIN2',
                        $definition,
                        $object,
                    );

                    continue;
                }

                $this->assertStringNotContainsString(
                    'COLLATE',
                    $definition,
                    $object,
                );
            }
        }

        $this->assertGreaterThan(0, $collated);
        $this->assertSame(
            $collated,
            substr_count($sql, 'COLLATE Latin1_General_100_BIN2'),
        );
        $this->assertStringContainsString(
            '[ip_address] NVARCHAR(45) NULL',
            $sql,
        );
        $this->assertStringContainsString(
            '[request_id] NVARCHAR(191) NULL',
            $sql,
        );
        $this->assertStringContainsString(
            '[user_agent] NVARCHAR(MAX) NULL',
            $sql,
        );
        $this->assertStringNotContainsString(
            '[user_agent] NVARCHAR(MAX) COLLATE',
            $sql,
        );
    }

    public function test_every_constraint_type_is_explicitly_named(): void
    {
        $statements = $this->install();
        $roles = $statements[0];
        $roleAssignments = $statements[3];

        $this->assertStringContainsString(
            'CONSTRAINT [acl_roles_pk] PRIMARY KEY ([id])',
            $roles,
        );
        $this->assertStringContainsString(
            'CONSTRAINT [acl_role_name_guard_uq] UNIQUE ([name], [guard_name])',
            $roles,
        );
        $this->assertStringContainsString(
            'CONSTRAINT [acl_ra_role_fk] FOREIGN KEY ([role_id])',
            $roleAssignments,
        );
        $this->assertStringContainsString(
            'CONSTRAINT [acl_ra_period_ck] CHECK (',
            $roleAssignments,
        );

        $sql = implode("\n", $statements);

        $this->assertSame(7, substr_count($sql, 'PRIMARY KEY ('));
        $this->assertSame(2, substr_count($sql, 'UNIQUE ('));
        $this->assertSame(4, substr_count($sql, 'FOREIGN KEY ('));
        $this->assertSame(7, substr_count($sql, 'CHECK ('));
    }

    public function test_foreign_keys_use_no_action_and_never_cascade(): void
    {
        $sql = implode("\n", $this->install());

        $this->assertSame(
            4,
            substr_count($sql, 'ON DELETE NO ACTION ON UPDATE NO ACTION'),
        );

        foreach ([
            'CASCADE',
            'SET NULL',
            'SET DEFAULT',
            'RESTRICT',
        ] as $rejected) {
            $this->assertStringNotContainsString($rejected, $sql, $rejected);
        }
    }

    /**
     * The artifacts must stay executable through any ordinary client, so no
     * SQLCMD batch separator, dynamic SQL, procedural object or existence guard
     * is emitted.
     */
    public function test_no_batch_separator_guard_or_procedural_object_is_emitted(): void
    {
        $statements = [
            ...$this->install(),
            ...$this->compiler()->compileRollback(
                $this->manifest(),
                $this->context(),
            ),
        ];
        $sql = implode("\n", $statements);

        foreach ([
            'IF NOT EXISTS',
            'IF EXISTS',
            'EXEC',
            'sp_',
            'CREATE TRIGGER',
            'CREATE PROCEDURE',
            'WITH NOCHECK',
            'NOT FOR REPLICATION',
            'sysname',
        ] as $rejected) {
            $this->assertStringNotContainsString($rejected, $sql, $rejected);
        }

        foreach (preg_split('/\R/', $sql) ?: [] as $line) {
            $this->assertNotSame('GO', trim($line));
        }

        foreach ($statements as $statement) {
            $this->assertStringEndsWith(';', $statement);
        }
    }

    public function test_explicit_indexes_preserve_their_manifest_column_order(): void
    {
        $statements = $this->install();

        $this->assertStringNotContainsString('CREATE INDEX', $statements[3]);
        $this->assertSame(
            'CREATE INDEX [acl_ra_subject_ix] ON [acl_role_assignments] ([subject_type], [subject_id], [revoked_at], [starts_at], [expires_at]);',
            $statements[4],
        );

        $compiled = [];

        foreach ($statements as $statement) {
            if (preg_match(
                '/^CREATE INDEX \[([a-z_]+)\] ON \[[a-z_]+\] \((.+)\);$/',
                $statement,
                $match,
            ) === 1) {
                $compiled[$match[1]] = array_map(
                    static fn (string $column): string => trim($column, '[] '),
                    explode(',', $match[2]),
                );
            }
        }

        $expected = [];

        foreach ($this->manifest()->tables() as $table) {
            foreach ($table->indexes as $index) {
                $expected[$index->name] = $index->columns;
            }
        }

        $this->assertCount(15, $compiled);
        $this->assertSame($expected, $compiled);
    }

    public function test_every_emitted_identifier_stays_within_the_portable_length(): void
    {
        $statements = [
            ...$this->install(),
            ...$this->compiler()->compileRollback(
                $this->manifest(),
                $this->context(),
            ),
        ];

        preg_match_all(
            '/\[([^\]]+)\]/',
            implode("\n", $statements),
            $identifiers,
        );

        $this->assertNotEmpty($identifiers[1]);

        foreach (array_unique($identifiers[1]) as $identifier) {
            $this->assertLessThanOrEqual(
                30,
                strlen($identifier),
                $identifier,
            );
        }
    }

    /**
     * @return array<string, array{string, string, class-string<\Throwable>}>
     */
    public static function nonPortableIdentifiers(): array
    {
        return [
            'schema with a hyphen' => [
                'schema', 'gate-trail', InvalidDatabaseIdentifier::class,
            ],
            'schema with a quote' => [
                'schema', 'gate"trail', InvalidDatabaseIdentifier::class,
            ],
            'schema with a bracket' => [
                'schema', 'gate]trail', InvalidDatabaseIdentifier::class,
            ],
            'schema that is too long' => [
                'schema',
                'gatetrail_security_schema_name_x',
                InvalidDatabaseIdentifier::class,
            ],
            // A bad prefix is rejected by SchemaContext itself, which raises the
            // base InvalidArgumentException rather than the identifier subclass.
            'prefix with a bracket' => [
                'prefix', 'app]_', InvalidArgumentException::class,
            ],
            'prefix with a space' => [
                'prefix', 'app ', InvalidArgumentException::class,
            ],
        ];
    }

    /**
     * @param  class-string<\Throwable>  $expected
     */
    #[DataProvider('nonPortableIdentifiers')]
    public function test_non_portable_configuration_still_fails_before_compiling(
        string $key,
        string $value,
        string $expected,
    ): void {
        $this->app['config']->set('gatetrail.database.'.$key, $value);

        $this->expectException($expected);

        $this->install();
    }

    public function test_a_non_portable_table_name_still_fails_before_compiling(): void
    {
        $this->app['config']->set('gatetrail.tables.roles', 'acl roles');

        $this->expectException(InvalidDatabaseIdentifier::class);

        $this->install();
    }

    /**
     * Every compiled column definition, keyed by `table.column`, so a test can
     * assert per-column behaviour against the manifest instead of counting
     * substrings across the whole script.
     *
     * @return array<string, string>
     */
    private function compiledColumns(): array
    {
        $columns = [];
        $table = null;

        foreach ($this->install() as $statement) {
            if (preg_match('/^CREATE TABLE \[([a-z_]+)\]/', $statement, $match) !== 1) {
                continue;
            }

            $table = $match[1];

            foreach (preg_split('/\R/', $statement) ?: [] as $line) {
                $line = rtrim(trim($line), ',');

                if (preg_match('/^\[([a-z_]+)\] /', $line, $column) !== 1) {
                    continue;
                }

                $columns[$table.'.'.$column[1]] = $line;
            }
        }

        $this->assertNotNull($table);

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function install(): array
    {
        return $this->compiler()->compileInstall(
            $this->manifest(),
            $this->context(),
        );
    }

    private function compiler(): DdlCompiler
    {
        return $this->app->make(DdlCompilerFactory::class)
            ->forDriver('sqlsrv');
    }

    private function manifest(): SchemaManifest
    {
        return $this->app->make(SchemaManifest::class);
    }

    private function context(): SchemaContext
    {
        return $this->app->make(SchemaContext::class);
    }
}

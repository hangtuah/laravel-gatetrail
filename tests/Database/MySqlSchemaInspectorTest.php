<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Database;

use HangTuah\GateTrail\Database\Schema\Inspection\MySql\MySqlCatalogReader;
use HangTuah\GateTrail\Database\Schema\Inspection\MySql\MySqlCatalogSnapshot;
use HangTuah\GateTrail\Database\Schema\Inspection\MySql\MySqlColumnMetadata;
use HangTuah\GateTrail\Database\Schema\Inspection\MySql\MySqlConstraintMetadata;
use HangTuah\GateTrail\Database\Schema\Inspection\MySql\MySqlIndexMetadata;
use HangTuah\GateTrail\Database\Schema\Inspection\MySql\MySqlSchemaInspector;
use HangTuah\GateTrail\Database\Schema\Inspection\SchemaInspection;
use HangTuah\GateTrail\Database\Schema\Manifest\ColumnDefinition;
use HangTuah\GateTrail\Database\Schema\Manifest\ColumnType;
use HangTuah\GateTrail\Database\Schema\Manifest\ConstraintDefinition;
use HangTuah\GateTrail\Database\Schema\Manifest\ConstraintType;
use HangTuah\GateTrail\Database\Schema\Manifest\SchemaManifest;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Tests\TestCase;

final class MySqlSchemaInspectorTest extends TestCase
{
    public function test_a_matching_catalog_passes_without_issues(): void
    {
        $inspection = $this->inspect($this->matchingSnapshot());

        $this->assertTrue($inspection->passes());
        $this->assertSame([], $inspection->issues);
    }

    public function test_a_missing_table_is_reported_without_mutating_the_catalog(): void
    {
        $snapshot = $this->matchingSnapshot();
        $snapshot = new MySqlCatalogSnapshot(
            tables: array_values(array_filter(
                $snapshot->tables,
                static fn (string $table): bool => $table !== 'acl_roles',
            )),
            columns: $snapshot->columns,
            indexes: $snapshot->indexes,
            constraints: $snapshot->constraints,
        );

        $inspection = $this->inspect($snapshot);

        $this->assertFalse($inspection->passes());
        $this->assertSame('missing_table', $inspection->issues[0]->code);
        $this->assertSame('acl_roles', $inspection->issues[0]->object);
    }

    public function test_column_index_and_constraint_drift_are_reported(): void
    {
        $snapshot = $this->matchingSnapshot();
        $columns = array_map(
            static fn (MySqlColumnMetadata $column): MySqlColumnMetadata => (
                $column->table === 'acl_roles' && $column->name === 'name'
            ) ? new MySqlColumnMetadata(
                table: $column->table,
                name: $column->name,
                dataType: 'text',
                columnType: 'text',
                nullable: true,
                length: null,
                extra: 'auto_increment',
                collation: 'utf8mb4_unicode_ci',
            ) : $column,
            $snapshot->columns,
        );
        $indexes = array_values(array_filter(
            $snapshot->indexes,
            static fn (MySqlIndexMetadata $index): bool => (
                $index->name !== 'acl_ra_subject_ix'
            ),
        ));
        $constraints = array_values(array_filter(
            $snapshot->constraints,
            static fn (MySqlConstraintMetadata $constraint): bool => (
                $constraint->name !== 'acl_ra_period_ck'
            ),
        ));
        $snapshot = new MySqlCatalogSnapshot(
            $snapshot->tables,
            $columns,
            $indexes,
            $constraints,
        );

        $inspection = $this->inspect($snapshot);
        $codes = array_map(
            static fn ($issue): string => $issue->code,
            $inspection->issues,
        );

        $this->assertFalse($inspection->passes());
        $this->assertContains('column_type_mismatch', $codes);
        $this->assertContains('column_nullability_mismatch', $codes);
        $this->assertContains('column_length_mismatch', $codes);
        $this->assertContains('column_generation_mismatch', $codes);
        $this->assertContains('column_collation_mismatch', $codes);
        $this->assertContains('missing_index', $codes);
        $this->assertContains('missing_constraint', $codes);
    }

    public function test_an_unexpected_column_fails_strict_conformance(): void
    {
        $snapshot = $this->matchingSnapshot();
        $snapshot = new MySqlCatalogSnapshot(
            $snapshot->tables,
            [
                ...$snapshot->columns,
                new MySqlColumnMetadata(
                    table: 'acl_roles',
                    name: 'legacy_flag',
                    dataType: 'bigint',
                    columnType: 'bigint unsigned',
                    nullable: true,
                    length: null,
                    extra: '',
                    collation: null,
                ),
            ],
            $snapshot->indexes,
            $snapshot->constraints,
        );

        $inspection = $this->inspect($snapshot);

        $this->assertFalse($inspection->passes());
        $this->assertContains(
            'unexpected_column',
            array_map(
                static fn ($issue): string => $issue->code,
                $inspection->issues,
            ),
        );
    }

    public function test_foreign_key_and_check_expression_drift_are_reported(): void
    {
        $snapshot = $this->matchingSnapshot();
        $constraints = array_map(
            static function (
                MySqlConstraintMetadata $constraint,
            ): MySqlConstraintMetadata {
                if ($constraint->name === 'acl_ra_role_fk') {
                    return new MySqlConstraintMetadata(
                        table: $constraint->table,
                        name: $constraint->name,
                        type: $constraint->type,
                        columns: $constraint->columns,
                        referencedTable: 'legacy_roles',
                        referencedColumns: ['legacy_id'],
                        deleteRule: 'CASCADE',
                        updateRule: 'CASCADE',
                    );
                }

                if ($constraint->name === 'acl_ra_period_ck') {
                    return new MySqlConstraintMetadata(
                        table: $constraint->table,
                        name: $constraint->name,
                        type: $constraint->type,
                        columns: $constraint->columns,
                        checkClause: 'expires_at IS NULL',
                    );
                }

                return $constraint;
            },
            $snapshot->constraints,
        );
        $snapshot = new MySqlCatalogSnapshot(
            $snapshot->tables,
            $snapshot->columns,
            $snapshot->indexes,
            $constraints,
        );

        $codes = array_map(
            static fn ($issue): string => $issue->code,
            $this->inspect($snapshot)->issues,
        );

        $this->assertContains('foreign_reference_mismatch', $codes);
        $this->assertContains('foreign_rule_mismatch', $codes);
        $this->assertContains('check_expression_mismatch', $codes);
    }

    private function inspect(
        MySqlCatalogSnapshot $snapshot,
    ): SchemaInspection {
        $reader = new class($snapshot) implements MySqlCatalogReader
        {
            public function __construct(
                private readonly MySqlCatalogSnapshot $snapshot,
            ) {}

            public function read(SchemaContext $context): MySqlCatalogSnapshot
            {
                return $this->snapshot;
            }
        };

        return (new MySqlSchemaInspector($reader))->inspect(
            $this->manifest(),
            $this->context(),
        );
    }

    private function matchingSnapshot(): MySqlCatalogSnapshot
    {
        $tables = [];
        $columns = [];
        $indexes = [];
        $constraints = [];
        $context = $this->context();

        foreach ($this->manifest()->tables() as $table) {
            $physicalTable = $context->tableName($table->key);
            $tables[] = $physicalTable;

            foreach ($table->columns as $column) {
                $columns[] = $this->columnMetadata($physicalTable, $column);
            }

            foreach ($table->indexes as $index) {
                $indexes[] = new MySqlIndexMetadata(
                    table: $physicalTable,
                    name: $index->name,
                    columns: $index->columns,
                    unique: false,
                );
            }

            foreach ($table->constraints as $constraint) {
                $constraints[] = $this->constraintMetadata(
                    $physicalTable,
                    $constraint,
                    $context,
                );
            }
        }

        return new MySqlCatalogSnapshot(
            $tables,
            $columns,
            $indexes,
            $constraints,
        );
    }

    private function columnMetadata(
        string $table,
        ColumnDefinition $column,
    ): MySqlColumnMetadata {
        [$dataType, $columnType] = match ($column->type) {
            ColumnType::BigInteger => ['bigint', 'bigint unsigned'],
            ColumnType::DateTime => ['datetime', 'datetime'],
            ColumnType::String => [
                'varchar',
                sprintf('varchar(%d)', $column->length ?? 191),
            ],
            ColumnType::Text => ['longtext', 'longtext'],
        };

        return new MySqlColumnMetadata(
            table: $table,
            name: $column->name,
            dataType: $dataType,
            columnType: $columnType,
            nullable: $column->nullable,
            length: $column->type === ColumnType::String
                ? $column->length
                : null,
            extra: $column->generated ? 'auto_increment' : '',
            collation: $column->type === ColumnType::String
                ? ($column->exactComparison
                    ? 'utf8mb4_bin'
                    : 'utf8mb4_unicode_ci')
                : null,
        );
    }

    private function constraintMetadata(
        string $table,
        ConstraintDefinition $constraint,
        SchemaContext $context,
    ): MySqlConstraintMetadata {
        return new MySqlConstraintMetadata(
            table: $table,
            name: $constraint->type === ConstraintType::Primary
                ? 'PRIMARY'
                : $constraint->name,
            type: match ($constraint->type) {
                ConstraintType::Primary => 'PRIMARY KEY',
                ConstraintType::Unique => 'UNIQUE',
                ConstraintType::Foreign => 'FOREIGN KEY',
                ConstraintType::Check => 'CHECK',
            },
            columns: $constraint->columns,
            referencedTable: $constraint->referencedTable === null
                ? null
                : $context->tableName($constraint->referencedTable),
            referencedColumns: $constraint->referencedColumns,
            deleteRule: $constraint->type === ConstraintType::Foreign
                ? 'RESTRICT'
                : null,
            updateRule: $constraint->type === ConstraintType::Foreign
                ? 'RESTRICT'
                : null,
            checkClause: $constraint->expression,
        );
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

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Inspection\MySql;

use HangTuah\GateTrail\Database\Schema\Inspection\SchemaInspection;
use HangTuah\GateTrail\Database\Schema\Inspection\SchemaInspector;
use HangTuah\GateTrail\Database\Schema\Inspection\SchemaIssue;
use HangTuah\GateTrail\Database\Schema\Manifest\ColumnDefinition;
use HangTuah\GateTrail\Database\Schema\Manifest\ColumnType;
use HangTuah\GateTrail\Database\Schema\Manifest\ConstraintDefinition;
use HangTuah\GateTrail\Database\Schema\Manifest\ConstraintType;
use HangTuah\GateTrail\Database\Schema\Manifest\IndexDefinition;
use HangTuah\GateTrail\Database\Schema\Manifest\SchemaManifest;
use HangTuah\GateTrail\Database\Schema\Manifest\TableDefinition;
use HangTuah\GateTrail\Database\Schema\SchemaContext;

final readonly class MySqlSchemaInspector implements SchemaInspector
{
    public function __construct(
        private MySqlCatalogReader $catalog,
    ) {}

    public function inspect(
        SchemaManifest $manifest,
        SchemaContext $context,
    ): SchemaInspection {
        $snapshot = $this->catalog->read($context);
        $issues = [];

        foreach ($manifest->tables() as $table) {
            $physicalTable = $context->tableName($table->key);

            if (! $snapshot->hasTable($physicalTable)) {
                $issues[] = new SchemaIssue(
                    code: 'missing_table',
                    object: $physicalTable,
                    message: sprintf(
                        'Required table [%s] is absent.',
                        $physicalTable,
                    ),
                );

                continue;
            }

            $issues = [
                ...$issues,
                ...$this->inspectColumns($table, $physicalTable, $snapshot),
                ...$this->inspectIndexes($table, $physicalTable, $snapshot),
                ...$this->inspectConstraints(
                    $table,
                    $physicalTable,
                    $snapshot,
                    $context,
                ),
            ];
        }

        return new SchemaInspection($issues);
    }

    /**
     * @return list<SchemaIssue>
     */
    private function inspectColumns(
        TableDefinition $table,
        string $physicalTable,
        MySqlCatalogSnapshot $snapshot,
    ): array {
        $issues = [];

        foreach ($table->columns as $expected) {
            $actual = $snapshot->column($physicalTable, $expected->name);
            $object = $physicalTable.'.'.$expected->name;

            if ($actual === null) {
                $issues[] = new SchemaIssue(
                    code: 'missing_column',
                    object: $object,
                    message: sprintf('Required column [%s] is absent.', $object),
                );

                continue;
            }

            $issues = [
                ...$issues,
                ...$this->inspectColumn($expected, $actual, $object),
            ];
        }

        foreach ($snapshot->columns as $actual) {
            if (
                $actual->table === $physicalTable
                && ! in_array($actual->name, $table->columnNames(), true)
            ) {
                $issues[] = new SchemaIssue(
                    code: 'unexpected_column',
                    object: $physicalTable.'.'.$actual->name,
                    message: sprintf(
                        'Column [%s.%s] is not part of the canonical schema.',
                        $physicalTable,
                        $actual->name,
                    ),
                );
            }
        }

        return $issues;
    }

    /**
     * @return list<SchemaIssue>
     */
    private function inspectColumn(
        ColumnDefinition $expected,
        MySqlColumnMetadata $actual,
        string $object,
    ): array {
        $issues = [];

        if (! $this->hasExpectedType($expected, $actual)) {
            $issues[] = new SchemaIssue(
                code: 'column_type_mismatch',
                object: $object,
                message: sprintf(
                    'Column [%s] has MySQL type [%s]; expected [%s].',
                    $object,
                    $actual->columnType,
                    $this->expectedType($expected),
                ),
            );
        }

        if ($actual->nullable !== $expected->nullable) {
            $issues[] = new SchemaIssue(
                code: 'column_nullability_mismatch',
                object: $object,
                message: sprintf(
                    'Column [%s] nullability does not match the canonical schema.',
                    $object,
                ),
            );
        }

        if (
            in_array(
                $expected->type,
                [ColumnType::FixedString, ColumnType::String],
                true,
            )
            && $actual->length !== $expected->length
        ) {
            $issues[] = new SchemaIssue(
                code: 'column_length_mismatch',
                object: $object,
                message: sprintf(
                    'Column [%s] length is [%s]; expected [%d].',
                    $object,
                    $actual->length === null ? 'null' : (string) $actual->length,
                    $expected->length ?? 191,
                ),
            );
        }

        $autoIncrement = str_contains($actual->extra, 'auto_increment');

        if ($autoIncrement !== $expected->generated) {
            $issues[] = new SchemaIssue(
                code: 'column_generation_mismatch',
                object: $object,
                message: sprintf(
                    'Column [%s] auto-increment behavior does not match the canonical schema.',
                    $object,
                ),
            );
        }

        if (
            $expected->exactComparison
            && strtolower((string) $actual->collation) !== 'utf8mb4_bin'
        ) {
            $issues[] = new SchemaIssue(
                code: 'column_collation_mismatch',
                object: $object,
                message: sprintf(
                    'Column [%s] requires the [utf8mb4_bin] collation.',
                    $object,
                ),
            );
        }

        return $issues;
    }

    /**
     * @return list<SchemaIssue>
     */
    private function inspectIndexes(
        TableDefinition $table,
        string $physicalTable,
        MySqlCatalogSnapshot $snapshot,
    ): array {
        $issues = [];

        foreach ($table->indexes as $expected) {
            $actual = $snapshot->index($physicalTable, $expected->name);

            if ($actual === null) {
                $issues[] = $this->missingIndex($physicalTable, $expected);

                continue;
            }

            if ($actual->columns !== $expected->columns || $actual->unique) {
                $issues[] = new SchemaIssue(
                    code: 'index_definition_mismatch',
                    object: $physicalTable.'.'.$expected->name,
                    message: sprintf(
                        'Index [%s.%s] does not match its canonical ordered columns or uniqueness.',
                        $physicalTable,
                        $expected->name,
                    ),
                );
            }
        }

        return $issues;
    }

    /**
     * @return list<SchemaIssue>
     */
    private function inspectConstraints(
        TableDefinition $table,
        string $physicalTable,
        MySqlCatalogSnapshot $snapshot,
        SchemaContext $context,
    ): array {
        $issues = [];

        foreach ($table->constraints as $expected) {
            $name = $expected->type === ConstraintType::Primary
                ? 'PRIMARY'
                : $expected->name;
            $actual = $snapshot->constraint($physicalTable, $name);
            $object = $physicalTable.'.'.$name;

            if ($actual === null) {
                $issues[] = new SchemaIssue(
                    code: 'missing_constraint',
                    object: $object,
                    message: sprintf(
                        'Required constraint [%s] is absent.',
                        $object,
                    ),
                );

                continue;
            }

            if ($actual->type !== $this->expectedConstraintType($expected)) {
                $issues[] = new SchemaIssue(
                    code: 'constraint_type_mismatch',
                    object: $object,
                    message: sprintf(
                        'Constraint [%s] has an unexpected type [%s].',
                        $object,
                        $actual->type,
                    ),
                );

                continue;
            }

            if (
                $expected->type !== ConstraintType::Check
                && $actual->columns !== $expected->columns
            ) {
                $issues[] = new SchemaIssue(
                    code: 'constraint_columns_mismatch',
                    object: $object,
                    message: sprintf(
                        'Constraint [%s] does not use the canonical ordered columns.',
                        $object,
                    ),
                );
            }

            if ($expected->type === ConstraintType::Foreign) {
                $issues = [
                    ...$issues,
                    ...$this->inspectForeign(
                        $expected,
                        $actual,
                        $object,
                        $context,
                    ),
                ];
            }

            if (
                $expected->type === ConstraintType::Check
                && $this->normalizeCheck($actual->checkClause)
                    !== $this->normalizeCheck($expected->expression)
            ) {
                $issues[] = new SchemaIssue(
                    code: 'check_expression_mismatch',
                    object: $object,
                    message: sprintf(
                        'Check constraint [%s] does not match the canonical expression.',
                        $object,
                    ),
                );
            }
        }

        return $issues;
    }

    /**
     * @return list<SchemaIssue>
     */
    private function inspectForeign(
        ConstraintDefinition $expected,
        MySqlConstraintMetadata $actual,
        string $object,
        SchemaContext $context,
    ): array {
        $referencedTable = $expected->referencedTable === null
            ? null
            : $context->tableName($expected->referencedTable);
        $issues = [];

        if (
            $actual->referencedTable !== $referencedTable
            || $actual->referencedColumns !== $expected->referencedColumns
        ) {
            $issues[] = new SchemaIssue(
                code: 'foreign_reference_mismatch',
                object: $object,
                message: sprintf(
                    'Foreign key [%s] does not reference the canonical table and columns.',
                    $object,
                ),
            );
        }

        if (
            ! $this->isRestrictiveRule($actual->deleteRule)
            || ! $this->isRestrictiveRule($actual->updateRule)
        ) {
            $issues[] = new SchemaIssue(
                code: 'foreign_rule_mismatch',
                object: $object,
                message: sprintf(
                    'Foreign key [%s] must restrict delete and update operations.',
                    $object,
                ),
            );
        }

        return $issues;
    }

    private function hasExpectedType(
        ColumnDefinition $expected,
        MySqlColumnMetadata $actual,
    ): bool {
        return match ($expected->type) {
            ColumnType::BigInteger => $actual->dataType === 'bigint'
                && str_contains($actual->columnType, 'unsigned'),
            ColumnType::DateTime => $actual->dataType === 'datetime',
            ColumnType::FixedString => $actual->dataType === 'char',
            ColumnType::String => $actual->dataType === 'varchar',
            ColumnType::Text => $actual->dataType === 'longtext',
        };
    }

    private function expectedType(ColumnDefinition $expected): string
    {
        return match ($expected->type) {
            ColumnType::BigInteger => 'bigint unsigned',
            ColumnType::DateTime => 'datetime',
            ColumnType::FixedString => sprintf(
                'char(%d)',
                $expected->length ?? 1,
            ),
            ColumnType::String => sprintf(
                'varchar(%d)',
                $expected->length ?? 191,
            ),
            ColumnType::Text => 'longtext',
        };
    }

    private function expectedConstraintType(
        ConstraintDefinition $constraint,
    ): string {
        return match ($constraint->type) {
            ConstraintType::Primary => 'PRIMARY KEY',
            ConstraintType::Unique => 'UNIQUE',
            ConstraintType::Foreign => 'FOREIGN KEY',
            ConstraintType::Check => 'CHECK',
        };
    }

    private function missingIndex(
        string $physicalTable,
        IndexDefinition $index,
    ): SchemaIssue {
        return new SchemaIssue(
            code: 'missing_index',
            object: $physicalTable.'.'.$index->name,
            message: sprintf(
                'Required index [%s.%s] is absent.',
                $physicalTable,
                $index->name,
            ),
        );
    }

    private function isRestrictiveRule(?string $rule): bool
    {
        return in_array(strtoupper((string) $rule), [
            'RESTRICT',
            'NO ACTION',
        ], true);
    }

    private function normalizeCheck(?string $expression): string
    {
        $normalized = strtolower(str_replace('`', '', (string) $expression));
        $normalized = preg_replace('/\s+/', '', $normalized) ?? $normalized;

        while ($this->hasRedundantOuterParentheses($normalized)) {
            $normalized = substr($normalized, 1, -1);
        }

        return $normalized;
    }

    private function hasRedundantOuterParentheses(string $expression): bool
    {
        if (
            strlen($expression) < 2
            || $expression[0] !== '('
            || $expression[-1] !== ')'
        ) {
            return false;
        }

        $depth = 0;
        $last = strlen($expression) - 1;

        for ($position = 0; $position <= $last; $position++) {
            if ($expression[$position] === '(') {
                $depth++;
            } elseif ($expression[$position] === ')') {
                $depth--;
            }

            if ($depth === 0 && $position < $last) {
                return false;
            }
        }

        return $depth === 0;
    }
}

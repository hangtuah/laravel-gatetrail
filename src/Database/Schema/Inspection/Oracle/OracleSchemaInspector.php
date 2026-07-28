<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Inspection\Oracle;

use HangTuah\GateTrail\Database\Schema\Ddl\Oracle\OracleGeneratedKeyStrategy;
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

final readonly class OracleSchemaInspector implements SchemaInspector
{
    public function __construct(
        private OracleCatalogReader $catalog,
    ) {}

    public function inspect(
        SchemaManifest $manifest,
        SchemaContext $context,
    ): SchemaInspection {
        $snapshot = $this->catalog->read($context);
        $issues = [];

        foreach ($manifest->tables() as $table) {
            $physicalTable = $this->expected($context->tableName($table->key));

            if (! $snapshot->hasTable($physicalTable)) {
                $issues[] = new SchemaIssue(
                    code: 'missing_table',
                    object: $physicalTable,
                    message: sprintf(
                        'Required Oracle table [%s] is absent.',
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

        $issues = [
            ...$issues,
            ...$this->inspectGeneratedKeys($manifest, $context, $snapshot),
        ];

        return new SchemaInspection($issues);
    }

    /**
     * @return list<SchemaIssue>
     */
    private function inspectColumns(
        TableDefinition $table,
        string $physicalTable,
        OracleCatalogSnapshot $snapshot,
    ): array {
        $issues = [];

        foreach ($table->columns as $expected) {
            $columnName = $this->expected($expected->name);
            $actual = $snapshot->column($physicalTable, $columnName);
            $object = $physicalTable.'.'.$columnName;

            if ($actual === null) {
                $issues[] = new SchemaIssue(
                    code: 'missing_column',
                    object: $object,
                    message: sprintf('Required column [%s] is absent.', $object),
                );

                continue;
            }

            if (! $this->hasExpectedType($expected, $actual)) {
                $issues[] = new SchemaIssue(
                    code: 'column_type_mismatch',
                    object: $object,
                    message: sprintf(
                        'Column [%s] has Oracle type [%s]; expected [%s].',
                        $object,
                        $actual->dataType,
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
                && (
                    $actual->charLength !== $expected->length
                    || $actual->charUsed !== 'C'
                )
            ) {
                $issues[] = new SchemaIssue(
                    code: 'column_length_mismatch',
                    object: $object,
                    message: sprintf(
                        'Column [%s] must use the canonical character length and CHAR semantics.',
                        $object,
                    ),
                );
            }
        }

        $expectedNames = array_map(
            fn (string $name): string => $this->expected($name),
            $table->columnNames(),
        );

        foreach ($snapshot->columns as $actual) {
            if (
                $actual->table === $physicalTable
                && ! in_array($actual->name, $expectedNames, true)
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
    private function inspectIndexes(
        TableDefinition $table,
        string $physicalTable,
        OracleCatalogSnapshot $snapshot,
    ): array {
        $issues = [];

        foreach ($table->indexes as $expected) {
            $name = $this->expected($expected->name);
            $actual = $snapshot->index($physicalTable, $name);
            $object = $physicalTable.'.'.$name;

            if ($actual === null) {
                $issues[] = $this->missingIndex($physicalTable, $expected);

                continue;
            }

            if (
                $actual->columns !== $this->expectedList($expected->columns)
                || $actual->unique
                || $actual->status !== 'VALID'
            ) {
                $issues[] = new SchemaIssue(
                    code: 'index_definition_mismatch',
                    object: $object,
                    message: sprintf(
                        'Index [%s] does not match its canonical columns, uniqueness, or valid status.',
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
    private function inspectConstraints(
        TableDefinition $table,
        string $physicalTable,
        OracleCatalogSnapshot $snapshot,
        SchemaContext $context,
    ): array {
        $issues = [];

        foreach ($table->constraints as $expected) {
            $name = $this->expected($expected->name);
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
                        'Constraint [%s] has unexpected Oracle type [%s].',
                        $object,
                        $actual->type,
                    ),
                );

                continue;
            }

            if (
                $expected->type !== ConstraintType::Check
                && $actual->columns !== $this->expectedList($expected->columns)
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

            if ($actual->status !== 'ENABLED' || $actual->validated !== 'VALIDATED') {
                $issues[] = new SchemaIssue(
                    code: 'constraint_state_mismatch',
                    object: $object,
                    message: sprintf(
                        'Constraint [%s] must be enabled and validated.',
                        $object,
                    ),
                );
            }

            if ($expected->type === ConstraintType::Foreign) {
                $referencedTable = $expected->referencedTable === null
                    ? null
                    : $this->expected(
                        $context->tableName($expected->referencedTable),
                    );

                if (
                    $actual->referencedTable !== $referencedTable
                    || $actual->referencedColumns
                        !== $this->expectedList($expected->referencedColumns)
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

                if ($actual->deleteRule !== 'NO ACTION') {
                    $issues[] = new SchemaIssue(
                        code: 'foreign_rule_mismatch',
                        object: $object,
                        message: sprintf(
                            'Foreign key [%s] must use Oracle NO ACTION delete behavior.',
                            $object,
                        ),
                    );
                }
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
    private function inspectGeneratedKeys(
        SchemaManifest $manifest,
        SchemaContext $context,
        OracleCatalogSnapshot $snapshot,
    ): array {
        $options = $context->oracleOptions();
        $issues = [];

        foreach ($this->generatedTables($manifest) as $tableKey => $column) {
            $table = $this->expected($context->tableName($tableKey));
            $columnName = $this->expected($column->name);
            $actualColumn = $snapshot->column($table, $columnName);

            if ($actualColumn === null) {
                continue;
            }

            if ($options->generatedKeys === OracleGeneratedKeyStrategy::Identity) {
                $identity = $snapshot->identity($table, $columnName);

                if (
                    ! $actualColumn->identity
                    || ! $actualColumn->defaultOnNull
                    || $identity === null
                    || $identity->generationType !== 'BY DEFAULT'
                ) {
                    $issues[] = new SchemaIssue(
                        code: 'identity_strategy_mismatch',
                        object: $table.'.'.$columnName,
                        message: sprintf(
                            'Column [%s.%s] must use BY DEFAULT ON NULL identity generation.',
                            $table,
                            $columnName,
                        ),
                    );
                }

                foreach ([
                    $this->expected($options->sequence($tableKey)),
                    $this->expected($options->trigger($tableKey)),
                ] as $objectName) {
                    if (
                        $snapshot->sequence($objectName) !== null
                        || $snapshot->trigger($objectName) !== null
                    ) {
                        $issues[] = new SchemaIssue(
                            code: 'mixed_generated_key_strategy',
                            object: $objectName,
                            message: sprintf(
                                'Object [%s] conflicts with the configured identity strategy.',
                                $objectName,
                            ),
                        );
                    }
                }

                continue;
            }

            if (
                $actualColumn->identity
                || $snapshot->identity($table, $columnName) !== null
            ) {
                $issues[] = new SchemaIssue(
                    code: 'mixed_generated_key_strategy',
                    object: $table.'.'.$columnName,
                    message: sprintf(
                        'Column [%s.%s] must not be an identity column in sequence-trigger mode.',
                        $table,
                        $columnName,
                    ),
                );
            }

            $sequenceName = $this->expected($options->sequence($tableKey));
            $sequence = $snapshot->sequence($sequenceName);

            if ($sequence === null) {
                $issues[] = new SchemaIssue(
                    code: 'missing_sequence',
                    object: $sequenceName,
                    message: sprintf(
                        'Required Oracle sequence [%s] is absent.',
                        $sequenceName,
                    ),
                );
            } elseif ($sequence->incrementBy !== 1 || $sequence->cycles) {
                $issues[] = new SchemaIssue(
                    code: 'sequence_definition_mismatch',
                    object: $sequenceName,
                    message: sprintf(
                        'Sequence [%s] must increment by one without cycling.',
                        $sequenceName,
                    ),
                );
            }

            $triggerName = $this->expected($options->trigger($tableKey));
            $trigger = $snapshot->trigger($triggerName);

            if ($trigger === null) {
                $issues[] = new SchemaIssue(
                    code: 'missing_trigger',
                    object: $triggerName,
                    message: sprintf(
                        'Required Oracle trigger [%s] is absent.',
                        $triggerName,
                    ),
                );
            } elseif (! $this->validTrigger(
                $trigger,
                $table,
                $columnName,
                $sequenceName,
            )) {
                $issues[] = new SchemaIssue(
                    code: 'trigger_definition_mismatch',
                    object: $triggerName,
                    message: sprintf(
                        'Trigger [%s] does not match the required null-guarded INSERT behavior.',
                        $triggerName,
                    ),
                );
            }
        }

        return $issues;
    }

    private function hasExpectedType(
        ColumnDefinition $expected,
        OracleColumnMetadata $actual,
    ): bool {
        return match ($expected->type) {
            ColumnType::BigInteger => $actual->dataType === 'NUMBER'
                && $actual->precision === 19
                && $actual->scale === 0,
            ColumnType::DateTime => $actual->dataType === 'TIMESTAMP(6)',
            ColumnType::FixedString => $actual->dataType === 'CHAR',
            ColumnType::String => $actual->dataType === 'VARCHAR2',
            ColumnType::Text => $actual->dataType === 'CLOB',
        };
    }

    private function expectedType(ColumnDefinition $expected): string
    {
        return match ($expected->type) {
            ColumnType::BigInteger => 'NUMBER(19,0)',
            ColumnType::DateTime => 'TIMESTAMP(6)',
            ColumnType::FixedString => sprintf(
                'CHAR(%d CHAR)',
                $expected->length ?? 1,
            ),
            ColumnType::String => sprintf(
                'VARCHAR2(%d CHAR)',
                $expected->length ?? 191,
            ),
            ColumnType::Text => 'CLOB',
        };
    }

    private function expectedConstraintType(
        ConstraintDefinition $constraint,
    ): string {
        return match ($constraint->type) {
            ConstraintType::Primary => 'P',
            ConstraintType::Unique => 'U',
            ConstraintType::Foreign => 'R',
            ConstraintType::Check => 'C',
        };
    }

    private function missingIndex(
        string $physicalTable,
        IndexDefinition $index,
    ): SchemaIssue {
        $name = $this->expected($index->name);

        return new SchemaIssue(
            code: 'missing_index',
            object: $physicalTable.'.'.$name,
            message: sprintf(
                'Required index [%s.%s] is absent.',
                $physicalTable,
                $name,
            ),
        );
    }

    private function validTrigger(
        OracleTriggerMetadata $trigger,
        string $table,
        string $column,
        string $sequence,
    ): bool {
        $body = $this->normalizeTriggerBody($trigger->body);

        return $trigger->table === $table
            && $trigger->type === 'BEFORE EACH ROW'
            && trim($trigger->event) === 'INSERT'
            && $trigger->status === 'ENABLED'
            && str_contains($body, sprintf('IF:NEW.%sISNULLTHEN', $column))
            && str_contains(
                $body,
                sprintf(':NEW.%s:=%s.NEXTVAL;', $column, $sequence),
            );
    }

    /**
     * @return array<string, ColumnDefinition>
     */
    private function generatedTables(SchemaManifest $manifest): array
    {
        $generatedTables = [];

        foreach ($manifest->tables() as $table) {
            foreach ($table->columns as $column) {
                if ($column->generated) {
                    $generatedTables[$table->key] = $column;
                }
            }
        }

        return $generatedTables;
    }

    private function normalizeCheck(?string $expression): string
    {
        $normalized = strtolower(
            str_replace(['"', '`'], '', (string) $expression),
        );
        $normalized = preg_replace('/\s+/', '', $normalized) ?? $normalized;

        while ($this->hasRedundantOuterParentheses($normalized)) {
            $normalized = substr($normalized, 1, -1);
        }

        return $normalized;
    }

    private function normalizeTriggerBody(string $body): string
    {
        $normalized = strtoupper(str_replace('"', '', $body));

        return preg_replace('/\s+/', '', $normalized) ?? $normalized;
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

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function expectedList(array $values): array
    {
        return array_map($this->expected(...), $values);
    }

    private function expected(string $identifier): string
    {
        return strtoupper($identifier);
    }
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Ddl\MySql;

use HangTuah\GateTrail\Database\Schema\Ddl\DdlCompiler;
use HangTuah\GateTrail\Database\Schema\Manifest\ColumnDefinition;
use HangTuah\GateTrail\Database\Schema\Manifest\ColumnType;
use HangTuah\GateTrail\Database\Schema\Manifest\ConstraintDefinition;
use HangTuah\GateTrail\Database\Schema\Manifest\ConstraintType;
use HangTuah\GateTrail\Database\Schema\Manifest\IndexDefinition;
use HangTuah\GateTrail\Database\Schema\Manifest\SchemaManifest;
use HangTuah\GateTrail\Database\Schema\Manifest\TableDefinition;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use LogicException;

final class MySqlDdlCompiler implements DdlCompiler
{
    public function compileInstall(
        SchemaManifest $manifest,
        SchemaContext $context,
    ): array {
        $context->validateAll();

        return array_map(
            fn (TableDefinition $table): string => $this->compileTable(
                $table,
                $context,
            ),
            $manifest->tables(),
        );
    }

    public function compileRollback(
        SchemaManifest $manifest,
        SchemaContext $context,
    ): array {
        $context->validateAll();

        return array_map(
            fn (string $key): string => sprintf(
                'DROP TABLE %s;',
                $this->quoteQualified($context->table($key)),
            ),
            $manifest->rollbackOrder(),
        );
    }

    private function compileTable(
        TableDefinition $table,
        SchemaContext $context,
    ): string {
        $definitions = [
            ...array_map(
                fn (ColumnDefinition $column): string => $this->compileColumn($column),
                $table->columns,
            ),
            ...array_map(
                fn (ConstraintDefinition $constraint): string => $this->compileConstraint(
                    $constraint,
                    $context,
                ),
                $table->constraints,
            ),
            ...array_map(
                fn (IndexDefinition $index): string => $this->compileIndex($index),
                $table->indexes,
            ),
        ];

        return sprintf(
            "CREATE TABLE %s (\n    %s\n) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
            $this->quoteQualified($context->table($table->key)),
            implode(",\n    ", $definitions),
        );
    }

    private function compileColumn(ColumnDefinition $column): string
    {
        $definition = [
            $this->quote($column->name),
            $this->columnType($column),
        ];

        if ($column->exactComparison && $column->type === ColumnType::String) {
            $definition[] = 'COLLATE utf8mb4_bin';
        }

        $definition[] = $column->nullable ? 'NULL' : 'NOT NULL';

        if ($column->generated) {
            $definition[] = 'AUTO_INCREMENT';
        }

        return implode(' ', $definition);
    }

    private function columnType(ColumnDefinition $column): string
    {
        return match ($column->type) {
            ColumnType::BigInteger => 'BIGINT UNSIGNED',
            ColumnType::DateTime => 'DATETIME',
            ColumnType::String => sprintf('VARCHAR(%d)', $column->length ?? 191),
            ColumnType::Text => 'LONGTEXT',
        };
    }

    private function compileConstraint(
        ConstraintDefinition $constraint,
        SchemaContext $context,
    ): string {
        return match ($constraint->type) {
            ConstraintType::Primary => sprintf(
                'PRIMARY KEY (%s)',
                $this->columnList($constraint->columns),
            ),
            ConstraintType::Unique => sprintf(
                'CONSTRAINT %s UNIQUE (%s)',
                $this->quote($constraint->name),
                $this->columnList($constraint->columns),
            ),
            ConstraintType::Foreign => $this->compileForeign($constraint, $context),
            ConstraintType::Check => sprintf(
                'CONSTRAINT %s CHECK (%s)',
                $this->quote($constraint->name),
                $constraint->expression
                    ?? throw new LogicException('A check constraint requires an expression.'),
            ),
        };
    }

    private function compileForeign(
        ConstraintDefinition $constraint,
        SchemaContext $context,
    ): string {
        $referencedTable = $constraint->referencedTable
            ?? throw new LogicException('A foreign key requires a referenced table.');

        return sprintf(
            'CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE RESTRICT ON UPDATE RESTRICT',
            $this->quote($constraint->name),
            $this->columnList($constraint->columns),
            $this->quoteQualified($context->table($referencedTable)),
            $this->columnList($constraint->referencedColumns),
        );
    }

    private function compileIndex(IndexDefinition $index): string
    {
        return sprintf(
            'INDEX %s (%s)',
            $this->quote($index->name),
            $this->columnList($index->columns),
        );
    }

    /**
     * @param  list<string>  $columns
     */
    private function columnList(array $columns): string
    {
        return implode(', ', array_map($this->quote(...), $columns));
    }

    private function quoteQualified(string $identifier): string
    {
        return implode(
            '.',
            array_map($this->quote(...), explode('.', $identifier)),
        );
    }

    private function quote(string $identifier): string
    {
        return sprintf('`%s`', $identifier);
    }
}

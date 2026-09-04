<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Ddl\SqlServer;

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

/**
 * Compiles the canonical schema manifest into SQL Server release `0001` DDL.
 *
 * # Type choices
 *
 * Every character column is a Unicode type, so a schema installed for one
 * application locale stores the same bytes as any other:
 *
 * - `String` maps to `NVARCHAR(n)` and `FixedString` to `NCHAR(n)`, not their
 *   non-Unicode `VARCHAR`/`CHAR` counterparts;
 * - `Text` maps to `NVARCHAR(MAX)`, the supported large-object type on SQL
 *   Server 2017 and later. `NTEXT` is deprecated and is not emitted;
 * - `DateTime` maps to `DATETIME2(0)`, matching the whole-second contract the
 *   other drivers compile and avoiding `DATETIME`'s 1753 floor and 3.33 ms
 *   rounding;
 * - `BigInteger` maps to `BIGINT`. SQL Server has no unsigned integer type, so
 *   the manifest's identifiers are signed here, exactly as they are on
 *   PostgreSQL and Oracle.
 *
 * Unicode doubles the byte width of an indexed character column, so the widest
 * index key this produces — `acl_audit_actor_ix`, two `NVARCHAR(191)` columns
 * plus a `DATETIME2(0)` — is 770 bytes, inside SQL Server's 1700-byte
 * nonclustered key limit. The only character-keyed primary key,
 * `acl_asgn_locks_pk`, is 192 bytes, inside the 900-byte clustered limit.
 *
 * # Generated keys and collation
 *
 * A manifest-generated identifier compiles to `BIGINT IDENTITY(1,1) NOT NULL`,
 * which is SQL Server's native generated-key mechanism and is what Eloquent
 * relies on when it omits the key from an insert.
 *
 * A column the manifest marks for exact comparison is pinned to
 * `Latin1_General_100_BIN2` — the collation {@see SqlServerAdapter} already
 * declares — rather than left to the database default, which on SQL Server is
 * commonly case-insensitive. Non-exact character columns inherit the database
 * default deliberately, so ordinary text keeps the deployment's own linguistic
 * comparison.
 *
 * # What is never emitted
 *
 * No `CASCADE`, `SET NULL` or `SET DEFAULT` referential action, because each
 * would destroy assignment history the package promises to keep; SQL Server
 * spells the restrictive action `NO ACTION`, which is what `RESTRICT` means on
 * the drivers that use that keyword. No `IF EXISTS`/`IF NOT EXISTS` guard, so
 * an unexpected object is a conflict a DBA investigates rather than a silent
 * no-op. No dynamic SQL, stored procedure, trigger, or untrusted or disabled
 * constraint. No `GO`, so the output stays executable through any ordinary
 * client rather than only through the SQLCMD batch parser.
 *
 * Identifiers are bracketed one component at a time, so a configured schema
 * yields `[schema].[table]` and never `[schema.table]`. They need no `]`
 * escaping because every identifier has already passed
 * {@see SchemaContext::validateAll()} and its `IdentifierValidator` boundary,
 * which admits only ASCII letters, digits and underscores.
 *
 * @internal
 */
final class SqlServerDdlCompiler implements DdlCompiler
{
    /**
     * The deterministic, case- and accent-sensitive collation
     * {@see \HangTuah\GateTrail\Database\Schema\Adapters\SqlServerAdapter}
     * declares for exact-comparison columns.
     */
    private const EXACT_COLLATION = 'Latin1_General_100_BIN2';

    public function compileInstall(
        SchemaManifest $manifest,
        SchemaContext $context,
    ): array {
        $context->validateAll();
        $statements = [];

        foreach ($manifest->tables() as $table) {
            $statements[] = $this->compileTable($table, $context);

            foreach ($table->indexes as $index) {
                $statements[] = $this->compileIndex($index, $table, $context);
            }
        }

        return $statements;
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
        ];

        return sprintf(
            "CREATE TABLE %s (\n    %s\n);",
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

        if ($column->generated) {
            $definition[] = 'IDENTITY(1,1)';
        }

        if (
            $column->exactComparison
            && in_array(
                $column->type,
                [ColumnType::FixedString, ColumnType::String],
                true,
            )
        ) {
            $definition[] = 'COLLATE '.self::EXACT_COLLATION;
        }

        $definition[] = $column->nullable ? 'NULL' : 'NOT NULL';

        return implode(' ', $definition);
    }

    private function columnType(ColumnDefinition $column): string
    {
        return match ($column->type) {
            ColumnType::BigInteger => 'BIGINT',
            ColumnType::DateTime => 'DATETIME2(0)',
            ColumnType::FixedString => sprintf(
                'NCHAR(%d)',
                $column->length ?? 1,
            ),
            ColumnType::String => sprintf(
                'NVARCHAR(%d)',
                $column->length ?? 191,
            ),
            ColumnType::Text => 'NVARCHAR(MAX)',
        };
    }

    private function compileConstraint(
        ConstraintDefinition $constraint,
        SchemaContext $context,
    ): string {
        return match ($constraint->type) {
            ConstraintType::Primary => sprintf(
                'CONSTRAINT %s PRIMARY KEY (%s)',
                $this->quote($constraint->name),
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
            'CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE NO ACTION ON UPDATE NO ACTION',
            $this->quote($constraint->name),
            $this->columnList($constraint->columns),
            $this->quoteQualified($context->table($referencedTable)),
            $this->columnList($constraint->referencedColumns),
        );
    }

    private function compileIndex(
        IndexDefinition $index,
        TableDefinition $table,
        SchemaContext $context,
    ): string {
        return sprintf(
            'CREATE INDEX %s ON %s (%s);',
            $this->quote($index->name),
            $this->quoteQualified($context->table($table->key)),
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
        return sprintf('[%s]', $identifier);
    }
}

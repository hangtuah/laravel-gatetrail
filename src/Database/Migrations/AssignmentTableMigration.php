<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Migrations;

use Illuminate\Database\Schema\Blueprint;

abstract class AssignmentTableMigration extends GateTrailMigration
{
    /**
     * @param  array<string, 'integer'|'string'>  $identityColumns
     * @param  array<string, array{parent: string, constraint: string}>  $foreignKeys
     * @param  array<string, list<string>>  $indexes
     */
    protected function createAssignmentTable(
        string $tableKey,
        array $identityColumns,
        array $foreignKeys,
        array $indexes,
        string $periodConstraint,
        string $revokeConstraint,
    ): void {
        if ($this->adapter()->requiresInlineCheckConstraints()) {
            $this->createSQLiteAssignmentTable(
                $tableKey,
                $identityColumns,
                $foreignKeys,
                $indexes,
                $periodConstraint,
                $revokeConstraint,
            );

            return;
        }

        $context = $this->context();
        $collation = $this->adapter()->caseSensitiveCollation();

        $this->schema()->create(
            $context->table($tableKey),
            function (Blueprint $table) use (
                $collation,
                $foreignKeys,
                $identityColumns,
                $indexes,
                $context,
            ): void {
                $table->id();

                foreach ($identityColumns as $column => $type) {
                    if ($type === 'string') {
                        $definition = $table->string($column, 191);

                        if ($collation !== null) {
                            $definition->collation($collation);
                        }

                        continue;
                    }

                    $table->unsignedBigInteger($column);
                }

                $table->dateTime('assigned_at');
                $this->actorTypeColumn($table, 'assigned_by_type', $collation);
                $this->actorIdColumn($table, 'assigned_by_id', $collation);
                $table->longText('assignment_reason')->nullable();
                $table->dateTime('starts_at')->nullable();
                $table->dateTime('expires_at')->nullable();
                $table->dateTime('revoked_at')->nullable();
                $this->actorTypeColumn($table, 'revoked_by_type', $collation, true);
                $this->actorIdColumn($table, 'revoked_by_id', $collation, true);
                $table->longText('revocation_reason')->nullable();
                $table->dateTime('created_at');
                $table->dateTime('updated_at');

                foreach ($foreignKeys as $column => $foreign) {
                    $table->foreign($column, $foreign['constraint'])
                        ->references('id')
                        ->on($context->table($foreign['parent']))
                        ->restrictOnDelete()
                        ->restrictOnUpdate();
                }

                foreach ($indexes as $name => $columns) {
                    $table->index($columns, $name);
                }
            },
        );

        $this->addCheckConstraints($tableKey, [
            $periodConstraint => $this->periodExpression(),
            $revokeConstraint => $this->revokeExpression(),
        ]);
    }

    /**
     * @param  array<string, 'integer'|'string'>  $identityColumns
     * @param  array<string, array{parent: string, constraint: string}>  $foreignKeys
     * @param  array<string, list<string>>  $indexes
     */
    private function createSQLiteAssignmentTable(
        string $tableKey,
        array $identityColumns,
        array $foreignKeys,
        array $indexes,
        string $periodConstraint,
        string $revokeConstraint,
    ): void {
        $grammar = $this->connection()->getQueryGrammar();
        $definitions = [
            $grammar->wrap('id').' INTEGER PRIMARY KEY AUTOINCREMENT',
        ];

        foreach ($identityColumns as $column => $type) {
            $definitions[] = sprintf(
                '%s %s NOT NULL',
                $grammar->wrap($column),
                $type === 'string'
                    ? 'VARCHAR(191) COLLATE BINARY'
                    : 'INTEGER',
            );
        }

        $definitions = [
            ...$definitions,
            $grammar->wrap('assigned_at').' DATETIME NOT NULL',
            $grammar->wrap('assigned_by_type').' VARCHAR(191) COLLATE BINARY NOT NULL',
            $grammar->wrap('assigned_by_id').' VARCHAR(191) COLLATE BINARY NOT NULL',
            $grammar->wrap('assignment_reason').' TEXT NULL',
            $grammar->wrap('starts_at').' DATETIME NULL',
            $grammar->wrap('expires_at').' DATETIME NULL',
            $grammar->wrap('revoked_at').' DATETIME NULL',
            $grammar->wrap('revoked_by_type').' VARCHAR(191) COLLATE BINARY NULL',
            $grammar->wrap('revoked_by_id').' VARCHAR(191) COLLATE BINARY NULL',
            $grammar->wrap('revocation_reason').' TEXT NULL',
            $grammar->wrap('created_at').' DATETIME NOT NULL',
            $grammar->wrap('updated_at').' DATETIME NOT NULL',
        ];

        foreach ($foreignKeys as $column => $foreign) {
            $definitions[] = sprintf(
                'CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE RESTRICT ON UPDATE RESTRICT',
                $grammar->wrap($foreign['constraint']),
                $grammar->wrap($column),
                $grammar->wrapTable($this->context()->table($foreign['parent'])),
                $grammar->wrap('id'),
            );
        }

        $definitions[] = sprintf(
            'CONSTRAINT %s CHECK (%s)',
            $grammar->wrap($periodConstraint),
            $this->periodExpression(),
        );
        $definitions[] = sprintf(
            'CONSTRAINT %s CHECK (%s)',
            $grammar->wrap($revokeConstraint),
            $this->revokeExpression(),
        );

        $this->connection()->statement(
            sprintf(
                'CREATE TABLE %s (%s)',
                $grammar->wrapTable($this->context()->table($tableKey)),
                implode(', ', $definitions),
            ),
        );

        $this->schema()->table(
            $this->context()->table($tableKey),
            static function (Blueprint $table) use ($indexes): void {
                foreach ($indexes as $name => $columns) {
                    $table->index($columns, $name);
                }
            },
        );
    }

    private function actorTypeColumn(
        Blueprint $table,
        string $name,
        ?string $collation,
        bool $nullable = false,
    ): void {
        $column = $table->string($name, 191);

        if ($collation !== null) {
            $column->collation($collation);
        }

        if ($nullable) {
            $column->nullable();
        }
    }

    private function actorIdColumn(
        Blueprint $table,
        string $name,
        ?string $collation,
        bool $nullable = false,
    ): void {
        $this->actorTypeColumn($table, $name, $collation, $nullable);
    }

    private function periodExpression(): string
    {
        return '(expires_at IS NULL) '
            .'OR (expires_at > COALESCE(starts_at, assigned_at))';
    }

    private function revokeExpression(): string
    {
        return '((revoked_at IS NULL) '
            .'AND (revoked_by_type IS NULL) '
            .'AND (revoked_by_id IS NULL)) '
            .'OR ((revoked_at IS NOT NULL) '
            .'AND (revoked_by_type IS NOT NULL) '
            .'AND (revoked_by_id IS NOT NULL))';
    }
}

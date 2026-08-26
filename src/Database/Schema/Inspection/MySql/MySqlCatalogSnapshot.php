<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Inspection\MySql;

/**
 * @internal
 */
final readonly class MySqlCatalogSnapshot
{
    /**
     * @param  list<string>  $tables
     * @param  list<MySqlColumnMetadata>  $columns
     * @param  list<MySqlIndexMetadata>  $indexes
     * @param  list<MySqlConstraintMetadata>  $constraints
     */
    public function __construct(
        public array $tables,
        public array $columns,
        public array $indexes,
        public array $constraints,
    ) {}

    public function hasTable(string $table): bool
    {
        return in_array($table, $this->tables, true);
    }

    public function column(
        string $table,
        string $name,
    ): ?MySqlColumnMetadata {
        foreach ($this->columns as $column) {
            if ($column->table === $table && $column->name === $name) {
                return $column;
            }
        }

        return null;
    }

    public function index(
        string $table,
        string $name,
    ): ?MySqlIndexMetadata {
        foreach ($this->indexes as $index) {
            if ($index->table === $table && $index->name === $name) {
                return $index;
            }
        }

        return null;
    }

    public function constraint(
        string $table,
        string $name,
    ): ?MySqlConstraintMetadata {
        foreach ($this->constraints as $constraint) {
            if ($constraint->table === $table && $constraint->name === $name) {
                return $constraint;
            }
        }

        return null;
    }
}

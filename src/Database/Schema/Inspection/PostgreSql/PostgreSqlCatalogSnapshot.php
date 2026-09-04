<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Inspection\PostgreSql;

/**
 * An immutable read-only picture of the GateTrail objects in one PostgreSQL
 * schema.
 *
 * @internal
 */
final readonly class PostgreSqlCatalogSnapshot
{
    /**
     * @param  list<string>  $tables
     * @param  list<PostgreSqlColumnMetadata>  $columns
     * @param  list<PostgreSqlIndexMetadata>  $indexes
     * @param  list<PostgreSqlConstraintMetadata>  $constraints
     * @param  list<PostgreSqlIdentityMetadata>  $identities
     */
    public function __construct(
        public string $schema,
        public array $tables,
        public array $columns,
        public array $indexes,
        public array $constraints,
        public array $identities,
    ) {}

    public function hasTable(string $table): bool
    {
        return in_array($table, $this->tables, true);
    }

    public function column(
        string $table,
        string $name,
    ): ?PostgreSqlColumnMetadata {
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
    ): ?PostgreSqlIndexMetadata {
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
    ): ?PostgreSqlConstraintMetadata {
        foreach ($this->constraints as $constraint) {
            if ($constraint->table === $table && $constraint->name === $name) {
                return $constraint;
            }
        }

        return null;
    }

    public function identity(
        string $table,
        string $column,
    ): ?PostgreSqlIdentityMetadata {
        foreach ($this->identities as $identity) {
            if ($identity->table === $table && $identity->column === $column) {
                return $identity;
            }
        }

        return null;
    }

    /**
     * @return list<PostgreSqlIndexMetadata>
     */
    public function indexesFor(string $table): array
    {
        return array_values(array_filter(
            $this->indexes,
            static fn (PostgreSqlIndexMetadata $index): bool => (
                $index->table === $table
            ),
        ));
    }
}

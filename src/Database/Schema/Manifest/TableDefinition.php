<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Manifest;

/**
 * @internal
 */
final readonly class TableDefinition
{
    /**
     * @param  list<ColumnDefinition>  $columns
     * @param  list<ConstraintDefinition>  $constraints
     * @param  list<IndexDefinition>  $indexes
     * @param  list<string>  $dependencies
     */
    public function __construct(
        public string $key,
        public string $migration,
        public array $columns,
        public array $constraints,
        public array $indexes = [],
        public array $dependencies = [],
    ) {}

    /**
     * @return list<string>
     */
    public function columnNames(): array
    {
        return array_map(
            static fn (ColumnDefinition $column): string => $column->name,
            $this->columns,
        );
    }
}

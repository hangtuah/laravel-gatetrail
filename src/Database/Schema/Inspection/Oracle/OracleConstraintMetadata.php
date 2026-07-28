<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Inspection\Oracle;

final readonly class OracleConstraintMetadata
{
    /**
     * @param  list<string>  $columns
     * @param  list<string>  $referencedColumns
     */
    public function __construct(
        public string $table,
        public string $name,
        public string $type,
        public array $columns,
        public ?string $referencedTable,
        public array $referencedColumns,
        public ?string $deleteRule,
        public string $status,
        public string $validated,
        public ?string $checkClause,
    ) {}
}

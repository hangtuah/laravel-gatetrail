<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Inspection\MySql;

/**
 * @internal
 */
final readonly class MySqlConstraintMetadata
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
        public ?string $referencedTable = null,
        public array $referencedColumns = [],
        public ?string $deleteRule = null,
        public ?string $updateRule = null,
        public ?string $checkClause = null,
    ) {}
}

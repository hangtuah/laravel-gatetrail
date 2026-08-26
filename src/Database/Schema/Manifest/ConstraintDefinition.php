<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Manifest;

/**
 * @internal
 */
final readonly class ConstraintDefinition
{
    /**
     * @param  list<string>  $columns
     * @param  list<string>  $referencedColumns
     */
    public function __construct(
        public string $name,
        public ConstraintType $type,
        public array $columns,
        public ?string $referencedTable = null,
        public array $referencedColumns = [],
        public ?string $expression = null,
        public bool $cascade = false,
    ) {}
}

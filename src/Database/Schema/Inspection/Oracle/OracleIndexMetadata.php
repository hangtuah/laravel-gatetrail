<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Inspection\Oracle;

/**
 * @internal
 */
final readonly class OracleIndexMetadata
{
    /**
     * @param  list<string>  $columns
     */
    public function __construct(
        public string $table,
        public string $name,
        public array $columns,
        public bool $unique,
        public string $status,
    ) {}
}

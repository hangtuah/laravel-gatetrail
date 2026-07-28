<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Inspection\MySql;

final readonly class MySqlIndexMetadata
{
    /**
     * @param  list<string>  $columns
     */
    public function __construct(
        public string $table,
        public string $name,
        public array $columns,
        public bool $unique,
    ) {}
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Inspection\MySql;

/**
 * @internal
 */
final readonly class MySqlColumnMetadata
{
    public function __construct(
        public string $table,
        public string $name,
        public string $dataType,
        public string $columnType,
        public bool $nullable,
        public ?int $length,
        public string $extra,
        public ?string $collation,
    ) {}
}

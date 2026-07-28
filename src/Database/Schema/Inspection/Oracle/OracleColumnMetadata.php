<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Inspection\Oracle;

final readonly class OracleColumnMetadata
{
    public function __construct(
        public string $table,
        public string $name,
        public string $dataType,
        public bool $nullable,
        public ?int $charLength,
        public ?string $charUsed,
        public ?int $precision,
        public ?int $scale,
        public bool $identity,
        public bool $defaultOnNull,
    ) {}
}

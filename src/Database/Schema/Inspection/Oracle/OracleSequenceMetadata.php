<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Inspection\Oracle;

final readonly class OracleSequenceMetadata
{
    public function __construct(
        public string $name,
        public int $incrementBy,
        public bool $cycles,
    ) {}
}

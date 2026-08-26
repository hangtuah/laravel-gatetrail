<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Inspection\Oracle;

/**
 * @internal
 */
final readonly class OracleIdentityMetadata
{
    public function __construct(
        public string $table,
        public string $column,
        public string $generationType,
    ) {}
}

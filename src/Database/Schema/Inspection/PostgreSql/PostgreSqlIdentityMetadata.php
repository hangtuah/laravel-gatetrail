<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Inspection\PostgreSql;

/**
 * @internal
 */
final readonly class PostgreSqlIdentityMetadata
{
    public function __construct(
        public string $table,
        public string $column,
        public PostgreSqlIdentityGeneration $generation,
        public ?string $sequence = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Manifest;

/**
 * @internal
 */
final readonly class ColumnDefinition
{
    public function __construct(
        public string $name,
        public ColumnType $type,
        public bool $nullable = false,
        public ?int $length = null,
        public bool $generated = false,
        public bool $exactComparison = false,
    ) {}
}

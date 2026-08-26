<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Manifest;

/**
 * @internal
 */
final readonly class IndexDefinition
{
    /**
     * @param  list<string>  $columns
     */
    public function __construct(
        public string $name,
        public array $columns,
    ) {}
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Audit;

use stdClass;

/**
 * @internal
 */
final readonly class NormalizedMetadata
{
    /**
     * @param  array<string, mixed>|stdClass  $value
     */
    public function __construct(
        public array|stdClass $value,
        public string $json,
    ) {}
}

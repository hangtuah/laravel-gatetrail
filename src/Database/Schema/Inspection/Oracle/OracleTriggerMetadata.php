<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Inspection\Oracle;

final readonly class OracleTriggerMetadata
{
    public function __construct(
        public string $name,
        public string $table,
        public string $type,
        public string $event,
        public string $status,
        public string $body,
    ) {}
}

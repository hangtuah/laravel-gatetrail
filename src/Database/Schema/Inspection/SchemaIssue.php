<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Inspection;

/**
 * @internal
 */
final readonly class SchemaIssue
{
    public function __construct(
        public string $code,
        public string $object,
        public string $message,
        public InspectionSeverity $severity = InspectionSeverity::Error,
    ) {}
}

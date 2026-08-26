<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Inspection;

/**
 * @internal
 */
final readonly class SchemaInspection
{
    /**
     * @param  list<SchemaIssue>  $issues
     */
    public function __construct(
        public array $issues,
    ) {}

    public function passes(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->severity === InspectionSeverity::Error) {
                return false;
            }
        }

        return true;
    }
}

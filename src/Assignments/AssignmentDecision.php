<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Assignments;

use Illuminate\Database\Eloquent\Model;

/**
 * @internal
 */
final readonly class AssignmentDecision
{
    private function __construct(
        public AssignmentDecisionOutcome $outcome,
        public ?Model $existingAssignment,
    ) {}

    public static function create(): self
    {
        return new self(
            AssignmentDecisionOutcome::Create,
            null,
        );
    }

    public static function unchanged(Model $assignment): self
    {
        return new self(
            AssignmentDecisionOutcome::Unchanged,
            $assignment,
        );
    }

    public static function conflict(): self
    {
        return new self(
            AssignmentDecisionOutcome::Conflict,
            null,
        );
    }
}

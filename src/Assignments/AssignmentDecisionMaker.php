<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Assignments;

use HangTuah\GateTrail\AssignmentPeriod;
use Illuminate\Support\Collection;

/**
 * @internal
 */
final class AssignmentDecisionMaker
{
    /**
     * @param  Collection<int, AssignmentConflict>  $conflicts
     */
    public function decide(
        AssignmentPeriod $requestedPeriod,
        EffectiveAssignmentPeriod $candidate,
        Collection $conflicts,
    ): AssignmentDecision {
        if ($conflicts->isEmpty()) {
            return AssignmentDecision::create();
        }

        if (! $requestedPeriod->isImmediate() || $conflicts->count() !== 1) {
            return AssignmentDecision::conflict();
        }

        $conflict = $conflicts->first();

        if (! $conflict->period->contains($candidate->startsAt())) {
            return AssignmentDecision::conflict();
        }

        return AssignmentDecision::unchanged($conflict->assignment);
    }
}

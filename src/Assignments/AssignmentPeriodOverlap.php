<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Assignments;

final class AssignmentPeriodOverlap
{
    public function overlaps(
        EffectiveAssignmentPeriod $first,
        EffectiveAssignmentPeriod $second,
    ): bool {
        if (
            $first->expiresAt() !== null
            && $first->expiresAt() <= $second->startsAt()
        ) {
            return false;
        }

        if (
            $second->expiresAt() !== null
            && $second->expiresAt() <= $first->startsAt()
        ) {
            return false;
        }

        return true;
    }
}

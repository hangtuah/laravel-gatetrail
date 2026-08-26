<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Assignments;

use Illuminate\Database\Eloquent\Model;

/**
 * @internal
 */
final readonly class AssignmentConflict
{
    public function __construct(
        public Model $assignment,
        public EffectiveAssignmentPeriod $period,
    ) {}
}

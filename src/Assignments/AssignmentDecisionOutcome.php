<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Assignments;

enum AssignmentDecisionOutcome: string
{
    case Create = 'create';
    case Unchanged = 'unchanged';
    case Conflict = 'conflict';
}

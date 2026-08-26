<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Assignments;

/**
 * @internal
 */
enum AssignmentDecisionOutcome: string
{
    case Create = 'create';
    case Unchanged = 'unchanged';
    case Conflict = 'conflict';
}

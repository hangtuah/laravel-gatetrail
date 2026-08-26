<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Inspection;

/**
 * @internal
 */
enum InspectionSeverity: string
{
    case Error = 'error';
    case Warning = 'warning';
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Diagnostics;

/**
 * @internal
 */
enum DiagnosticSeverity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Informational = 'informational';
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Diagnostics;

/**
 * @internal
 */
final class DiagnosticReference
{
    public static function for(string $kind, int|string $identifier): string
    {
        return hash('sha256', $kind.'|'.(string) $identifier);
    }
}

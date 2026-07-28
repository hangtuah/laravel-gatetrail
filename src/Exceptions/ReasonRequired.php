<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Exceptions;

use InvalidArgumentException;

final class ReasonRequired extends InvalidArgumentException
{
    public static function forAssignment(): self
    {
        return new self(
            'A non-empty reason is required for a GateTrail assignment mutation.',
        );
    }
}

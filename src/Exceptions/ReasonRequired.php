<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Exceptions;

use InvalidArgumentException;

final class ReasonRequired extends InvalidArgumentException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function forAssignment(): self
    {
        return new self(
            'A non-empty reason is required for a GateTrail assignment mutation.',
        );
    }

    public static function forDefinition(): self
    {
        return new self(
            'A non-empty reason is required for a GateTrail definition mutation.',
        );
    }
}

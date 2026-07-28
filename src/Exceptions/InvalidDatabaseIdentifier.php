<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Exceptions;

use InvalidArgumentException;

final class InvalidDatabaseIdentifier extends InvalidArgumentException
{
    public static function because(string $label, string $identifier, string $reason): self
    {
        return new self(
            sprintf(
                'Invalid GateTrail %s [%s]: %s.',
                $label,
                $identifier,
                $reason,
            ),
        );
    }
}

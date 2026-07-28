<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Exceptions;

use DateTimeInterface;
use InvalidArgumentException;

final class InvalidAssignmentPeriod extends InvalidArgumentException
{
    public static function expiryNotAfterStart(
        DateTimeInterface $startsAt,
        DateTimeInterface $expiresAt,
    ): self {
        return new self(
            sprintf(
                'GateTrail assignment expiry [%s] must be later than its effective start [%s].',
                $expiresAt->format(DATE_ATOM),
                $startsAt->format(DATE_ATOM),
            ),
        );
    }
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Time;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/**
 * @internal
 */
final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        $now = new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC'),
        );

        return $now->setTime(
            (int) $now->format('H'),
            (int) $now->format('i'),
            (int) $now->format('s'),
        );
    }
}

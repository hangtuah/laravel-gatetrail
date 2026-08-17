<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Fixtures;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * A frozen clock that counts how often it was asked.
 *
 * "One instant per evaluation" is the property that keeps a snapshot's queries
 * agreeing about *when* they are asking. A plain frozen clock cannot prove it:
 * every read returns the same value, so a resolver that read the clock once
 * per chunk would look identical to one that read it once per call.
 */
final class CountingClock implements ClockInterface
{
    public int $reads = 0;

    public function __construct(private readonly DateTimeImmutable $time) {}

    public function now(): DateTimeImmutable
    {
        $this->reads++;

        return $this->time;
    }
}

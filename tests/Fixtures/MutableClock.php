<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Fixtures;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * A frozen clock whose instant can be moved without rebinding it.
 *
 * Memoization tests need the evaluation second to advance while the container
 * scope stays intact: rebinding a `FrozenClock` would force a scope flush,
 * which would discard the very memo under test.
 */
final class MutableClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $time) {}

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }

    public function moveTo(DateTimeImmutable $time): void
    {
        $this->time = $time;
    }
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Time;

use DateTimeImmutable;
use DateTimeZone;
use HangTuah\GateTrail\Tests\TestCase;
use HangTuah\GateTrail\Time\SystemClock;
use Psr\Clock\ClockInterface;

final class SystemClockTest extends TestCase
{
    public function test_system_clock_returns_current_utc_whole_seconds(): void
    {
        $before = new DateTimeImmutable(
            '-1 second',
            new DateTimeZone('UTC'),
        );
        $clock = new SystemClock;
        $now = $clock->now();
        $after = new DateTimeImmutable(
            '+1 second',
            new DateTimeZone('UTC'),
        );

        $this->assertInstanceOf(ClockInterface::class, $clock);
        $this->assertSame('UTC', $now->getTimezone()->getName());
        $this->assertSame('000000', $now->format('u'));
        $this->assertGreaterThanOrEqual($before, $now);
        $this->assertLessThanOrEqual($after, $now);
    }

    public function test_clock_contract_resolves_from_the_container(): void
    {
        $this->assertInstanceOf(
            SystemClock::class,
            app(ClockInterface::class),
        );
    }
}

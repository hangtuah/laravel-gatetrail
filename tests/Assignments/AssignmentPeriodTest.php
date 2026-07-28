<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Assignments;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use HangTuah\GateTrail\AssignmentPeriod;
use HangTuah\GateTrail\Exceptions\InvalidAssignmentPeriod;
use PHPUnit\Framework\TestCase;

final class AssignmentPeriodTest extends TestCase
{
    public function test_immediate_period_has_no_explicit_boundaries(): void
    {
        $period = AssignmentPeriod::immediate();

        $this->assertNull($period->startsAt());
        $this->assertNull($period->expiresAt());
    }

    public function test_until_period_has_only_an_expiry(): void
    {
        $period = AssignmentPeriod::until(
            new DateTimeImmutable(
                '2026-07-28 20:30:45.987654',
                new DateTimeZone('Asia/Kuala_Lumpur'),
            ),
        );

        $this->assertNull($period->startsAt());
        $this->assertSame(
            '2026-07-28 12:30:45.000000 +00:00',
            $period->expiresAt()?->format('Y-m-d H:i:s.u P'),
        );
    }

    public function test_between_period_copies_mutable_inputs(): void
    {
        $start = new DateTime('2026-07-28 09:00:00');
        $expiry = new DateTime('2026-07-28 10:00:00');
        $period = AssignmentPeriod::between($start, $expiry);

        $start->modify('+5 hours');
        $expiry->modify('+5 hours');

        $this->assertSame(
            '2026-07-28 09:00:00',
            $period->startsAt()?->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-07-28 10:00:00',
            $period->expiresAt()?->format('Y-m-d H:i:s'),
        );
    }

    public function test_between_period_may_have_no_expiry(): void
    {
        $period = AssignmentPeriod::between(
            new DateTimeImmutable('2026-07-28 09:00:00'),
        );

        $this->assertNotNull($period->startsAt());
        $this->assertNull($period->expiresAt());
    }

    public function test_expiry_must_be_later_than_start_after_normalization(): void
    {
        $this->expectException(InvalidAssignmentPeriod::class);
        $this->expectExceptionMessage('must be later than');

        AssignmentPeriod::between(
            new DateTimeImmutable('2026-07-28 09:00:00.100000'),
            new DateTimeImmutable('2026-07-28 09:00:00.900000'),
        );
    }
}

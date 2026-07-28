<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Assignments;

use DateTimeImmutable;
use HangTuah\GateTrail\AssignmentPeriod;
use HangTuah\GateTrail\Assignments\AssignmentPeriodOverlap;
use HangTuah\GateTrail\Assignments\EffectiveAssignmentPeriod;
use HangTuah\GateTrail\Exceptions\InvalidAssignmentPeriod;
use PHPUnit\Framework\TestCase;

final class AssignmentPeriodOverlapTest extends TestCase
{
    private AssignmentPeriodOverlap $evaluator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluator = new AssignmentPeriodOverlap;
    }

    public function test_adjacent_periods_do_not_overlap(): void
    {
        $first = $this->period('09:00:00', '10:00:00');
        $second = $this->period('10:00:00', '11:00:00');

        $this->assertFalse($this->evaluator->overlaps($first, $second));
        $this->assertFalse($this->evaluator->overlaps($second, $first));
    }

    public function test_intersecting_periods_overlap(): void
    {
        $first = $this->period('09:00:00', '10:00:00');
        $second = $this->period('09:30:00', '11:00:00');

        $this->assertTrue($this->evaluator->overlaps($first, $second));
        $this->assertTrue($this->evaluator->overlaps($second, $first));
    }

    public function test_contained_periods_overlap(): void
    {
        $outer = $this->period('09:00:00', '12:00:00');
        $inner = $this->period('10:00:00', '11:00:00');

        $this->assertTrue($this->evaluator->overlaps($outer, $inner));
        $this->assertTrue($this->evaluator->overlaps($inner, $outer));
    }

    public function test_open_ended_period_overlaps_every_later_period(): void
    {
        $open = $this->period('09:00:00');
        $later = $this->period('20:00:00', '21:00:00');

        $this->assertTrue($this->evaluator->overlaps($open, $later));
        $this->assertTrue($this->evaluator->overlaps($later, $open));
    }

    public function test_separated_periods_do_not_overlap(): void
    {
        $first = $this->period('09:00:00', '10:00:00');
        $second = $this->period('11:00:00', '12:00:00');

        $this->assertFalse($this->evaluator->overlaps($first, $second));
        $this->assertFalse($this->evaluator->overlaps($second, $first));
    }

    public function test_omitted_start_uses_assigned_at(): void
    {
        $period = EffectiveAssignmentPeriod::fromAssignment(
            AssignmentPeriod::until($this->time('10:00:00')),
            $this->time('09:00:00'),
        );

        $this->assertSame(
            '2026-07-28 09:00:00',
            $period->startsAt()->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-07-28 10:00:00',
            $period->expiresAt()?->format('Y-m-d H:i:s'),
        );
    }

    public function test_until_period_is_validated_against_assigned_at(): void
    {
        $this->expectException(InvalidAssignmentPeriod::class);
        $this->expectExceptionMessage('must be later than');

        EffectiveAssignmentPeriod::fromAssignment(
            AssignmentPeriod::until($this->time('09:00:00')),
            $this->time('09:00:00'),
        );
    }

    private function period(
        string $start,
        ?string $expiry = null,
    ): EffectiveAssignmentPeriod {
        return EffectiveAssignmentPeriod::fromStored(
            $this->time($start),
            null,
            $expiry === null ? null : $this->time($expiry),
        );
    }

    private function time(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-07-28 '.$time);
    }
}

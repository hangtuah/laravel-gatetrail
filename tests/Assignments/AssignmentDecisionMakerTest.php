<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Assignments;

use DateTimeImmutable;
use HangTuah\GateTrail\AssignmentPeriod;
use HangTuah\GateTrail\Assignments\AssignmentConflict;
use HangTuah\GateTrail\Assignments\AssignmentDecisionMaker;
use HangTuah\GateTrail\Assignments\AssignmentDecisionOutcome;
use HangTuah\GateTrail\Assignments\EffectiveAssignmentPeriod;
use HangTuah\GateTrail\Models\RoleAssignment;
use PHPUnit\Framework\TestCase;

final class AssignmentDecisionMakerTest extends TestCase
{
    private AssignmentDecisionMaker $decisions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->decisions = new AssignmentDecisionMaker;
    }

    public function test_no_conflicts_allows_creation(): void
    {
        $candidate = $this->period('12:00:00');

        $decision = $this->decisions->decide(
            AssignmentPeriod::immediate(),
            $candidate,
            collect(),
        );

        $this->assertSame(
            AssignmentDecisionOutcome::Create,
            $decision->outcome,
        );
        $this->assertNull($decision->existingAssignment);
    }

    public function test_immediate_replay_returns_active_existing_assignment(): void
    {
        $existing = new RoleAssignment;
        $existing->setAttribute('id', 42);
        $candidate = $this->period('12:00:00');
        $conflict = new AssignmentConflict(
            $existing,
            $this->period('11:00:00', '13:00:00'),
        );

        $decision = $this->decisions->decide(
            AssignmentPeriod::immediate(),
            $candidate,
            collect([$conflict]),
        );

        $this->assertSame(
            AssignmentDecisionOutcome::Unchanged,
            $decision->outcome,
        );
        $this->assertSame($existing, $decision->existingAssignment);
    }

    public function test_scheduled_replay_remains_a_conflict(): void
    {
        $existing = new RoleAssignment;
        $candidate = $this->period('12:00:00', '13:00:00');
        $conflict = new AssignmentConflict(
            $existing,
            $this->period('12:00:00', '13:00:00'),
        );

        $decision = $this->decisions->decide(
            AssignmentPeriod::between(
                $this->time('12:00:00'),
                $this->time('13:00:00'),
            ),
            $candidate,
            collect([$conflict]),
        );

        $this->assertSame(
            AssignmentDecisionOutcome::Conflict,
            $decision->outcome,
        );
        $this->assertNull($decision->existingAssignment);
    }

    public function test_immediate_request_conflicting_only_in_future_is_not_unchanged(): void
    {
        $candidate = $this->period('12:00:00');
        $future = new AssignmentConflict(
            new RoleAssignment,
            $this->period('13:00:00', '14:00:00'),
        );

        $decision = $this->decisions->decide(
            AssignmentPeriod::immediate(),
            $candidate,
            collect([$future]),
        );

        $this->assertSame(
            AssignmentDecisionOutcome::Conflict,
            $decision->outcome,
        );
    }

    public function test_multiple_conflicts_fail_closed(): void
    {
        $candidate = $this->period('12:00:00');
        $conflicts = collect([
            new AssignmentConflict(
                new RoleAssignment,
                $this->period('10:00:00', '13:00:00'),
            ),
            new AssignmentConflict(
                new RoleAssignment,
                $this->period('11:00:00', '14:00:00'),
            ),
        ]);

        $decision = $this->decisions->decide(
            AssignmentPeriod::immediate(),
            $candidate,
            $conflicts,
        );

        $this->assertSame(
            AssignmentDecisionOutcome::Conflict,
            $decision->outcome,
        );
        $this->assertNull($decision->existingAssignment);
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

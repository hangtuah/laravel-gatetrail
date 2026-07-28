<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use DateTimeImmutable;
use DateTimeZone;
use HangTuah\GateTrail\AssignmentPeriod;
use HangTuah\GateTrail\Mutations\Commands\AssignRoleCommand;
use HangTuah\GateTrail\RoleReference;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AssignRoleCommandTest extends TestCase
{
    public function test_command_captures_immutable_input_and_normalized_time(): void
    {
        $subject = new TestSubject;
        $actor = SystemActor::named('staff-sync');
        $period = AssignmentPeriod::immediate();
        $metadata = ['source' => 'staff-system'];
        $clock = new FrozenClock(
            new DateTimeImmutable(
                '2026-07-28 20:30:45.987654',
                new DateTimeZone('Asia/Kuala_Lumpur'),
            ),
        );

        $command = AssignRoleCommand::capture(
            subject: $subject,
            role: 'reviewer',
            actor: $actor,
            reason: 'Synchronized from staff system',
            period: $period,
            metadata: $metadata,
            clock: $clock,
        );

        $metadata['source'] = 'changed-after-capture';

        $this->assertSame($subject, $command->subject);
        $this->assertSame('reviewer', $command->role);
        $this->assertSame($actor, $command->actor);
        $this->assertSame(
            'Synchronized from staff system',
            $command->reason,
        );
        $this->assertSame($period, $command->period);
        $this->assertSame(
            ['source' => 'staff-system'],
            $command->metadata,
        );
        $this->assertSame(
            '2026-07-28 12:30:45.000000 +00:00',
            $command->requestedAt->format('Y-m-d H:i:s.u P'),
        );
    }

    public function test_explicit_role_reference_is_preserved(): void
    {
        $reference = RoleReference::id(
            '550e8400-e29b-41d4-a716-446655440000',
        );

        $command = AssignRoleCommand::capture(
            subject: new TestSubject,
            role: $reference,
            actor: SystemActor::named('test-suite'),
            reason: 'Command test',
            period: AssignmentPeriod::immediate(),
            metadata: [],
            clock: new FrozenClock(
                new DateTimeImmutable('2026-07-28 12:00:00'),
            ),
        );

        $this->assertSame($reference, $command->role);
        $this->assertSame(
            '550e8400-e29b-41d4-a716-446655440000',
            $reference->id,
        );
    }

    public function test_empty_explicit_role_id_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be empty');

        RoleReference::id('   ');
    }
}

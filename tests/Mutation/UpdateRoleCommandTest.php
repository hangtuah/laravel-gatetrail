<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use DateTimeImmutable;
use DateTimeZone;
use HangTuah\GateTrail\Mutations\Commands\UpdateRoleCommand;
use HangTuah\GateTrail\RoleChanges;
use HangTuah\GateTrail\RoleReference;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use PHPUnit\Framework\TestCase;

final class UpdateRoleCommandTest extends TestCase
{
    public function test_command_captures_inputs_and_normalized_time(): void
    {
        $role = RoleReference::id(7);
        $changes = RoleChanges::description('Reviews documents.');
        $actor = SystemActor::named('definition-suite');
        $metadata = ['ticket' => 'SEC-42'];

        $command = UpdateRoleCommand::capture(
            role: $role,
            changes: $changes,
            actor: $actor,
            reason: 'Clarify role purpose',
            metadata: $metadata,
            clock: new FrozenClock(
                new DateTimeImmutable(
                    '2026-07-29 20:30:45.987654',
                    new DateTimeZone('Asia/Kuala_Lumpur'),
                ),
            ),
        );
        $metadata['ticket'] = 'changed';

        $this->assertSame($role, $command->role);
        $this->assertSame($changes, $command->changes);
        $this->assertSame(
            'Reviews documents.',
            $command->changes->description,
        );
        $this->assertSame($actor, $command->actor);
        $this->assertSame('Clarify role purpose', $command->reason);
        $this->assertSame(['ticket' => 'SEC-42'], $command->metadata);
        $this->assertSame(
            '2026-07-29 12:30:45.000000 +00:00',
            $command->requestedAt->format('Y-m-d H:i:s.u P'),
        );
    }

    public function test_command_preserves_explicit_null_description_and_reason(): void
    {
        $command = UpdateRoleCommand::capture(
            role: 'reviewer',
            changes: RoleChanges::description(null),
            actor: SystemActor::named('definition-suite'),
            reason: null,
            metadata: [],
            clock: new FrozenClock(
                new DateTimeImmutable('2026-07-29 12:00:00 UTC'),
            ),
        );

        $this->assertSame('reviewer', $command->role);
        $this->assertNull($command->changes->description);
        $this->assertNull($command->reason);
        $this->assertSame([], $command->metadata);
    }
}

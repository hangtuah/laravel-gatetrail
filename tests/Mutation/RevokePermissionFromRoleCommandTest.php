<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use DateTimeImmutable;
use DateTimeZone;
use HangTuah\GateTrail\AssignmentReference;
use HangTuah\GateTrail\Mutations\Commands\RevokePermissionFromRoleCommand;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use PHPUnit\Framework\TestCase;

final class RevokePermissionFromRoleCommandTest extends TestCase
{
    public function test_command_captures_inputs_and_normalized_time(): void
    {
        $reference = AssignmentReference::id(7);
        $actor = SystemActor::named('revocation-suite');
        $metadata = ['ticket' => 'SEC-42'];

        $command = RevokePermissionFromRoleCommand::capture(
            assignment: $reference,
            actor: $actor,
            reason: 'Role no longer approves documents',
            metadata: $metadata,
            clock: new FrozenClock(
                new DateTimeImmutable(
                    '2026-07-29 20:30:45.987654',
                    new DateTimeZone('Asia/Kuala_Lumpur'),
                ),
            ),
        );
        $metadata['ticket'] = 'changed';

        $this->assertSame($reference, $command->assignment);
        $this->assertSame($actor, $command->actor);
        $this->assertSame(
            'Role no longer approves documents',
            $command->reason,
        );
        $this->assertSame(
            ['ticket' => 'SEC-42'],
            $command->metadata,
        );
        $this->assertSame(
            '2026-07-29 12:30:45.000000 +00:00',
            $command->requestedAt->format('Y-m-d H:i:s.u P'),
        );
    }
}

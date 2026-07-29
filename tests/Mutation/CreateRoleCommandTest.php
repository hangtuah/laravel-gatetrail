<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use DateTimeImmutable;
use DateTimeZone;
use HangTuah\GateTrail\Mutations\Commands\CreateRoleCommand;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use PHPUnit\Framework\TestCase;

final class CreateRoleCommandTest extends TestCase
{
    public function test_command_captures_inputs_and_normalized_time(): void
    {
        $actor = SystemActor::named('definition-suite');
        $metadata = ['ticket' => 'SEC-42'];

        $command = CreateRoleCommand::capture(
            name: '  Reviewer  ',
            guard: '  web  ',
            actor: $actor,
            reason: 'Initial provisioning',
            description: 'Reviews documents.',
            metadata: $metadata,
            clock: new FrozenClock(
                new DateTimeImmutable(
                    '2026-07-29 20:30:45.987654',
                    new DateTimeZone('Asia/Kuala_Lumpur'),
                ),
            ),
        );
        $metadata['ticket'] = 'changed';

        $this->assertSame('Reviewer', $command->name);
        $this->assertSame('web', $command->guard);
        $this->assertSame($actor, $command->actor);
        $this->assertSame('Initial provisioning', $command->reason);
        $this->assertSame('Reviews documents.', $command->description);
        $this->assertSame(
            ['ticket' => 'SEC-42'],
            $command->metadata,
        );
        $this->assertSame(
            '2026-07-29 12:30:45.000000 +00:00',
            $command->requestedAt->format('Y-m-d H:i:s.u P'),
        );
    }

    public function test_command_preserves_nullable_reason_and_description(): void
    {
        $command = CreateRoleCommand::capture(
            name: 'reviewer',
            guard: 'web',
            actor: SystemActor::named('definition-suite'),
            reason: null,
            description: null,
            metadata: [],
            clock: new FrozenClock(
                new DateTimeImmutable('2026-07-29 12:00:00 UTC'),
            ),
        );

        $this->assertNull($command->reason);
        $this->assertNull($command->description);
        $this->assertSame([], $command->metadata);
    }
}

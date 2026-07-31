<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use DateTimeImmutable;
use DateTimeZone;
use HangTuah\GateTrail\Mutations\Commands\CreatePermissionCommand;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class CreatePermissionCommandTest extends TestCase
{
    public function test_command_captures_trimmed_inputs_and_normalized_time(): void
    {
        $actor = SystemActor::named('definition-suite');
        $metadata = ['ticket' => 'SEC-42'];

        $command = CreatePermissionCommand::capture(
            name: '  Documents.Approve  ',
            guard: '  web  ',
            actor: $actor,
            reason: 'Introduce approval capability',
            description: 'Approve submitted documents',
            metadata: $metadata,
            clock: new FrozenClock(
                new DateTimeImmutable(
                    '2026-07-29 20:30:45.987654',
                    new DateTimeZone('Asia/Kuala_Lumpur'),
                ),
            ),
        );
        $metadata['ticket'] = 'changed';

        $this->assertSame('Documents.Approve', $command->name);
        $this->assertSame('web', $command->guard);
        $this->assertSame($actor, $command->actor);
        $this->assertSame(
            'Introduce approval capability',
            $command->reason,
        );
        $this->assertSame(
            'Approve submitted documents',
            $command->description,
        );
        $this->assertSame(['ticket' => 'SEC-42'], $command->metadata);
        $this->assertSame(
            '2026-07-29 12:30:45.000000 +00:00',
            $command->requestedAt->format('Y-m-d H:i:s.u P'),
        );
        $this->assertSame(
            'UTC',
            $command->requestedAt->getTimezone()->getName(),
        );
    }

    public function test_command_preserves_a_null_reason_and_description(): void
    {
        $command = CreatePermissionCommand::capture(
            name: 'documents.approve',
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

    public function test_command_preserves_an_explicit_empty_description(): void
    {
        $command = CreatePermissionCommand::capture(
            name: 'documents.approve',
            guard: 'web',
            actor: SystemActor::named('definition-suite'),
            reason: null,
            description: '',
            metadata: [],
            clock: new FrozenClock(
                new DateTimeImmutable('2026-07-29 12:00:00 UTC'),
            ),
        );

        $this->assertSame('', $command->description);
        $this->assertNotNull($command->description);
    }

    public function test_command_truncates_microseconds_to_whole_seconds(): void
    {
        $command = CreatePermissionCommand::capture(
            name: 'documents.approve',
            guard: 'web',
            actor: SystemActor::named('definition-suite'),
            reason: null,
            description: null,
            metadata: [],
            clock: new FrozenClock(
                new DateTimeImmutable('2026-07-29 12:00:00.999999 UTC'),
            ),
        );

        $this->assertSame(
            '2026-07-29 12:00:00.000000',
            $command->requestedAt->format('Y-m-d H:i:s.u'),
        );
    }

    public function test_command_captures_a_model_actor_without_booting_laravel(): void
    {
        // No Laravel application exists in this test case, so any container,
        // connection, or model query would fatal rather than pass.
        $actor = new TestSubject;
        $actor->forceFill(['id' => 7, 'name' => 'Admin']);

        $command = CreatePermissionCommand::capture(
            name: 'documents.approve',
            guard: 'web',
            actor: $actor,
            reason: null,
            description: null,
            metadata: [],
            clock: new FrozenClock(
                new DateTimeImmutable('2026-07-29 12:00:00 UTC'),
            ),
        );

        $this->assertSame($actor, $command->actor);
        $this->assertFalse($actor->exists);
        $this->assertSame('documents.approve', $command->name);
    }

    public function test_command_is_final_readonly_and_privately_constructed(): void
    {
        $reflection = new ReflectionClass(CreatePermissionCommand::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
        $this->assertTrue(
            $reflection->getConstructor()?->isPrivate() ?? false,
        );
        $this->assertSame(
            ['__construct', 'capture'],
            array_map(
                static fn (ReflectionMethod $method): string => $method
                    ->getName(),
                $reflection->getMethods(),
            ),
        );
    }
}

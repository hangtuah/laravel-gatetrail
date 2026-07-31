<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use DateTimeImmutable;
use DateTimeZone;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Mutations\Commands\DeactivatePermissionCommand;
use HangTuah\GateTrail\PermissionReference;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class DeactivatePermissionCommandTest extends TestCase
{
    public function test_command_captures_reference_input_and_normalized_time(): void
    {
        $permission = PermissionReference::id(7);
        $actor = SystemActor::named('definition-suite');
        $metadata = ['ticket' => 'SEC-42'];

        $command = DeactivatePermissionCommand::capture(
            permission: $permission,
            actor: $actor,
            reason: 'Superseded by a narrower permission',
            metadata: $metadata,
            clock: new FrozenClock(
                new DateTimeImmutable(
                    '2026-07-29 20:30:45.987654',
                    new DateTimeZone('Asia/Kuala_Lumpur'),
                ),
            ),
        );
        $metadata['ticket'] = 'changed';

        $this->assertSame($permission, $command->permission);
        $this->assertSame($actor, $command->actor);
        $this->assertSame(
            'Superseded by a narrower permission',
            $command->reason,
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

    public function test_command_captures_a_model_input_without_booting_laravel(): void
    {
        // No Laravel application exists in this test case, so any container,
        // connection, or model query would fatal rather than pass.
        $permission = new Permission;
        $permission->forceFill([
            'id' => 7,
            'name' => 'documents.approve',
            'guard_name' => 'web',
        ]);

        $command = DeactivatePermissionCommand::capture(
            permission: $permission,
            actor: SystemActor::named('definition-suite'),
            reason: null,
            metadata: [],
            clock: new FrozenClock(
                new DateTimeImmutable('2026-07-29 12:00:00 UTC'),
            ),
        );

        $this->assertSame($permission, $command->permission);
        $this->assertFalse($permission->exists);
        $this->assertNull($command->reason);
        $this->assertSame([], $command->metadata);
    }

    public function test_command_preserves_a_string_name_input(): void
    {
        $command = DeactivatePermissionCommand::capture(
            permission: 'documents.approve',
            actor: SystemActor::named('definition-suite'),
            reason: null,
            metadata: [],
            clock: new FrozenClock(
                new DateTimeImmutable('2026-07-29 12:00:00 UTC'),
            ),
        );

        $this->assertSame('documents.approve', $command->permission);
    }

    public function test_command_preserves_an_integer_identifier(): void
    {
        $command = DeactivatePermissionCommand::capture(
            permission: 7,
            actor: SystemActor::named('definition-suite'),
            reason: null,
            metadata: [],
            clock: new FrozenClock(
                new DateTimeImmutable('2026-07-29 12:00:00 UTC'),
            ),
        );

        $this->assertSame(7, $command->permission);
    }

    public function test_command_truncates_microseconds_to_whole_seconds(): void
    {
        $command = DeactivatePermissionCommand::capture(
            permission: PermissionReference::id(
                '018f4ac0-73a6-7c3e-9a34-0242ac120002',
            ),
            actor: SystemActor::named('definition-suite'),
            reason: null,
            metadata: [],
            clock: new FrozenClock(
                new DateTimeImmutable('2026-07-29 12:00:00.999999 UTC'),
            ),
        );

        $this->assertInstanceOf(
            PermissionReference::class,
            $command->permission,
        );
        $this->assertSame(
            '018f4ac0-73a6-7c3e-9a34-0242ac120002',
            $command->permission->id,
        );
        $this->assertSame(
            '2026-07-29 12:00:00.000000',
            $command->requestedAt->format('Y-m-d H:i:s.u'),
        );
    }

    public function test_command_is_final_readonly_and_privately_constructed(): void
    {
        $reflection = new ReflectionClass(DeactivatePermissionCommand::class);

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

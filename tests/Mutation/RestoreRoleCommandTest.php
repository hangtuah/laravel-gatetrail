<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use DateTimeImmutable;
use DateTimeZone;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\Mutations\Commands\RestoreRoleCommand;
use HangTuah\GateTrail\RoleReference;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use PHPUnit\Framework\TestCase;

final class RestoreRoleCommandTest extends TestCase
{
    public function test_command_captures_reference_input_and_normalized_time(): void
    {
        $role = RoleReference::id(7);
        $actor = SystemActor::named('definition-suite');
        $metadata = ['ticket' => 'SEC-42'];

        $command = RestoreRoleCommand::capture(
            role: $role,
            actor: $actor,
            reason: 'Reinstated after review',
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
        $this->assertSame($actor, $command->actor);
        $this->assertSame('Reinstated after review', $command->reason);
        $this->assertSame(['ticket' => 'SEC-42'], $command->metadata);
        $this->assertSame(
            '2026-07-29 12:30:45.000000 +00:00',
            $command->requestedAt->format('Y-m-d H:i:s.u P'),
        );
    }

    public function test_command_captures_a_model_input_without_booting_laravel(): void
    {
        // No Laravel application exists in this test case, so any container,
        // connection, or model query would fatal rather than pass.
        $role = new Role;
        $role->forceFill([
            'id' => 7,
            'name' => 'reviewer',
            'guard_name' => 'web',
        ]);

        $command = RestoreRoleCommand::capture(
            role: $role,
            actor: SystemActor::named('definition-suite'),
            reason: null,
            metadata: [],
            clock: new FrozenClock(
                new DateTimeImmutable('2026-07-29 12:00:00 UTC'),
            ),
        );

        $this->assertSame($role, $command->role);
        $this->assertFalse($role->exists);
        $this->assertNull($command->reason);
        $this->assertSame([], $command->metadata);
    }

    public function test_command_preserves_a_string_name_and_null_reason(): void
    {
        $command = RestoreRoleCommand::capture(
            role: 'reviewer',
            actor: SystemActor::named('definition-suite'),
            reason: null,
            metadata: [],
            clock: new FrozenClock(
                new DateTimeImmutable('2026-07-29 12:00:00 UTC'),
            ),
        );

        $this->assertSame('reviewer', $command->role);
        $this->assertNull($command->reason);
    }

    public function test_command_preserves_an_integer_identifier(): void
    {
        $command = RestoreRoleCommand::capture(
            role: 7,
            actor: SystemActor::named('definition-suite'),
            reason: null,
            metadata: [],
            clock: new FrozenClock(
                new DateTimeImmutable('2026-07-29 12:00:00 UTC'),
            ),
        );

        $this->assertSame(7, $command->role);
    }

    public function test_command_truncates_microseconds_to_whole_seconds(): void
    {
        $command = RestoreRoleCommand::capture(
            role: RoleReference::id('018f4ac0-73a6-7c3e-9a34-0242ac120002'),
            actor: SystemActor::named('definition-suite'),
            reason: null,
            metadata: [],
            clock: new FrozenClock(
                new DateTimeImmutable('2026-07-29 12:00:00.999999 UTC'),
            ),
        );

        $this->assertInstanceOf(RoleReference::class, $command->role);
        $this->assertSame(
            '018f4ac0-73a6-7c3e-9a34-0242ac120002',
            $command->role->id,
        );
        $this->assertSame(
            '2026-07-29 12:00:00.000000',
            $command->requestedAt->format('Y-m-d H:i:s.u'),
        );
        $this->assertSame(
            'UTC',
            $command->requestedAt->getTimezone()->getName(),
        );
    }
}

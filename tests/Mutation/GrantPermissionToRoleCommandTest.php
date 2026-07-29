<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use DateTimeImmutable;
use DateTimeZone;
use HangTuah\GateTrail\AssignmentPeriod;
use HangTuah\GateTrail\Mutations\Commands\GrantPermissionToRoleCommand;
use HangTuah\GateTrail\PermissionReference;
use HangTuah\GateTrail\RoleReference;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use PHPUnit\Framework\TestCase;

final class GrantPermissionToRoleCommandTest extends TestCase
{
    public function test_command_captures_inputs_and_normalized_time(): void
    {
        $role = RoleReference::id('role-uuid');
        $permission = PermissionReference::id('permission-uuid');
        $actor = SystemActor::named('test-suite');
        $period = AssignmentPeriod::immediate();
        $metadata = ['ticket' => 'SEC-42'];

        $command = GrantPermissionToRoleCommand::capture(
            role: $role,
            permission: $permission,
            actor: $actor,
            reason: 'Role grant',
            period: $period,
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
        $this->assertSame($permission, $command->permission);
        $this->assertSame($actor, $command->actor);
        $this->assertSame('Role grant', $command->reason);
        $this->assertSame($period, $command->period);
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

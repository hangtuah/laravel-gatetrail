<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use DateTimeImmutable;
use HangTuah\GateTrail\AssignmentReference;
use HangTuah\GateTrail\Mutations\Commands\RevokeDirectPermissionCommand;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use PHPUnit\Framework\TestCase;

final class RevokeDirectPermissionCommandTest extends TestCase
{
    public function test_command_captures_input(): void
    {
        $ref = AssignmentReference::id(7);
        $c = RevokeDirectPermissionCommand::capture($ref, SystemActor::named('sync'), 'Revoke', [], new FrozenClock(new DateTimeImmutable('2026-07-29 12:00:00 UTC')));
        $this->assertSame($ref, $c->assignment);
        $this->assertSame('Revoke', $c->reason);
    }
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations\Commands;

use DateTimeImmutable;
use DateTimeZone;
use HangTuah\GateTrail\AssignmentReference;
use HangTuah\GateTrail\Contracts\PermissionAssignmentContract;
use HangTuah\GateTrail\SystemActor;
use Illuminate\Database\Eloquent\Model;
use Psr\Clock\ClockInterface;

/** @internal */
final readonly class RevokeDirectPermissionCommand
{
    /** @param array<string,mixed> $metadata */
    private function __construct(public PermissionAssignmentContract|AssignmentReference|string|int $assignment, public Model|SystemActor $actor, public string $reason, public array $metadata, public DateTimeImmutable $requestedAt) {}

    /** @param array<string,mixed> $metadata */
    public static function capture(PermissionAssignmentContract|AssignmentReference|string|int $assignment, Model|SystemActor $actor, string $reason, array $metadata, ClockInterface $clock): self
    {
        $utc = $clock->now()->setTimezone(new DateTimeZone('UTC'));
        $time = $utc->setTime((int) $utc->format('H'), (int) $utc->format('i'), (int) $utc->format('s'));

        return new self($assignment, $actor, $reason, $metadata, $time);
    }
}

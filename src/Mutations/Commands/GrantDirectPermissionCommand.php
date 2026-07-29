<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations\Commands;

use DateTimeImmutable;
use DateTimeZone;
use HangTuah\GateTrail\AssignmentPeriod;
use HangTuah\GateTrail\Contracts\PermissionContract;
use HangTuah\GateTrail\PermissionReference;
use HangTuah\GateTrail\SystemActor;
use Illuminate\Database\Eloquent\Model;
use Psr\Clock\ClockInterface;

/** @internal */
final readonly class GrantDirectPermissionCommand
{
    /** @param array<string, mixed> $metadata */
    private function __construct(
        public Model $subject,
        public PermissionContract|PermissionReference|string|int $permission,
        public Model|SystemActor $actor,
        public string $reason,
        public AssignmentPeriod $period,
        public array $metadata,
        public DateTimeImmutable $requestedAt,
    ) {}

    /** @param array<string, mixed> $metadata */
    public static function capture(
        Model $subject,
        PermissionContract|PermissionReference|string|int $permission,
        Model|SystemActor $actor,
        string $reason,
        AssignmentPeriod $period,
        array $metadata,
        ClockInterface $clock,
    ): self {
        $utc = $clock->now()->setTimezone(new DateTimeZone('UTC'));
        $time = $utc->setTime((int) $utc->format('H'), (int) $utc->format('i'), (int) $utc->format('s'));

        return new self($subject, $permission, $actor, $reason, $period, $metadata, $time);
    }
}

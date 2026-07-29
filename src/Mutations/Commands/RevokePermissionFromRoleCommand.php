<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations\Commands;

use DateTimeImmutable;
use DateTimeZone;
use HangTuah\GateTrail\AssignmentReference;
use HangTuah\GateTrail\Contracts\RolePermissionContract;
use HangTuah\GateTrail\SystemActor;
use Illuminate\Database\Eloquent\Model;
use Psr\Clock\ClockInterface;

/** @internal */
final readonly class RevokePermissionFromRoleCommand
{
    /** @param array<string, mixed> $metadata */
    private function __construct(
        public RolePermissionContract|AssignmentReference|string|int $assignment,
        public Model|SystemActor $actor,
        public string $reason,
        public array $metadata,
        public DateTimeImmutable $requestedAt,
    ) {}

    /** @param array<string, mixed> $metadata */
    public static function capture(
        RolePermissionContract|AssignmentReference|string|int $assignment,
        Model|SystemActor $actor,
        string $reason,
        array $metadata,
        ClockInterface $clock,
    ): self {
        $utc = $clock->now()->setTimezone(new DateTimeZone('UTC'));
        $requestedAt = $utc->setTime(
            (int) $utc->format('H'),
            (int) $utc->format('i'),
            (int) $utc->format('s'),
        );

        return new self(
            $assignment,
            $actor,
            $reason,
            $metadata,
            $requestedAt,
        );
    }
}

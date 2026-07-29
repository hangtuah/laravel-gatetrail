<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations\Commands;

use DateTimeImmutable;
use DateTimeZone;
use HangTuah\GateTrail\AssignmentReference;
use HangTuah\GateTrail\Contracts\RoleAssignmentContract;
use HangTuah\GateTrail\SystemActor;
use Illuminate\Database\Eloquent\Model;
use Psr\Clock\ClockInterface;

/**
 * @internal
 */
final readonly class RevokeRoleCommand
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    private function __construct(
        public RoleAssignmentContract|AssignmentReference|string|int $assignment,
        public Model|SystemActor $actor,
        public string $reason,
        public array $metadata,
        public DateTimeImmutable $requestedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function capture(
        RoleAssignmentContract|AssignmentReference|string|int $assignment,
        Model|SystemActor $actor,
        string $reason,
        array $metadata,
        ClockInterface $clock,
    ): self {
        return new self(
            $assignment,
            $actor,
            $reason,
            $metadata,
            self::normalize($clock->now()),
        );
    }

    private static function normalize(
        DateTimeImmutable $timestamp,
    ): DateTimeImmutable {
        $utc = $timestamp->setTimezone(new DateTimeZone('UTC'));

        return $utc->setTime(
            (int) $utc->format('H'),
            (int) $utc->format('i'),
            (int) $utc->format('s'),
        );
    }
}

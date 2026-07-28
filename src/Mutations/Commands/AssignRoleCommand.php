<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations\Commands;

use DateTimeImmutable;
use DateTimeZone;
use HangTuah\GateTrail\AssignmentPeriod;
use HangTuah\GateTrail\Contracts\RoleContract;
use HangTuah\GateTrail\RoleReference;
use HangTuah\GateTrail\SystemActor;
use Illuminate\Database\Eloquent\Model;
use Psr\Clock\ClockInterface;

/**
 * @internal
 */
final readonly class AssignRoleCommand
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    private function __construct(
        public Model $subject,
        public RoleContract|RoleReference|string|int $role,
        public Model|SystemActor $actor,
        public string $reason,
        public AssignmentPeriod $period,
        public array $metadata,
        public DateTimeImmutable $requestedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function capture(
        Model $subject,
        RoleContract|RoleReference|string|int $role,
        Model|SystemActor $actor,
        string $reason,
        AssignmentPeriod $period,
        array $metadata,
        ClockInterface $clock,
    ): self {
        return new self(
            $subject,
            $role,
            $actor,
            $reason,
            $period,
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

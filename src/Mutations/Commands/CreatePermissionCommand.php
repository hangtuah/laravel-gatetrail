<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations\Commands;

use DateTimeImmutable;
use DateTimeZone;
use HangTuah\GateTrail\SystemActor;
use Illuminate\Database\Eloquent\Model;
use Psr\Clock\ClockInterface;

/**
 * @internal
 */
final readonly class CreatePermissionCommand
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    private function __construct(
        public string $name,
        public string $guard,
        public Model|SystemActor $actor,
        public ?string $reason,
        public ?string $description,
        public array $metadata,
        public DateTimeImmutable $requestedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function capture(
        string $name,
        string $guard,
        Model|SystemActor $actor,
        ?string $reason,
        ?string $description,
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
            trim($name),
            trim($guard),
            $actor,
            $reason,
            $description,
            $metadata,
            $requestedAt,
        );
    }
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Assignments;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use HangTuah\GateTrail\AssignmentPeriod;
use HangTuah\GateTrail\Exceptions\InvalidAssignmentPeriod;

final readonly class EffectiveAssignmentPeriod
{
    private function __construct(
        private DateTimeImmutable $startsAt,
        private ?DateTimeImmutable $expiresAt,
    ) {}

    public static function fromAssignment(
        AssignmentPeriod $period,
        DateTimeInterface $assignedAt,
    ): self {
        return self::fromTimestamps(
            $assignedAt,
            $period->startsAt(),
            $period->expiresAt(),
        );
    }

    public static function fromStored(
        DateTimeInterface $assignedAt,
        ?DateTimeInterface $startsAt,
        ?DateTimeInterface $expiresAt,
    ): self {
        return self::fromTimestamps(
            $assignedAt,
            $startsAt,
            $expiresAt,
        );
    }

    public function startsAt(): DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function expiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function contains(DateTimeInterface $timestamp): bool
    {
        $time = self::normalize($timestamp);

        return $this->startsAt <= $time
            && ($this->expiresAt === null || $this->expiresAt > $time);
    }

    private static function fromTimestamps(
        DateTimeInterface $assignedAt,
        ?DateTimeInterface $startsAt,
        ?DateTimeInterface $expiresAt,
    ): self {
        $start = self::normalize($startsAt ?? $assignedAt);
        $expiry = $expiresAt === null
            ? null
            : self::normalize($expiresAt);

        if ($expiry !== null && $expiry <= $start) {
            throw InvalidAssignmentPeriod::expiryNotAfterStart(
                $start,
                $expiry,
            );
        }

        return new self($start, $expiry);
    }

    private static function normalize(
        DateTimeInterface $timestamp,
    ): DateTimeImmutable {
        $immutable = DateTimeImmutable::createFromInterface($timestamp)
            ->setTimezone(new DateTimeZone('UTC'));

        return $immutable->setTime(
            (int) $immutable->format('H'),
            (int) $immutable->format('i'),
            (int) $immutable->format('s'),
        );
    }
}

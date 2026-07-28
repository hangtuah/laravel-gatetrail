<?php

declare(strict_types=1);

namespace HangTuah\GateTrail;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use HangTuah\GateTrail\Exceptions\InvalidAssignmentPeriod;

final readonly class AssignmentPeriod
{
    private function __construct(
        private ?DateTimeImmutable $startsAt,
        private ?DateTimeImmutable $expiresAt,
    ) {}

    public static function immediate(): self
    {
        return new self(null, null);
    }

    public static function until(DateTimeInterface $expiresAt): self
    {
        return new self(
            null,
            self::normalize($expiresAt),
        );
    }

    public static function between(
        DateTimeInterface $startsAt,
        ?DateTimeInterface $expiresAt = null,
    ): self {
        $start = self::normalize($startsAt);
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

    public function startsAt(): ?DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function expiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isImmediate(): bool
    {
        return $this->startsAt === null && $this->expiresAt === null;
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

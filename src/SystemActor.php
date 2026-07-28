<?php

declare(strict_types=1);

namespace HangTuah\GateTrail;

use HangTuah\GateTrail\Exceptions\InvalidActor;
use Illuminate\Support\Str;

final readonly class SystemActor
{
    public const TYPE = 'gatetrail.system';

    private function __construct(
        public string $name,
    ) {}

    public static function named(string $name): self
    {
        $normalized = trim($name);

        if ($normalized === '') {
            throw InvalidActor::emptySystemName();
        }

        if (Str::length($normalized) > 191) {
            throw InvalidActor::valueTooLong('system actor name');
        }

        return new self($normalized);
    }
}

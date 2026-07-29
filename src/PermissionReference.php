<?php

declare(strict_types=1);

namespace HangTuah\GateTrail;

use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class PermissionReference
{
    private function __construct(public int|string $id) {}

    public static function id(int|string $id): self
    {
        if (is_string($id) && trim($id) === '') {
            throw new InvalidArgumentException(
                'GateTrail permission reference ID cannot be empty.',
            );
        }

        if (is_string($id) && Str::length($id) > 191) {
            throw new InvalidArgumentException(
                'GateTrail permission reference ID cannot exceed 191 characters.',
            );
        }

        return new self($id);
    }
}

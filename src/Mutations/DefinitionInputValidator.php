<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * @internal
 */
final readonly class DefinitionInputValidator
{
    public function name(string $value): string
    {
        return $this->identifier($value, 'name');
    }

    public function guard(string $value): string
    {
        return $this->identifier($value, 'guard name');
    }

    public function description(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $this->assertUtf8($value, 'description');

        return $value;
    }

    private function identifier(string $value, string $label): string
    {
        $this->assertUtf8($value, $label);
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new InvalidArgumentException(
                sprintf(
                    'GateTrail definition %s cannot be empty.',
                    $label,
                ),
            );
        }

        if (Str::length($trimmed) > 191) {
            throw new InvalidArgumentException(
                sprintf(
                    'GateTrail definition %s cannot exceed 191 characters.',
                    $label,
                ),
            );
        }

        return $trimmed;
    }

    private function assertUtf8(string $value, string $label): void
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException(
                sprintf(
                    'GateTrail definition %s must be valid UTF-8.',
                    $label,
                ),
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Exceptions;

use RuntimeException;

final class RoleNotFound extends RuntimeException
{
    private function __construct(
        public readonly string $guard,
        public readonly string $referenceType,
    ) {
        parent::__construct(
            sprintf(
                'GateTrail role was not found for guard [%s] using [%s] input.',
                $guard,
                $referenceType,
            ),
        );
    }

    public static function forGuard(
        string $guard,
        string $referenceType,
    ): self {
        return new self($guard, $referenceType);
    }
}

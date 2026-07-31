<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Exceptions;

use RuntimeException;

final class PermissionNotFound extends RuntimeException
{
    private function __construct(
        public readonly ?string $guard,
        public readonly string $referenceType,
    ) {
        parent::__construct(
            $guard === null
                ? sprintf(
                    'GateTrail permission was not found using [%s] input.',
                    $referenceType,
                )
                : sprintf(
                    'GateTrail permission was not found for guard [%s] using [%s] input.',
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

    /**
     * Reports an explicit model, identifier, or reference that resolved to no
     * row, so no persisted guard exists and no default guard may be consulted
     * merely to describe the failure.
     */
    public static function forReference(string $referenceType): self
    {
        return new self(null, $referenceType);
    }
}

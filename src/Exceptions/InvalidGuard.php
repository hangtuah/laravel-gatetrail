<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Exceptions;

use InvalidArgumentException;

final class InvalidGuard extends InvalidArgumentException
{
    private function __construct(
        public readonly string $contextType,
        public readonly string $source,
    ) {
        parent::__construct(
            sprintf(
                'GateTrail could not resolve a valid guard for [%s] from [%s].',
                $contextType,
                $source,
            ),
        );
    }

    public static function forSubject(
        string $subjectType,
        string $source,
    ): self {
        return new self($subjectType, $source);
    }

    public static function forTarget(string $targetType): self
    {
        return new self($targetType, 'persisted target');
    }
}

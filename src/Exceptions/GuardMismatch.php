<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Exceptions;

use RuntimeException;

final class GuardMismatch extends RuntimeException
{
    private function __construct(
        public readonly string $subjectGuard,
        public readonly string $targetGuard,
        public readonly string $targetType,
        public readonly int|string|null $targetId,
    ) {
        parent::__construct(
            sprintf(
                'GateTrail subject guard [%s] does not match %s guard [%s].',
                $subjectGuard,
                $targetType,
                $targetGuard,
            ),
        );
    }

    public static function forRole(
        string $subjectGuard,
        string $roleGuard,
        int|string|null $roleId,
    ): self {
        return new self(
            $subjectGuard,
            $roleGuard,
            'role',
            $roleId,
        );
    }
}

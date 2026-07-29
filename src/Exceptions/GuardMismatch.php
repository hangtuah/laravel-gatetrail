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
        string $sourceType = 'subject',
    ) {
        parent::__construct(
            sprintf(
                'GateTrail %s guard [%s] does not match %s guard [%s].',
                $sourceType,
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

    public static function forPermission(
        string $subjectGuard,
        string $permissionGuard,
        int|string|null $permissionId,
    ): self {
        return new self(
            $subjectGuard,
            $permissionGuard,
            'permission',
            $permissionId,
        );
    }

    public static function forRolePermission(
        string $roleGuard,
        string $permissionGuard,
        int|string|null $permissionId,
    ): self {
        return new self(
            $roleGuard,
            $permissionGuard,
            'permission',
            $permissionId,
            'role',
        );
    }
}

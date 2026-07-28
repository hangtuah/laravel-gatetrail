<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Assignments;

use InvalidArgumentException;

final readonly class AssignmentScope
{
    private const FORMAT_VERSION = 'v1';

    private function __construct(
        private AssignmentKind $kind,
        /** @var array<string, string> */
        private array $components,
        private string $key,
        private string $hash,
    ) {}

    public static function forRole(
        string $subjectType,
        int|string $subjectId,
        int|string $roleId,
    ): self {
        return self::make(
            AssignmentKind::Role,
            [
                'subject_type' => self::normalizeSubjectType($subjectType),
                'subject_id' => self::normalizeNumericId(
                    $subjectId,
                    'subject ID',
                ),
                'role_id' => self::normalizeNumericId($roleId, 'role ID'),
            ],
        );
    }

    public static function forPermission(
        string $subjectType,
        int|string $subjectId,
        int|string $permissionId,
    ): self {
        return self::make(
            AssignmentKind::Permission,
            [
                'subject_type' => self::normalizeSubjectType($subjectType),
                'subject_id' => self::normalizeNumericId(
                    $subjectId,
                    'subject ID',
                ),
                'permission_id' => self::normalizeNumericId(
                    $permissionId,
                    'permission ID',
                ),
            ],
        );
    }

    public static function forRolePermission(
        int|string $roleId,
        int|string $permissionId,
    ): self {
        return self::make(
            AssignmentKind::RolePermission,
            [
                'role_id' => self::normalizeNumericId($roleId, 'role ID'),
                'permission_id' => self::normalizeNumericId(
                    $permissionId,
                    'permission ID',
                ),
            ],
        );
    }

    public function kind(): AssignmentKind
    {
        return $this->kind;
    }

    public function key(): string
    {
        return $this->key;
    }

    /**
     * @return array<string, string>
     */
    public function components(): array
    {
        return $this->components;
    }

    public function hash(): string
    {
        return $this->hash;
    }

    /**
     * @param  array<string, string>  $components
     */
    private static function make(
        AssignmentKind $kind,
        array $components,
    ): self {
        $segments = [
            self::FORMAT_VERSION,
            self::segment($kind->value),
        ];

        foreach ($components as $name => $value) {
            $segments[] = self::segment($name);
            $segments[] = self::segment($value);
        }

        $key = implode('|', $segments);

        return new self(
            $kind,
            $components,
            $key,
            hash('sha256', $key),
        );
    }

    private static function segment(string $value): string
    {
        return strlen($value).':'.$value;
    }

    private static function normalizeSubjectType(string $subjectType): string
    {
        if ($subjectType === '') {
            throw new InvalidArgumentException(
                'GateTrail assignment subject type cannot be empty.',
            );
        }

        return $subjectType;
    }

    private static function normalizeNumericId(
        int|string $id,
        string $label,
    ): string {
        $value = (string) $id;

        if (preg_match('/^[0-9]+$/', $value) !== 1) {
            throw new InvalidArgumentException(
                sprintf(
                    'GateTrail assignment %s must be an unsigned decimal integer.',
                    $label,
                ),
            );
        }

        $normalized = ltrim($value, '0');

        return $normalized === '' ? '0' : $normalized;
    }
}

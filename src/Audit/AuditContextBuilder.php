<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Audit;

use InvalidArgumentException;

/**
 * @internal
 */
final readonly class AuditContextBuilder
{
    public function __construct(
        private CanonicalAuditJson $json,
    ) {}

    public function roleAssignment(
        string $guardName,
        string $roleName,
        NormalizedMetadata $metadata,
    ): string {
        $this->assertName($guardName, 'guard name');
        $this->assertName($roleName, 'role name');

        return $this->json->object([
            'schema_version' => 1,
            'guard_name' => $guardName,
            'role_name' => $roleName,
            'metadata' => $metadata->value,
        ]);
    }

    public function directPermissionAssignment(
        string $guardName,
        string $permissionName,
        NormalizedMetadata $metadata,
    ): string {
        $this->assertName($guardName, 'guard name');
        $this->assertName($permissionName, 'permission name');

        return $this->json->object([
            'schema_version' => 1,
            'guard_name' => $guardName,
            'permission_name' => $permissionName,
            'metadata' => $metadata->value,
        ]);
    }

    public function rolePermissionAssignment(
        string $guardName,
        string $roleName,
        string $permissionName,
        NormalizedMetadata $metadata,
    ): string {
        $this->assertName($guardName, 'guard name');
        $this->assertName($roleName, 'role name');
        $this->assertName($permissionName, 'permission name');

        return $this->json->object([
            'schema_version' => 1,
            'guard_name' => $guardName,
            'role_name' => $roleName,
            'permission_name' => $permissionName,
            'metadata' => $metadata->value,
        ]);
    }

    private function assertName(string $value, string $label): void
    {
        if ($value === '') {
            throw new InvalidArgumentException(
                sprintf(
                    'GateTrail audit %s cannot be empty.',
                    $label,
                ),
            );
        }
    }
}

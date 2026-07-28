<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Support;

use HangTuah\GateTrail\Contracts\PermissionAssignmentContract;
use HangTuah\GateTrail\Contracts\PermissionContract;
use HangTuah\GateTrail\Contracts\RoleAssignmentContract;
use HangTuah\GateTrail\Contracts\RoleContract;
use HangTuah\GateTrail\Contracts\RolePermissionContract;
use HangTuah\GateTrail\Exceptions\InvalidModelConfiguration;
use HangTuah\GateTrail\Models\AccessAudit;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\PermissionAssignment;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\Models\RoleAssignment;
use HangTuah\GateTrail\Models\RolePermission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class ModelRegistry
{
    /**
     * @param  array<string, mixed>  $models
     */
    public function __construct(
        private readonly array $models,
    ) {}

    /**
     * @return class-string<Role&RoleContract>
     */
    public function role(): string
    {
        /** @var class-string<Role&RoleContract> */
        return $this->resolve('role', Role::class, RoleContract::class, true);
    }

    /**
     * @return class-string<Permission&PermissionContract>
     */
    public function permission(): string
    {
        /** @var class-string<Permission&PermissionContract> */
        return $this->resolve(
            'permission',
            Permission::class,
            PermissionContract::class,
            true,
        );
    }

    /**
     * @return class-string<RoleAssignment&RoleAssignmentContract>
     */
    public function roleAssignment(): string
    {
        /** @var class-string<RoleAssignment&RoleAssignmentContract> */
        return $this->resolve(
            'role_assignment',
            RoleAssignment::class,
            RoleAssignmentContract::class,
        );
    }

    /**
     * @return class-string<PermissionAssignment&PermissionAssignmentContract>
     */
    public function permissionAssignment(): string
    {
        /** @var class-string<PermissionAssignment&PermissionAssignmentContract> */
        return $this->resolve(
            'permission_assignment',
            PermissionAssignment::class,
            PermissionAssignmentContract::class,
        );
    }

    /**
     * @return class-string<RolePermission&RolePermissionContract>
     */
    public function rolePermission(): string
    {
        /** @var class-string<RolePermission&RolePermissionContract> */
        return $this->resolve(
            'role_permission',
            RolePermission::class,
            RolePermissionContract::class,
        );
    }

    /**
     * @return class-string<AccessAudit>
     */
    public function accessAudit(): string
    {
        /** @var class-string<AccessAudit> */
        return $this->resolve('access_audit', AccessAudit::class);
    }

    /**
     * @param  class-string<Model>  $default
     * @param  class-string|null  $contract
     * @return class-string<Model>
     */
    private function resolve(
        string $key,
        string $default,
        ?string $contract = null,
        bool $requiresSoftDeletes = false,
    ): string {
        $class = $this->models[$key] ?? $default;

        if (! is_string($class) || $class === '') {
            throw InvalidModelConfiguration::missing($key);
        }

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            throw InvalidModelConfiguration::notEloquentModel($key, $class);
        }

        if ($contract !== null && ! is_subclass_of($class, $contract)) {
            throw InvalidModelConfiguration::missingContract(
                $key,
                $class,
                $contract,
            );
        }

        if (
            $requiresSoftDeletes
            && ! in_array(SoftDeletes::class, class_uses_recursive($class), true)
        ) {
            throw InvalidModelConfiguration::missingSoftDeletes($key, $class);
        }

        /** @var class-string<Model> $class */
        return $class;
    }
}

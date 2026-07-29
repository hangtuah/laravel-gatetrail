<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations;

use HangTuah\GateTrail\Contracts\PermissionContract;
use HangTuah\GateTrail\Contracts\RoleContract;
use HangTuah\GateTrail\Exceptions\GuardMismatch;
use HangTuah\GateTrail\Exceptions\InvalidGuard;
use HangTuah\GateTrail\Exceptions\PermissionNotFound;
use HangTuah\GateTrail\Exceptions\RoleNotFound;
use HangTuah\GateTrail\Guards\DefaultGuardResolver;
use HangTuah\GateTrail\PermissionReference;
use HangTuah\GateTrail\RoleReference;
use HangTuah\GateTrail\Support\ModelRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;
use ReflectionMethod;

final readonly class RolePermissionDefinitionResolver
{
    private const CONTEXT = 'role-permission assignment';

    public function __construct(
        private ModelRegistry $models,
        private DefaultGuardResolver $guards,
    ) {}

    /**
     * @return array{
     *     Model&RoleContract,
     *     Model&PermissionContract,
     *     string
     * }
     */
    public function resolve(
        RoleContract|RoleReference|string|int $roleInput,
        PermissionContract|PermissionReference|string|int $permissionInput,
    ): array {
        $this->assertTransaction();

        if (is_string($roleInput) && is_string($permissionInput)) {
            $guard = $this->guards->resolve(self::CONTEXT);
            $role = $this->resolveRole($roleInput, $guard);
            $permission = $this->resolvePermission(
                $permissionInput,
                $guard,
            );

            return [$role, $permission, $guard];
        }

        if (is_string($roleInput)) {
            $permission = $this->resolvePermission($permissionInput);
            $guard = $this->guard($permission, 'permission');
            $role = $this->resolveRole($roleInput, $guard);

            return [$role, $permission, $guard];
        }

        $role = $this->resolveRole($roleInput);
        $guard = $this->guard($role, 'role');
        $permission = $this->resolvePermission(
            $permissionInput,
            is_string($permissionInput) ? $guard : null,
        );
        $permissionGuard = $this->guard(
            $permission,
            'permission',
        );

        if ($guard !== $permissionGuard) {
            $permissionId = $permission->getKey();

            throw GuardMismatch::forRolePermission(
                $guard,
                $permissionGuard,
                is_int($permissionId) || is_string($permissionId)
                    ? $permissionId
                    : null,
            );
        }

        return [$role, $permission, $guard];
    }

    private function assertTransaction(): void
    {
        $roleClass = $this->models->role();
        $role = new $roleClass;

        if ($role->getConnection()->transactionLevel() === 0) {
            throw new LogicException(
                'GateTrail role-permission definitions must be resolved '
                .'inside a database transaction.',
            );
        }
    }

    private function resolveRole(
        RoleContract|RoleReference|string|int $input,
        ?string $guard = null,
    ): Model&RoleContract {
        $class = $this->models->role();

        if ($input instanceof RoleContract) {
            if (! $input instanceof Model || ! $input->exists) {
                throw RoleNotFound::forGuard(
                    $guard ?? $this->guards->resolve(self::CONTEXT),
                    'model',
                );
            }

            $role = $input;
        } elseif (is_string($input)) {
            if ($guard === null) {
                throw new LogicException(
                    'GateTrail role name resolution requires a guard.',
                );
            }

            $role = $class::query()
                ->where('name', $input)
                ->where('guard_name', $guard)
                ->first();
        } else {
            $id = $input instanceof RoleReference
                ? $input->id
                : $input;
            $role = $class::query()->find($id);
        }

        if (! $role instanceof Model || $this->isDeactivated($role)) {
            throw RoleNotFound::forGuard(
                $guard ?? $this->guards->resolve(self::CONTEXT),
                is_string($input) ? 'name' : 'ID',
            );
        }

        if ($guard !== null
            && $this->guard($role, 'role') !== $guard) {
            $roleId = $role->getKey();

            throw GuardMismatch::forRole(
                $guard,
                $this->guard($role, 'role'),
                is_int($roleId) || is_string($roleId)
                    ? $roleId
                    : null,
            );
        }

        return $role;
    }

    private function resolvePermission(
        PermissionContract|PermissionReference|string|int $input,
        ?string $guard = null,
    ): Model&PermissionContract {
        $class = $this->models->permission();

        if ($input instanceof PermissionContract) {
            if (! $input instanceof Model || ! $input->exists) {
                throw PermissionNotFound::forGuard(
                    $guard ?? $this->guards->resolve(self::CONTEXT),
                    'model',
                );
            }

            $permission = $input;
        } elseif (is_string($input)) {
            if ($guard === null) {
                throw new LogicException(
                    'GateTrail permission name resolution requires a guard.',
                );
            }

            $permission = $class::query()
                ->where('name', $input)
                ->where('guard_name', $guard)
                ->first();
        } else {
            $id = $input instanceof PermissionReference
                ? $input->id
                : $input;
            $permission = $class::query()->find($id);
        }

        if (! $permission instanceof Model
            || $this->isDeactivated($permission)) {
            throw PermissionNotFound::forGuard(
                $guard ?? $this->guards->resolve(self::CONTEXT),
                is_string($input) ? 'name' : 'ID',
            );
        }

        return $permission;
    }

    private function guard(Model $definition, string $type): string
    {
        $guard = $definition->getAttribute('guard_name');

        if (! is_string($guard) || trim($guard) === '') {
            throw InvalidGuard::forTarget($type);
        }

        return trim($guard);
    }

    private function isDeactivated(Model $definition): bool
    {
        if (! in_array(
            SoftDeletes::class,
            class_uses_recursive($definition),
            true,
        )) {
            return false;
        }

        return (new ReflectionMethod($definition, 'trashed'))
            ->invoke($definition) === true;
    }
}

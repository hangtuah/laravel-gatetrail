<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations;

use HangTuah\GateTrail\Contracts\RoleContract;
use HangTuah\GateTrail\Exceptions\GuardMismatch;
use HangTuah\GateTrail\Exceptions\InvalidGuard;
use HangTuah\GateTrail\Exceptions\RoleNotFound;
use HangTuah\GateTrail\Guards\SubjectGuardResolver;
use HangTuah\GateTrail\RoleReference;
use HangTuah\GateTrail\Support\ModelRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;
use ReflectionMethod;

/**
 * @internal
 */
final readonly class RoleInputResolver
{
    public function __construct(
        private ModelRegistry $models,
        private SubjectGuardResolver $guards,
    ) {}

    public function resolve(
        Model $subject,
        RoleContract|RoleReference|string|int $input,
    ): Model&RoleContract {
        $guard = $this->guards->resolve($subject);
        $model = $this->models->role();
        $instance = new $model;

        if ($instance->getConnection()->transactionLevel() === 0) {
            throw new LogicException(
                'GateTrail role inputs must be resolved inside a database transaction.',
            );
        }

        if ($input instanceof RoleContract) {
            if (! $input instanceof Model || ! $input->exists) {
                throw RoleNotFound::forGuard($guard, 'model');
            }

            $role = $input;
        } elseif (is_string($input)) {
            $role = $model::query()
                ->where('name', $input)
                ->where('guard_name', $guard)
                ->first();

            if ($role === null) {
                throw RoleNotFound::forGuard($guard, 'name');
            }
        } else {
            $id = $input instanceof RoleReference
                ? $input->id
                : $input;
            $role = $model::query()->find($id);

            if ($role === null) {
                throw RoleNotFound::forGuard($guard, 'ID');
            }
        }

        if ($this->isDeactivated($role)) {
            throw RoleNotFound::forGuard($guard, 'deactivated role');
        }

        $targetGuard = $role->getAttribute('guard_name');

        if (! is_string($targetGuard) || trim($targetGuard) === '') {
            throw InvalidGuard::forTarget('role');
        }

        $targetGuard = trim($targetGuard);

        if ($guard !== $targetGuard) {
            $roleId = $role->getKey();

            throw GuardMismatch::forRole(
                $guard,
                $targetGuard,
                is_int($roleId) || is_string($roleId)
                    ? $roleId
                    : null,
            );
        }

        return $role;
    }

    private function isDeactivated(Model $role): bool
    {
        if (! in_array(
            SoftDeletes::class,
            class_uses_recursive($role),
            true,
        )) {
            return false;
        }

        $trashed = new ReflectionMethod($role, 'trashed');

        return $trashed->invoke($role) === true;
    }
}

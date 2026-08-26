<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations;

use HangTuah\GateTrail\Contracts\PermissionContract;
use HangTuah\GateTrail\Exceptions\GuardMismatch;
use HangTuah\GateTrail\Exceptions\InvalidGuard;
use HangTuah\GateTrail\Exceptions\PermissionNotFound;
use HangTuah\GateTrail\Guards\SubjectGuardResolver;
use HangTuah\GateTrail\PermissionReference;
use HangTuah\GateTrail\Support\ModelRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;
use ReflectionMethod;

/**
 * @internal
 */
final readonly class PermissionInputResolver
{
    public function __construct(
        private ModelRegistry $models,
        private SubjectGuardResolver $guards,
    ) {}

    public function resolve(
        Model $subject,
        PermissionContract|PermissionReference|string|int $input,
    ): Model&PermissionContract {
        $guard = $this->guards->resolve($subject);
        $model = $this->models->permission();
        $instance = new $model;

        if ($instance->getConnection()->transactionLevel() === 0) {
            throw new LogicException(
                'GateTrail permission inputs must be resolved inside a database transaction.',
            );
        }

        if ($input instanceof PermissionContract) {
            if (! $input instanceof Model || ! $input->exists) {
                throw PermissionNotFound::forGuard($guard, 'model');
            }
            $permission = $input;
        } elseif (is_string($input)) {
            $permission = $model::query()
                ->where('name', $input)
                ->where('guard_name', $guard)
                ->first();
            if ($permission === null) {
                throw PermissionNotFound::forGuard($guard, 'name');
            }
        } else {
            $id = $input instanceof PermissionReference ? $input->id : $input;
            $permission = $model::query()->find($id);
            if ($permission === null) {
                throw PermissionNotFound::forGuard($guard, 'ID');
            }
        }

        if ($this->isDeactivated($permission)) {
            throw PermissionNotFound::forGuard($guard, 'deactivated permission');
        }

        $targetGuard = $permission->getAttribute('guard_name');
        if (! is_string($targetGuard) || trim($targetGuard) === '') {
            throw InvalidGuard::forTarget('permission');
        }
        $targetGuard = trim($targetGuard);

        if ($guard !== $targetGuard) {
            $id = $permission->getKey();
            throw GuardMismatch::forPermission(
                $guard,
                $targetGuard,
                is_int($id) || is_string($id) ? $id : null,
            );
        }

        return $permission;
    }

    private function isDeactivated(Model $permission): bool
    {
        if (! in_array(SoftDeletes::class, class_uses_recursive($permission), true)) {
            return false;
        }

        return (new ReflectionMethod($permission, 'trashed'))
            ->invoke($permission) === true;
    }
}

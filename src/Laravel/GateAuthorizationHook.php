<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Laravel;

use HangTuah\GateTrail\Contracts\PermissionResolver;
use HangTuah\GateTrail\Guards\SubjectGuardResolver;
use HangTuah\GateTrail\Support\ModelRegistry;
use Illuminate\Database\Eloquent\Model;

/**
 * Owns only argument-free Laravel abilities backed by a GateTrail permission
 * definition in the subject's resolved guard.
 *
 * @internal
 */
final readonly class GateAuthorizationHook
{
    public function __construct(
        private SubjectGuardResolver $guards,
        private ModelRegistry $models,
        private PermissionResolver $permissions,
    ) {}

    /**
     * @param  array<array-key, mixed>  $arguments
     */
    public function authorize(
        mixed $subject,
        string $ability,
        array $arguments = [],
    ): ?bool {
        if (! $subject instanceof Model || $arguments !== []) {
            return null;
        }

        $guard = $this->guards->resolve($subject);
        $permissionClass = $this->models->permission();
        $permission = (new $permissionClass)
            ->newQuery()
            ->withTrashed()
            ->where('guard_name', $guard)
            ->where('name', $ability)
            ->first();

        if ($permission === null) {
            return null;
        }

        if ($permission->getAttribute($permission->getDeletedAtColumn()) !== null) {
            return false;
        }

        return $this->permissions->allows($subject, $permission);
    }
}

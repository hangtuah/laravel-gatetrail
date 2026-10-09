<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Concerns;

use HangTuah\GateTrail\Access\EffectivePermission;
use HangTuah\GateTrail\Access\EffectiveRole;
use HangTuah\GateTrail\Access\RequestedDefinitionNormalizer;
use HangTuah\GateTrail\Contracts\PermissionContract;
use HangTuah\GateTrail\Contracts\PermissionResolver;
use HangTuah\GateTrail\Contracts\RoleContract;
use HangTuah\GateTrail\Contracts\RoleResolver;
use HangTuah\GateTrail\PermissionReference;
use HangTuah\GateTrail\RoleReference;
use HangTuah\GateTrail\Support\ModelRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;
use ReflectionObject;

/** @mixin Model */
// Consumer models use this trait outside the package source tree.
// @phpstan-ignore trait.unused
trait HasGateTrail
{
    public function hasRole(
        RoleContract|RoleReference|string|int $role,
    ): bool {
        return app(RoleResolver::class)->has($this, $role);
    }

    /**
     * @param  array<int, RoleContract|RoleReference|string|int>  $roles
     */
    public function hasAnyRole(array $roles): bool
    {
        return $this->matchesRoles($roles, requireAll: false);
    }

    /**
     * @param  array<int, RoleContract|RoleReference|string|int>  $roles
     */
    public function hasAllRoles(array $roles): bool
    {
        return $this->matchesRoles($roles, requireAll: true);
    }

    public function hasPermissionTo(
        PermissionContract|PermissionReference|string|int $permission,
    ): bool {
        return app(PermissionResolver::class)->allows($this, $permission);
    }

    /** @return Collection<int, EffectivePermission> */
    public function effectivePermissions(): Collection
    {
        return app(PermissionResolver::class)->effectivePermissions($this);
    }

    /** @return Collection<int, EffectiveRole> */
    public function roles(): Collection
    {
        return app(RoleResolver::class)->effectiveRoles($this);
    }

    /**
     * Raw direct-definition relation. Assignment lifecycle is intentionally
     * not interpreted here; authorization checks use PermissionResolver.
     *
     * @return MorphToMany<Model&PermissionContract, $this>
     */
    public function directPermissions(): MorphToMany
    {
        $models = app(ModelRegistry::class);
        $assignmentClass = $models->permissionAssignment();
        $assignment = new $assignmentClass;

        return $this->morphToMany(
            $models->permission(),
            'subject',
            $assignment->getTable(),
            'subject_id',
            'permission_id',
        );
    }

    /** @return MorphMany<Model, $this> */
    public function roleAssignments(): MorphMany
    {
        return $this->morphMany(
            app(ModelRegistry::class)->roleAssignment(),
            'subject',
        );
    }

    /** @return MorphMany<Model, $this> */
    public function permissionAssignments(): MorphMany
    {
        return $this->morphMany(
            app(ModelRegistry::class)->permissionAssignment(),
            'subject',
        );
    }

    /**
     * @internal
     *
     * @return array{defined: bool, value: mixed}
     */
    final public function gateTrailGuardNameFromDeclaredProperty(): array
    {
        $reflection = new ReflectionObject($this);

        if (! $reflection->hasProperty('gateTrailGuardName')) {
            return ['defined' => false, 'value' => null];
        }

        $property = $reflection->getProperty('gateTrailGuardName');

        if ($property->isPrivate()) {
            return ['defined' => false, 'value' => null];
        }

        return [
            'defined' => true,
            'value' => $property->getValue($this),
        ];
    }

    /**
     * @param  array<int, RoleContract|RoleReference|string|int>  $roles
     */
    private function matchesRoles(array $roles, bool $requireAll): bool
    {
        if ($roles === []) {
            return false;
        }

        $resolver = app(RoleResolver::class);
        $effective = $resolver->effectiveRoles($this);
        $models = app(ModelRegistry::class);
        $roleClass = $models->role();
        $normalizer = app(RequestedDefinitionNormalizer::class);

        foreach ($roles as $role) {
            $requested = $normalizer->normalize($role, new $roleClass);
            $present = $requested !== null && ($requested->name !== null
                ? $effective->contains(
                    static fn (EffectiveRole $item): bool => $item->name === $requested->name,
                )
                : $effective->contains(
                    static fn (EffectiveRole $item): bool => $item->id === $requested->identifier,
                ));

            if (! $requireAll && $present) {
                return true;
            }

            if ($requireAll && ! $present) {
                return false;
            }
        }

        return $requireAll;
    }
}

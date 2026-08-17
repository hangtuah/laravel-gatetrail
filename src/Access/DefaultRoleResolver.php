<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Access;

use HangTuah\GateTrail\Contracts\RoleContract;
use HangTuah\GateTrail\Contracts\RoleResolver;
use HangTuah\GateTrail\RoleReference;
use HangTuah\GateTrail\Support\ModelRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The public role resolver, as a projection over one snapshot.
 *
 * It shares the snapshot with {@see DefaultPermissionResolver} rather than
 * computing roles of its own, so `hasRole('editor')` can never disagree with
 * the roles that granted a permission in the same evaluation. Every lifecycle
 * rule, guard rule, query and ordering rule stays in
 * {@see EffectiveAccessResolver} and {@see EffectiveAccessSnapshot}.
 *
 * A role is not a permission. A role that grants nothing at all is still
 * reported, because a role name is not an authorization bypass by itself and
 * an unpermissioned role must remain visible.
 *
 * **Normalization happens before the snapshot is built**, so an impossible
 * input denies at zero cost and is never bound into a statement. A valid input
 * builds exactly one snapshot and compares in memory; **no separate definition
 * lookup is ever issued**.
 *
 * Missing, deactivated and cross-guard roles are all absent from the snapshot,
 * and absence is one answer — a caller cannot distinguish them. A denial never
 * raises `RoleNotFound` or `GuardMismatch`; the mutation paths keep those, and
 * correctly answer differently. An unresolvable subject or guard still raises,
 * and a database failure still propagates.
 *
 * @see docs/decisions/0012-effective-access-resolution.md
 */
final readonly class DefaultRoleResolver implements RoleResolver
{
    public function __construct(
        private EffectiveAccessResolver $access,
        private RequestedDefinitionNormalizer $requests,
        private ModelRegistry $models,
    ) {}

    public function has(
        Model $subject,
        RoleContract|RoleReference|string|int $role,
    ): bool {
        $definitionClass = $this->models->role();

        $requested = $this->requests->normalize($role, new $definitionClass);

        if ($requested === null) {
            return false;
        }

        $snapshot = $this->access->resolve($subject);

        if ($requested->name !== null) {
            return $snapshot->hasRoleNamed($requested->name);
        }

        if ($requested->identifier !== null) {
            return $snapshot->hasRoleId($requested->identifier);
        }

        // Unreachable: a normalized request always carries one of the two.
        // Kept because the only safe direction to fall is closed.
        return false;
    }

    /**
     * @return Collection<int, EffectiveRole>
     */
    public function effectiveRoles(Model $subject): Collection
    {
        return $this->access->resolve($subject)->roles();
    }
}

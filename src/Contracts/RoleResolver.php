<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Contracts;

use HangTuah\GateTrail\Access\EffectiveRole;
use HangTuah\GateTrail\RoleReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The source of truth for every GateTrail role decision.
 *
 * Both methods are projections over one evaluated state, so `has()` is true
 * for exactly the roles in `effectiveRoles()` within a single evaluation. This
 * interface carries no lifecycle rule, guard rule, query or ordering rule of
 * its own; an implementation delegates all of that to the shared internal
 * resolution.
 *
 * Roles are resolved separately from permissions rather than derived from
 * them. A role that grants no permissions at all must still be reportable, so
 * collapsing the two would make an unpermissioned role invisible.
 *
 * `EffectiveRole` is absent from the input union deliberately. It is an output
 * type, and passing one here is rejected at PHP's type boundary rather than
 * answered with `false`.
 *
 * Any and all checks derive from the same effective role set and must not
 * implement independent lifecycle or guard rules.
 *
 * @see docs/decisions/0012-effective-access-resolution.md
 */
interface RoleResolver
{
    public function has(
        Model $subject,
        RoleContract|RoleReference|string|int $role,
    ): bool;

    /**
     * @return Collection<int, EffectiveRole>
     */
    public function effectiveRoles(Model $subject): Collection;
}

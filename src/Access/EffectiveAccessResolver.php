<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Access;

use HangTuah\GateTrail\Guards\SubjectGuardResolver;
use HangTuah\GateTrail\Subjects\SubjectIdentityResolver;
use Illuminate\Database\Eloquent\Model;
use Psr\Clock\ClockInterface;

/**
 * Builds one evaluated authorization state for one subject.
 *
 * This is the shared internal resolution of ADR 0012 topology C:
 * `PermissionResolver` and `RoleResolver` will both project over the snapshot
 * this class returns, so no second lifecycle, guard or ordering rule can
 * appear and drift from the first.
 *
 * **Incomplete by design in this increment.** Only ADR queries 1 and 2 run —
 * effective roles and *direct* permission grants. **Permissions reachable
 * through a role are not present yet**; query 3, its full-path revalidation
 * and its role-ID chunking are Increment 6. Nothing consumer-facing can reach
 * this class in the meantime: `PermissionResolver` and `RoleResolver` remain
 * unbound, so an incomplete permission set cannot answer an authorization
 * check.
 *
 * One evaluation is one identity, one guard and one instant:
 *
 * - the **supplied** model is resolved. The authenticated user is never
 *   substituted, because a check asks about the subject it was handed;
 * - `SubjectIdentityResolver` runs once, so a read and a write cannot disagree
 *   about who the subject is;
 * - `SubjectGuardResolver` runs once, under ADR 0003's precedence. There is no
 *   per-call override;
 * - `ClockInterface::now()` is read once and the identical instant is passed
 *   to both queries and stored in the snapshot, so the two statements agree
 *   about *when* they are asking.
 *
 * Both failures happen before any statement: an unresolvable subject raises
 * `InvalidSubject` and an unresolvable guard raises `InvalidGuard`, neither of
 * which is converted into a denial.
 *
 * Reads are `SELECT` only. No transaction is opened, no lock is taken, no
 * coordination row is touched, and a database failure propagates unchanged
 * rather than being caught and turned into an empty result.
 *
 * Ordering, deduplication, membership indexing and fresh-collection safety all
 * belong to {@see EffectiveAccessSnapshot}; this class prepares its inputs and
 * nothing more.
 *
 * @internal
 *
 * @see docs/decisions/0012-effective-access-resolution.md
 */
final readonly class EffectiveAccessResolver
{
    public function __construct(
        private SubjectIdentityResolver $identities,
        private SubjectGuardResolver $guards,
        private ClockInterface $clock,
        private EffectiveAccessQueryBuilder $queries,
        private EffectiveAccessRowMapper $rows,
    ) {}

    /**
     * Two statements, in a fixed order, from one identity, guard and instant.
     *
     * The order of the three resolutions is fixed so that one subject always
     * fails for the same reason: identity, then guard, then the clock — which
     * is read last because it is the only one that cannot fail.
     */
    public function resolve(Model $subject): EffectiveAccessSnapshot
    {
        $identity = $this->identities->resolve($subject);
        $guardName = $this->guards->resolve($subject);
        $evaluatedAt = $this->clock->now();

        $roles = $this->rows->roles(
            $this->queries
                ->effectiveRolesQuery($identity, $guardName, $evaluatedAt)
                ->get(),
            $guardName,
        );

        // Direct grants only. Role-derived permissions arrive with query 3 in
        // Increment 6, and are deliberately not synthesized from the roles
        // above — a role is not evidence of a permission.
        $permissions = $this->rows->permissions(
            $this->queries
                ->directPermissionsQuery($identity, $guardName, $evaluatedAt)
                ->get(),
            $guardName,
        );

        return EffectiveAccessSnapshot::of(
            $identity,
            $guardName,
            $evaluatedAt,
            $roles,
            $permissions,
        );
    }
}

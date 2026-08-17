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
 * The effective permission set is the specification's formula in full — active
 * direct grants plus permissions reachable through active roles — assembled
 * from at most three query shapes:
 *
 * ```text
 * 1. effective roles          one statement
 * 2. direct permissions       one statement
 * 3. role-derived permissions one statement per 900-role chunk, or none
 * ```
 *
 * Query 3 is **skipped entirely** when query 1 found no roles, and its
 * candidate identifiers are a scan bound and a chunk unit only — never
 * evidence that a role is still valid. Validity comes from the revalidation
 * inside query 3 itself.
 *
 * Nothing consumer-facing can reach this class yet: `PermissionResolver` and
 * `RoleResolver` stay unbound until Increment 7 supplies the thin adapters,
 * and there is no memoization, so every call rebuilds.
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
    /**
     * Candidate role identifiers per query-3 statement.
     *
     * Owned here rather than by the query builder because chunking decides
     * **how many statements are issued**, which is a resolution concern; the
     * builder describes one statement and has no opinion about how many exist.
     *
     * One fixed number on every engine, and not configuration. Oracle's
     * expression-list ceiling is 1000 (`ORA-01795`) and SQL Server's parameter
     * ceiling is 2100 per statement; 900 clears both with room for the
     * subject, guard and instant bindings. A per-engine bound would make the
     * package issue a different number of statements on Oracle than on MySQL,
     * which would make the query budget itself engine-dependent.
     */
    private const CANDIDATE_ROLE_CHUNK = 900;

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

        $permissions = $this->rows->permissions(
            $this->queries
                ->directPermissionsQuery($identity, $guardName, $evaluatedAt)
                ->get(),
            $guardName,
        );

        // One statement per chunk, and none at all when there are no roles.
        // The identity, guard and instant resolved above are reused unchanged
        // across every chunk, so the chunks are one evaluation split for the
        // engine's benefit rather than several evaluations.
        foreach ($this->candidateChunks($roles) as $candidateRoleIds) {
            $permissions = [
                ...$permissions,
                ...$this->rows->roleDerivedPermissions(
                    $this->queries
                        ->roleDerivedPermissionsQuery(
                            $identity,
                            $guardName,
                            $evaluatedAt,
                            $candidateRoleIds,
                        )
                        ->get(),
                    $guardName,
                ),
            ];
        }

        // Merged, not merged-and-tidied. A permission reachable directly and
        // through two roles arrives three times; deduplication by primary key
        // and raw-byte ordering belong to the snapshot, which owns both rules
        // for every caller.
        return EffectiveAccessSnapshot::of(
            $identity,
            $guardName,
            $evaluatedAt,
            $roles,
            $permissions,
        );
    }

    /**
     * The candidate role identifiers, deduplicated and split at the chunk
     * bound.
     *
     * Deduplication here is input preparation, not a second copy of the
     * snapshot's rule: two active assignments of one role are two rows and one
     * candidate, and counting them twice would inflate the `IN` list and could
     * push a subject over a chunk boundary that its actual role count is
     * nowhere near.
     *
     * The identifier's PHP type is preserved. Only the deduplication *key* is
     * type-prefixed, so integer `7` and string `'7'` cannot collapse into one
     * candidate while the values bound into the query stay exactly as the
     * definition model produced them.
     *
     * @param  list<EffectiveRole>  $roles
     * @return list<list<int|string>>
     */
    private function candidateChunks(array $roles): array
    {
        $candidates = [];

        foreach ($roles as $role) {
            $key = is_int($role->id) ? 'int:'.$role->id : 'string:'.$role->id;
            $candidates[$key] = $role->id;
        }

        if ($candidates === []) {
            // Query 3 is skipped entirely. No role was observed, so no
            // role-derived permission can exist, and inventing a statement to
            // discover that would cost one query per resolve for every subject
            // that holds no roles at all.
            return [];
        }

        return array_chunk(array_values($candidates), self::CANDIDATE_ROLE_CHUNK);
    }
}

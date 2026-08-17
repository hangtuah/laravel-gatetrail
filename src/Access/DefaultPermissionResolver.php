<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Access;

use HangTuah\GateTrail\Contracts\PermissionContract;
use HangTuah\GateTrail\Contracts\PermissionResolver;
use HangTuah\GateTrail\PermissionReference;
use HangTuah\GateTrail\Support\ModelRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The public permission resolver, as a projection over one snapshot.
 *
 * It is deliberately thin. Every lifecycle rule, guard rule, query and
 * ordering rule lives in {@see EffectiveAccessResolver} and
 * {@see EffectiveAccessSnapshot}; this class normalizes the caller's question
 * and asks it. Anything more here would be a second algorithm, and a second
 * algorithm is exactly what lets `allows()` and `effectivePermissions()`
 * disagree.
 *
 * Both methods read the same evaluated state, so within one evaluation a
 * `true` from `allows()` and the presence of that permission in
 * `effectivePermissions()` cannot contradict each other.
 *
 * **Normalization happens first — before the subject, the guard and the
 * snapshot.** An input no definition row could carry — an empty name, an
 * overflowing identifier, an unsaved model, a foreign class — denies
 * immediately, so it costs zero queries and is never bound into a statement.
 * On that short-circuit the supplied subject is deliberately never inspected,
 * so the call returns `false` even when that subject would itself have been
 * unresolvable.
 *
 * A valid input then proceeds through subject and guard resolution and builds
 * exactly one snapshot, comparing in memory: **no separate definition lookup
 * is ever issued**, because the snapshot already carries every effective
 * permission's identifier, name and guard.
 *
 * Missing, deactivated and cross-guard permissions are all simply absent from
 * the snapshot, and absence is one answer. A caller cannot tell the three
 * apart, which is what non-disclosure requires. A denial never raises
 * `PermissionNotFound` or `GuardMismatch` — those name a target a caller
 * intended to change, and a check only asks a question.
 *
 * The typed failures apply to the valid-input path: once normalization has
 * passed, an unresolvable subject raises `InvalidSubject`, an invalid
 * authoritative guard raises `InvalidGuard`, and a database failure propagates
 * unchanged.
 *
 * @see docs/decisions/0012-effective-access-resolution.md
 */
final readonly class DefaultPermissionResolver implements PermissionResolver
{
    public function __construct(
        private EffectiveAccessResolver $access,
        private RequestedDefinitionNormalizer $requests,
        private ModelRegistry $models,
    ) {}

    public function allows(
        Model $subject,
        PermissionContract|PermissionReference|string|int $permission,
    ): bool {
        $definitionClass = $this->models->permission();

        $requested = $this->requests->normalize($permission, new $definitionClass);

        if ($requested === null) {
            return false;
        }

        $snapshot = $this->access->resolve($subject);

        if ($requested->name !== null) {
            return $snapshot->hasPermissionNamed($requested->name);
        }

        if ($requested->identifier !== null) {
            return $snapshot->hasPermissionId($requested->identifier);
        }

        // Unreachable: a normalized request always carries one of the two.
        // Kept because the only safe direction to fall is closed.
        return false;
    }

    /**
     * @return Collection<int, EffectivePermission>
     */
    public function effectivePermissions(Model $subject): Collection
    {
        return $this->access->resolve($subject)->permissions();
    }
}

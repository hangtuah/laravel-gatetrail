<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Contracts;

use HangTuah\GateTrail\Access\EffectivePermission;
use HangTuah\GateTrail\PermissionReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The source of truth for every GateTrail permission decision.
 *
 * Both methods are projections over one evaluated state, so a `true` from
 * `allows()` and the presence of that permission in `effectivePermissions()`
 * cannot disagree within a single evaluation. This interface carries no
 * lifecycle rule, guard rule, query or ordering rule of its own; an
 * implementation delegates all of that to the shared internal resolution.
 *
 * `$subject` is a `Model` rather than `mixed` because guard resolution already
 * requires one and assignment rows are polymorphic. Narrowing an untyped
 * framework subject — Laravel hands `Gate::before` a `mixed` value — is a
 * Phase 6 adapter responsibility: the adapter returns `null` for a subject
 * GateTrail cannot manage, so this contract never sees one.
 *
 * `EffectivePermission` is absent from the input union deliberately. It is an
 * output type, and passing one here is rejected at PHP's type boundary rather
 * than answered with `false`.
 *
 * Batch checks must resolve one state and test every parameter against it.
 * Calling `allows()` once per parameter is not an acceptable any/all
 * implementation, because separate calls may observe different states.
 *
 * @see docs/decisions/0012-effective-access-resolution.md
 */
interface PermissionResolver
{
    public function allows(
        Model $subject,
        PermissionContract|PermissionReference|string|int $permission,
    ): bool;

    /**
     * @return Collection<int, EffectivePermission>
     */
    public function effectivePermissions(Model $subject): Collection;
}

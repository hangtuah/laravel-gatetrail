<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Access;

use HangTuah\GateTrail\Contracts\PermissionResolver;

/**
 * One permission a subject effectively holds.
 *
 * It carries the three fields a consumer needs to correlate a result with a
 * definition row, and nothing else. No Eloquent model, connection or lazy
 * relation is retained, so a returned value can neither alias resolved state
 * nor reintroduce an N+1 query from consumer code.
 *
 * This is an **output** type. No resolver method accepts it, so an instance is
 * never proof of authorization; the declared input unions in
 * {@see PermissionResolver} exclude it.
 *
 * Construction is package-controlled: the constructor is private and the named
 * factory below is `@internal`. Consumers receive instances through
 * `effectivePermissions()` and do not construct them. PHP has no
 * package-private visibility, so the factory is technically callable — that
 * does not make it supported consumer API, and its parameters are not
 * compatibility surface.
 *
 * Source attribution — which role or direct grant produced this permission —
 * is deliberately outside v1 and needs its own compatibility decision.
 *
 * @see docs/decisions/0012-effective-access-resolution.md
 */
final readonly class EffectivePermission
{
    private function __construct(
        public int|string $id,
        public string $name,
        public string $guardName,
    ) {}

    /**
     * @internal Package-controlled construction. Not supported consumer API.
     */
    public static function of(
        int|string $id,
        string $name,
        string $guardName,
    ): self {
        return new self($id, $name, $guardName);
    }
}

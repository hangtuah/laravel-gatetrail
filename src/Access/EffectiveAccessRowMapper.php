<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Access;

use HangTuah\GateTrail\Mutations\DefinitionIdentifierNormalizer;
use HangTuah\GateTrail\Support\ModelRegistry;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Turns observed snapshot-query rows into effective-access values.
 *
 * Rows are read **only** through the three fixed aliases the query builder
 * selects. No definition model is hydrated, no relation is touched, and no
 * attribute the builder did not select is consulted, so mapping cannot
 * reintroduce a query the budget does not account for.
 *
 * Raw attributes are read rather than `getAttribute()`, deliberately. It skips
 * casts, accessors and the relation fallback entirely, and it distinguishes a
 * **missing** selected field from a `null` one — a distinction that matters,
 * because both are defects but only the first means the query changed shape.
 *
 * Two rules govern what leaves this class.
 *
 * **Cross-guard rows are excluded, silently.** The SQL `guard_name` predicate
 * is the primary filter, but a case-insensitive collation can widen it, so a
 * returned guard that is not byte-for-byte the resolved guard is dropped. That
 * is an ordinary authorization outcome: no exception, and no disclosure of the
 * other guard, the name or the identifier.
 *
 * **A malformed row is an internal invariant failure.** A missing, null or
 * wrongly typed identifier, name or guard cannot occur if the schema and the
 * query are both correct, so it raises rather than degrading to a `false` that
 * would hide the defect behind what looks like ordinary policy. The raised
 * message carries a fixed field label and nothing else — no identifier, no
 * name, no guard, no row contents, no SQL and no bindings.
 *
 * Identifier normalization is delegated to
 * {@see DefinitionIdentifierNormalizer}, the same component the mutation paths
 * use, so a read and a write cannot disagree about what identifier a
 * definition has. This matters beyond tidiness: a driver may hand back a
 * numeric identifier as a **string** — Oracle routinely does — and an
 * integer-keyed definition must still produce a native `int` so the snapshot's
 * type-stable key does not split one definition into two entries.
 *
 * @internal
 *
 * @see docs/decisions/0012-effective-access-resolution.md
 */
final readonly class EffectiveAccessRowMapper
{
    public function __construct(
        private ModelRegistry $models,
        private DefinitionIdentifierNormalizer $identifiers,
    ) {}

    /**
     * @param  iterable<array-key, Model>  $rows
     * @return list<EffectiveRole>
     */
    public function roles(iterable $rows, string $guardName): array
    {
        $definitionClass = $this->models->role();
        $mapped = [];

        foreach ($this->definitions($rows, new $definitionClass, $guardName) as $definition) {
            $mapped[] = EffectiveRole::of(
                $definition['id'],
                $definition['name'],
                $guardName,
            );
        }

        return $mapped;
    }

    /**
     * @param  iterable<array-key, Model>  $rows
     * @return list<EffectivePermission>
     */
    public function permissions(iterable $rows, string $guardName): array
    {
        $definitionClass = $this->models->permission();
        $mapped = [];

        foreach ($this->definitions($rows, new $definitionClass, $guardName) as $definition) {
            $mapped[] = EffectivePermission::of(
                $definition['id'],
                $definition['name'],
                $guardName,
            );
        }

        return $mapped;
    }

    /**
     * Query 3's rows, which cross two definitions and so carry two guards.
     *
     * **The role guard is verified first**, before the permission identifier
     * or name is even read. A row whose role is not ours is not ours, and
     * discarding it unexamined is what keeps a widened database comparison
     * from turning into a failed authorization call.
     *
     * Both guards must be byte-for-byte the resolved guard. The permission
     * guard alone is not enough: a candidate role observed as `web` can be
     * rewritten to `WEB` between query 1 and query 3 while its permission stays
     * `web`, and a case-insensitive collation would keep granting through it.
     *
     * @param  iterable<array-key, Model>  $rows
     * @return list<EffectivePermission>
     */
    public function roleDerivedPermissions(iterable $rows, string $guardName): array
    {
        $definitionClass = $this->models->permission();
        $definition = new $definitionClass;
        $mapped = [];

        foreach ($rows as $row) {
            $attributes = $row->getAttributes();

            $roleGuard = $this->guardAlias(
                $attributes,
                EffectiveAccessQueryBuilder::ROLE_GUARD_ALIAS,
                'role',
            );

            if ($roleGuard !== $guardName) {
                continue;
            }

            if ($this->guard($attributes) !== $guardName) {
                continue;
            }

            $mapped[] = EffectivePermission::of(
                $this->identifier($attributes, $definition),
                $this->name($attributes),
                $guardName,
            );
        }

        return $mapped;
    }

    /**
     * The shape both subject-assignment mappings share.
     *
     * The guard is checked first because it decides whether this row is ours
     * at all. Once a row is known to belong to another guard it is dropped
     * unexamined: a widened database comparison is not a reason to raise on
     * fields of a row that is being discarded anyway.
     *
     * @param  iterable<array-key, Model>  $rows
     * @return list<array{id: int|string, name: string}>
     */
    private function definitions(
        iterable $rows,
        Model $definition,
        string $guardName,
    ): array {
        $mapped = [];

        foreach ($rows as $row) {
            $attributes = $row->getAttributes();

            if ($this->guard($attributes) !== $guardName) {
                continue;
            }

            $mapped[] = [
                'id' => $this->identifier($attributes, $definition),
                'name' => $this->name($attributes),
            ];
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function guard(array $attributes): string
    {
        return $this->guardAlias(
            $attributes,
            EffectiveAccessQueryBuilder::GUARD_ALIAS,
            'definition',
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function guardAlias(
        array $attributes,
        string $alias,
        string $label,
    ): string {
        $guard = $attributes[$alias] ?? null;

        if (! is_string($guard)) {
            throw new LogicException(sprintf(
                'GateTrail observed an effective access row whose %s guard is missing or not a string.',
                $label,
            ));
        }

        return $guard;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function name(array $attributes): string
    {
        $name = $attributes[EffectiveAccessQueryBuilder::NAME_ALIAS] ?? null;

        if (! is_string($name)) {
            throw new LogicException(
                'GateTrail observed an effective access row whose definition name is missing or not a string.',
            );
        }

        return $name;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function identifier(array $attributes, Model $definition): int|string
    {
        $identifier = $attributes[EffectiveAccessQueryBuilder::ID_ALIAS] ?? null;

        if (! is_int($identifier) && ! is_string($identifier)) {
            throw new LogicException(
                'GateTrail observed an effective access row whose definition identifier is missing or not an integer or string.',
            );
        }

        $normalized = $this->identifiers->normalize($definition, $identifier);

        if ($normalized === null) {
            // A persisted identifier that cannot be expressed in the
            // configured key type is a defect, not a denial. Casting it would
            // saturate an out-of-range value onto a real definition.
            throw new LogicException(
                'GateTrail observed an effective access row whose definition identifier does not match the configured key type.',
            );
        }

        return $normalized;
    }
}

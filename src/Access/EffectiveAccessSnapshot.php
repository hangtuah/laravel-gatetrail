<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Access;

use DateTimeImmutable;
use HangTuah\GateTrail\Subjects\SubjectIdentity;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * One evaluated authorization state, frozen.
 *
 * A snapshot answers for exactly one canonical subject identity, in one
 * resolved guard, at one evaluation instant. `PermissionResolver` and
 * `RoleResolver` are thin projections over it, so a `true` from a boolean
 * check and the presence of that entry in the corresponding collection are
 * the same fact read twice rather than two algorithms that can drift.
 *
 * It holds **resolved values only**. No Eloquent model, connection, query
 * builder, relation, mutable `Collection`, query counter, cache store,
 * container, resolver or closure is retained, so a snapshot cannot execute a
 * statement, cannot lazily load anything, and cannot be changed after the
 * instant it describes. Section 8 of the ADR is explicit that a self-reported
 * query counter would report what the algorithm believed it did; the counting
 * belongs on the connection, never here.
 *
 * The evaluation instant is **supplied**, never read from the system clock and
 * never renormalized. One call resolves one instant, and the snapshot records
 * the instant it was handed.
 *
 * Both lists are canonical on construction: unique by definition primary key,
 * then ordered ascending by name using raw-byte `strcmp()`. Neither rule is
 * delegated to the database, because `ORDER BY` and key collation differ
 * across MySQL, PostgreSQL, Oracle and SQL Server.
 *
 * This class is internal. It is not public API, not part of the compatibility
 * surface, and its method names and parameters may change in any release.
 *
 * @internal
 *
 * @see docs/decisions/0012-effective-access-resolution.md
 */
final readonly class EffectiveAccessSnapshot
{
    /**
     * @param  list<EffectiveRole>  $roles
     * @param  list<EffectivePermission>  $permissions
     * @param  array<string, true>  $roleIdKeys
     * @param  array<string, true>  $roleNameKeys
     * @param  array<string, true>  $permissionIdKeys
     * @param  array<string, true>  $permissionNameKeys
     */
    private function __construct(
        private SubjectIdentity $subject,
        private string $guardName,
        private DateTimeImmutable $evaluatedAt,
        private array $roles,
        private array $permissions,
        private array $roleIdKeys,
        private array $roleNameKeys,
        private array $permissionIdKeys,
        private array $permissionNameKeys,
    ) {}

    /**
     * Build one snapshot from already-resolved in-memory values.
     *
     * Nothing here queries, filters by lifecycle, resolves a guard or reads a
     * clock. The caller — the future `EffectiveAccessResolver` — owns all of
     * that and hands over the finished values.
     *
     * `$roles` and `$permissions` may arrive in any order and may repeat: a
     * permission reachable through two roles and a direct grant is expected to
     * appear three times. Duplicates collapse by primary key, first occurrence
     * wins, and `usort()` is stable, so one input list always produces one
     * output list.
     *
     * @internal Package-controlled construction. Not supported consumer API.
     *
     * @param  iterable<array-key, EffectiveRole>  $roles
     * @param  iterable<array-key, EffectivePermission>  $permissions
     */
    public static function of(
        SubjectIdentity $subject,
        string $guardName,
        DateTimeImmutable $evaluatedAt,
        iterable $roles,
        iterable $permissions,
    ): self {
        $canonicalRoles = self::canonicalize($roles, EffectiveRole::class);
        $canonicalPermissions = self::canonicalize(
            $permissions,
            EffectivePermission::class,
        );

        return new self(
            $subject,
            $guardName,
            $evaluatedAt,
            $canonicalRoles,
            $canonicalPermissions,
            self::identifierKeys($canonicalRoles),
            self::nameKeys($canonicalRoles),
            self::identifierKeys($canonicalPermissions),
            self::nameKeys($canonicalPermissions),
        );
    }

    public function subject(): SubjectIdentity
    {
        return $this->subject;
    }

    public function guardName(): string
    {
        return $this->guardName;
    }

    public function evaluatedAt(): DateTimeImmutable
    {
        return $this->evaluatedAt;
    }

    /**
     * A fresh `Collection` per call, so a consumer may mutate what it is given
     * without changing this snapshot, a later projection or a membership test.
     *
     * @return Collection<int, EffectiveRole>
     */
    public function roles(): Collection
    {
        return new Collection($this->roles);
    }

    /**
     * @return Collection<int, EffectivePermission>
     */
    public function permissions(): Collection
    {
        return new Collection($this->permissions);
    }

    /**
     * Exact in-memory membership. `7` and `'7'` are different identifiers and
     * do not match each other, matching the `===` semantics a loose lookup
     * would silently lose.
     */
    public function hasRoleId(int|string $id): bool
    {
        return isset($this->roleIdKeys[self::identifierKey($id)]);
    }

    /**
     * Byte-for-byte and case-sensitive. Normalizing a requested name belongs
     * to the resolver adapter, not to the evaluated state.
     */
    public function hasRoleNamed(string $name): bool
    {
        return isset($this->roleNameKeys[self::nameKey($name)]);
    }

    public function hasPermissionId(int|string $id): bool
    {
        return isset($this->permissionIdKeys[self::identifierKey($id)]);
    }

    public function hasPermissionNamed(string $name): bool
    {
        return isset($this->permissionNameKeys[self::nameKey($name)]);
    }

    /**
     * Deduplicate by primary key, then order ascending by raw name bytes.
     *
     * Deduplication is keyed on the identifier rather than the name because
     * the identifier is the identity the database enforces. Ordering uses
     * `strcmp()` rather than `strcoll()`, `strcasecmp()`, `strnatcmp()` or
     * SQL, so the order is one documented byte order on every engine.
     *
     * @template TValue of EffectiveRole|EffectivePermission
     *
     * @param  iterable<array-key, mixed>  $values
     * @param  class-string<TValue>  $type
     * @return list<TValue>
     */
    private static function canonicalize(iterable $values, string $type): array
    {
        $unique = [];

        foreach ($values as $value) {
            if (! $value instanceof $type) {
                // Rejected here rather than in the comparator, where the
                // failure would surface as a TypeError inside usort() with no
                // indication of which list was malformed. The message carries
                // a type label only, never an identifier or a name.
                throw new InvalidArgumentException(sprintf(
                    'An effective access snapshot accepts only [%s] values, [%s] given.',
                    $type,
                    get_debug_type($value),
                ));
            }

            $key = self::identifierKey($value->id);

            if (! array_key_exists($key, $unique)) {
                $unique[$key] = $value;
            }
        }

        $ordered = array_values($unique);

        usort(
            $ordered,
            static fn (
                EffectiveRole|EffectivePermission $left,
                EffectiveRole|EffectivePermission $right,
            ): int => strcmp($left->name, $right->name),
        );

        return $ordered;
    }

    /**
     * @param  list<EffectiveRole|EffectivePermission>  $values
     * @return array<string, true>
     */
    private static function identifierKeys(array $values): array
    {
        $keys = [];

        foreach ($values as $value) {
            $keys[self::identifierKey($value->id)] = true;
        }

        return $keys;
    }

    /**
     * @param  list<EffectiveRole|EffectivePermission>  $values
     * @return array<string, true>
     */
    private static function nameKeys(array $values): array
    {
        $keys = [];

        foreach ($values as $value) {
            $keys[self::nameKey($value->name)] = true;
        }

        return $keys;
    }

    /**
     * PHP silently rewrites a decimal-string array key to an integer, so a
     * bare `$index[$id]` would make identifier `7` and identifier `'7'` the
     * same bucket. The type prefix keeps them apart, and keeps a name bucket
     * from ever colliding with an identifier bucket.
     */
    private static function identifierKey(int|string $id): string
    {
        return is_int($id) ? 'int:'.$id : 'string:'.$id;
    }

    private static function nameKey(string $name): string
    {
        return 'name:'.$name;
    }
}

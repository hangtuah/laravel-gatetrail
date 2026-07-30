<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Audit;

use HangTuah\GateTrail\Contracts\RoleContract;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * @internal
 */
final readonly class RoleSnapshotBuilder
{
    public function __construct(
        private CanonicalAuditJson $json,
    ) {}

    public function build(Model&RoleContract $role): string
    {
        $persisted = $this->reload($role);

        return $this->json->object([
            'id' => $this->json->identifier(
                $persisted->getKey(),
                'id',
            ),
            'name' => $this->requiredString($persisted, 'name'),
            'guard_name' => $this->requiredString(
                $persisted,
                'guard_name',
            ),
            'description' => $this->nullableString(
                $persisted,
                'description',
            ),
            'deleted_at' => $this->nullableTimestamp(
                $persisted,
                'deleted_at',
            ),
        ]);
    }

    /**
     * Reloads the exact persisted row behind one role definition.
     *
     * The query is bound to the supplied model, so it keeps the configured
     * class, connection, and table, and it is pinned to that model's primary
     * key. Every global scope is dropped rather than only `SoftDeletingScope`:
     * a canonical audit snapshot must describe the row as stored, and a
     * consumer scope that hides the freshly mutated row would otherwise fail
     * an already-successful mutation instead of recording it. Dropping scopes
     * cannot widen the result, because the primary key still identifies at
     * most one row.
     */
    private function reload(Model&RoleContract $role): Model
    {
        $key = $role->getKey();

        if (
            ! $role->exists
            || (! is_int($key) && ! is_string($key))
        ) {
            throw new InvalidArgumentException(
                'GateTrail cannot snapshot an unpersisted role definition.',
            );
        }

        $persisted = $role->newQueryWithoutScopes()
            ->whereKey($key)
            ->first();

        if (! $persisted instanceof Model) {
            throw new InvalidArgumentException(
                'GateTrail cannot snapshot an unpersisted role definition.',
            );
        }

        return $persisted;
    }

    private function requiredString(
        Model $role,
        string $field,
    ): string {
        $value = $role->getAttribute($field);

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException(
                "GateTrail audit field [$field] must be a non-empty string.",
            );
        }

        return $value;
    }

    private function nullableString(
        Model $role,
        string $field,
    ): ?string {
        $value = $role->getAttribute($field);

        if ($value === null || is_string($value)) {
            return $value;
        }

        throw new InvalidArgumentException(
            "GateTrail audit field [$field] must be a string or null.",
        );
    }

    private function nullableTimestamp(
        Model $role,
        string $field,
    ): ?string {
        $value = $role->getAttribute($field);

        return $value === null
            ? null
            : $this->json->timestamp($value, $field);
    }
}

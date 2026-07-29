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
        if (
            ! $role->exists
            || ! ($persisted = $role->fresh()) instanceof Model
        ) {
            throw new InvalidArgumentException(
                'GateTrail cannot snapshot an unpersisted role definition.',
            );
        }

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

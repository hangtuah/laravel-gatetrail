<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Audit;

use HangTuah\GateTrail\Contracts\RoleAssignmentContract;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * @internal
 */
final readonly class RoleAssignmentSnapshotBuilder
{
    public function __construct(
        private CanonicalAuditJson $json,
    ) {}

    public function build(
        Model&RoleAssignmentContract $assignment,
    ): string {
        if (! $assignment->exists) {
            throw new InvalidArgumentException(
                'GateTrail cannot snapshot an unpersisted role assignment.',
            );
        }

        $persisted = $assignment->fresh();

        if (! $persisted instanceof Model) {
            throw new InvalidArgumentException(
                'GateTrail cannot reload the persisted role assignment.',
            );
        }

        return $this->json->object([
            'id' => $this->json->identifier(
                $persisted->getKey(),
                'id',
            ),
            'assigned_at' => $this->json->timestamp(
                $persisted->getAttribute('assigned_at'),
                'assigned_at',
            ),
            'assigned_by_type' => $this->requiredString(
                $persisted,
                'assigned_by_type',
            ),
            'assigned_by_id' => $this->requiredString(
                $persisted,
                'assigned_by_id',
            ),
            'assignment_reason' => $this->nullableString(
                $persisted,
                'assignment_reason',
            ),
            'starts_at' => $this->nullableTimestamp(
                $persisted,
                'starts_at',
            ),
            'expires_at' => $this->nullableTimestamp(
                $persisted,
                'expires_at',
            ),
            'revoked_at' => $this->nullableTimestamp(
                $persisted,
                'revoked_at',
            ),
            'revoked_by_type' => $this->nullableString(
                $persisted,
                'revoked_by_type',
            ),
            'revoked_by_id' => $this->nullableString(
                $persisted,
                'revoked_by_id',
            ),
            'revocation_reason' => $this->nullableString(
                $persisted,
                'revocation_reason',
            ),
            'subject_type' => $this->requiredString(
                $persisted,
                'subject_type',
            ),
            'subject_id' => $this->json->identifier(
                $persisted->getAttribute('subject_id'),
                'subject_id',
            ),
            'role_id' => $this->json->identifier(
                $persisted->getAttribute('role_id'),
                'role_id',
            ),
        ]);
    }

    private function requiredString(
        Model $assignment,
        string $field,
    ): string {
        $value = $assignment->getAttribute($field);

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException(
                sprintf(
                    'GateTrail audit field [%s] must be a non-empty string.',
                    $field,
                ),
            );
        }

        return $value;
    }

    private function nullableString(
        Model $assignment,
        string $field,
    ): ?string {
        $value = $assignment->getAttribute($field);

        if ($value === null || is_string($value)) {
            return $value;
        }

        throw new InvalidArgumentException(
            sprintf(
                'GateTrail audit field [%s] must be a string or null.',
                $field,
            ),
        );
    }

    private function nullableTimestamp(
        Model $assignment,
        string $field,
    ): ?string {
        $value = $assignment->getAttribute($field);

        return $value === null
            ? null
            : $this->json->timestamp($value, $field);
    }
}

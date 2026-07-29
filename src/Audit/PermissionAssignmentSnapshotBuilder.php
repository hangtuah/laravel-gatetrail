<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Audit;

use HangTuah\GateTrail\Contracts\PermissionAssignmentContract;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** @internal */
final readonly class PermissionAssignmentSnapshotBuilder
{
    public function __construct(
        private CanonicalAuditJson $json,
        private SensitiveAuditValueRedactor $redactor,
    ) {}

    public function build(
        Model&PermissionAssignmentContract $assignment,
        string $snapshot = 'after',
    ): string {
        if (! in_array($snapshot, ['before', 'after'], true)) {
            throw new InvalidArgumentException(
                'GateTrail assignment snapshot side must be before or after.',
            );
        }
        if (! $assignment->exists || ! ($persisted = $assignment->fresh()) instanceof Model) {
            throw new InvalidArgumentException(
                'GateTrail cannot snapshot an unpersisted permission assignment.',
            );
        }

        return $this->json->object([
            'id' => $this->json->identifier($persisted->getKey(), 'id'),
            'assigned_at' => $this->json->timestamp($persisted->getAttribute('assigned_at'), 'assigned_at'),
            'assigned_by_type' => $this->requiredString($persisted, 'assigned_by_type'),
            'assigned_by_id' => $this->requiredString($persisted, 'assigned_by_id'),
            'assignment_reason' => $this->redactor->assignmentReason(
                $this->nullableString($persisted, 'assignment_reason'),
                $snapshot,
            ),
            'starts_at' => $this->nullableTimestamp($persisted, 'starts_at'),
            'expires_at' => $this->nullableTimestamp($persisted, 'expires_at'),
            'revoked_at' => $this->nullableTimestamp($persisted, 'revoked_at'),
            'revoked_by_type' => $this->nullableString($persisted, 'revoked_by_type'),
            'revoked_by_id' => $this->nullableString($persisted, 'revoked_by_id'),
            'revocation_reason' => $this->redactor->revocationReason(
                $this->nullableString($persisted, 'revocation_reason'),
                $snapshot,
            ),
            'subject_type' => $this->requiredString($persisted, 'subject_type'),
            'subject_id' => $this->json->identifier($persisted->getAttribute('subject_id'), 'subject_id'),
            'permission_id' => $this->json->identifier($persisted->getAttribute('permission_id'), 'permission_id'),
        ]);
    }

    private function requiredString(Model $model, string $field): string
    {
        $value = $model->getAttribute($field);
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("GateTrail audit field [$field] must be a non-empty string.");
        }

        return $value;
    }

    private function nullableString(Model $model, string $field): ?string
    {
        $value = $model->getAttribute($field);
        if ($value === null || is_string($value)) {
            return $value;
        }
        throw new InvalidArgumentException("GateTrail audit field [$field] must be a string or null.");
    }

    private function nullableTimestamp(Model $model, string $field): ?string
    {
        $value = $model->getAttribute($field);

        return $value === null ? null : $this->json->timestamp($value, $field);
    }
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations\Handlers;

use DateTimeInterface;
use HangTuah\GateTrail\Audit\AuditContextBuilder;
use HangTuah\GateTrail\Audit\MetadataNormalizer;
use HangTuah\GateTrail\Audit\PendingAuditRecord;
use HangTuah\GateTrail\Audit\RolePermissionSnapshotBuilder;
use HangTuah\GateTrail\Audit\SensitiveAuditValueRedactor;
use HangTuah\GateTrail\Contracts\RolePermissionContract;
use HangTuah\GateTrail\Exceptions\AssignmentNotActive;
use HangTuah\GateTrail\Models\RolePermission;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\MutationResult;
use HangTuah\GateTrail\Mutations\ActorIdentityResolver;
use HangTuah\GateTrail\Mutations\AuditedMutationTransaction;
use HangTuah\GateTrail\Mutations\Commands\RevokePermissionFromRoleCommand;
use HangTuah\GateTrail\Mutations\MutationName;
use HangTuah\GateTrail\Mutations\MutationReasonValidator;
use HangTuah\GateTrail\Mutations\PreparedMutation;
use HangTuah\GateTrail\Mutations\RolePermissionInputResolver;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @internal
 */
final readonly class RevokePermissionFromRoleHandler
{
    public function __construct(
        private MetadataNormalizer $metadata,
        private ActorIdentityResolver $actors,
        private MutationReasonValidator $reasons,
        private AuditedMutationTransaction $mutations,
        private RolePermissionInputResolver $assignments,
        private RolePermissionSnapshotBuilder $snapshots,
        private AuditContextBuilder $contexts,
        private SensitiveAuditValueRedactor $redactor,
    ) {}

    /**
     * @return MutationResult<RolePermission&RolePermissionContract>
     */
    public function handle(
        RevokePermissionFromRoleCommand $command,
    ): MutationResult {
        $metadata = $this->metadata->normalize($command->metadata);
        $actor = $this->actors->resolve($command->actor);
        $reason = $this->reasons->assignment($command->reason);

        return $this->mutations->run(
            function () use (
                $command,
                $metadata,
                $actor,
                $reason,
            ): PreparedMutation {
                $assignment = $this->assignments->resolve(
                    $command->assignment,
                );
                $assignmentId = $this->identifier(
                    $assignment,
                    'role-permission assignment',
                );

                $this->assertRevocable(
                    $assignment,
                    $assignmentId,
                    $command->requestedAt,
                );

                $before = $this->snapshots->build(
                    $assignment,
                    'before',
                );
                $assignment->forceFill([
                    'revoked_at' => $command->requestedAt,
                    'revoked_by_type' => $actor->type,
                    'revoked_by_id' => $actor->id,
                    'revocation_reason' => $reason,
                    'updated_at' => $command->requestedAt,
                ]);
                $assignment->save();
                $role = $assignment->role()->first();
                $permission = $assignment->permission()->first();

                if (! $role instanceof Model) {
                    throw new LogicException(
                        'GateTrail role-permission assignment has no related role.',
                    );
                }

                if (! $permission instanceof Model) {
                    throw new LogicException(
                        'GateTrail role-permission assignment has no related permission.',
                    );
                }

                $audit = new PendingAuditRecord(
                    event: MutationName::PERMISSION_REVOKED_FROM_ROLE,
                    actor: $actor,
                    subjectType: null,
                    subjectId: null,
                    targetType: 'role_permission',
                    targetId: $assignmentId,
                    before: $before,
                    after: $this->snapshots->build(
                        $assignment,
                        'after',
                    ),
                    reason: $this->redactor->auditReason($reason),
                    context: $this->contexts->rolePermissionAssignment(
                        $this->requiredString($role, 'guard_name'),
                        $this->requiredString($role, 'name'),
                        $this->requiredString($permission, 'name'),
                        $metadata,
                    ),
                    ipAddress: null,
                    userAgent: null,
                    requestId: null,
                    createdAt: $command->requestedAt,
                );

                return new PreparedMutation(
                    $assignment,
                    MutationOutcome::Revoked,
                    $audit,
                );
            },
        );
    }

    private function assertRevocable(
        RolePermission $assignment,
        int|string $assignmentId,
        DateTimeInterface $requestedAt,
    ): void {
        if ($assignment->getAttribute('revoked_at') !== null) {
            throw AssignmentNotActive::forRolePermission($assignmentId);
        }

        $expiresAt = $assignment->getAttribute('expires_at');

        if (
            $expiresAt !== null
            && (
                ! $expiresAt instanceof DateTimeInterface
                || $expiresAt <= $requestedAt
            )
        ) {
            throw AssignmentNotActive::forRolePermission($assignmentId);
        }
    }

    private function identifier(
        Model $model,
        string $label,
    ): int|string {
        $id = $model->getKey();

        if (! is_int($id) && ! is_string($id)) {
            throw new LogicException(
                sprintf(
                    'GateTrail %s has no persisted identifier.',
                    $label,
                ),
            );
        }

        return $id;
    }

    private function requiredString(
        Model $model,
        string $attribute,
    ): string {
        $value = $model->getAttribute($attribute);

        if (! is_string($value) || trim($value) === '') {
            throw new LogicException(
                sprintf(
                    'GateTrail attribute [%s] must be a non-empty string.',
                    $attribute,
                ),
            );
        }

        return trim($value);
    }
}

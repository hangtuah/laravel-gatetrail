<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations\Handlers;

use DateTimeInterface;
use HangTuah\GateTrail\Audit\AuditContextBuilder;
use HangTuah\GateTrail\Audit\MetadataNormalizer;
use HangTuah\GateTrail\Audit\PendingAuditRecord;
use HangTuah\GateTrail\Audit\RoleAssignmentSnapshotBuilder;
use HangTuah\GateTrail\Audit\SensitiveAuditValueRedactor;
use HangTuah\GateTrail\Contracts\RoleAssignmentContract;
use HangTuah\GateTrail\Exceptions\AssignmentNotActive;
use HangTuah\GateTrail\Models\RoleAssignment;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\MutationResult;
use HangTuah\GateTrail\Mutations\ActorIdentityResolver;
use HangTuah\GateTrail\Mutations\AuditedMutationTransaction;
use HangTuah\GateTrail\Mutations\Commands\RevokeRoleCommand;
use HangTuah\GateTrail\Mutations\MutationReasonValidator;
use HangTuah\GateTrail\Mutations\PreparedMutation;
use HangTuah\GateTrail\Mutations\RoleAssignmentInputResolver;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @internal
 */
final readonly class RevokeRoleHandler
{
    public function __construct(
        private MetadataNormalizer $metadata,
        private ActorIdentityResolver $actors,
        private MutationReasonValidator $reasons,
        private AuditedMutationTransaction $mutations,
        private RoleAssignmentInputResolver $assignments,
        private RoleAssignmentSnapshotBuilder $snapshots,
        private AuditContextBuilder $contexts,
        private SensitiveAuditValueRedactor $redactor,
    ) {}

    /**
     * @return MutationResult<RoleAssignment&RoleAssignmentContract>
     */
    public function handle(RevokeRoleCommand $command): MutationResult
    {
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
                    'role assignment',
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

                if (! $role instanceof Model) {
                    throw new LogicException(
                        'GateTrail role assignment has no related role.',
                    );
                }

                $audit = new PendingAuditRecord(
                    event: 'role.revoked',
                    actor: $actor,
                    subjectType: $this->requiredString(
                        $assignment,
                        'subject_type',
                    ),
                    subjectId: $this->identifier(
                        $assignment,
                        'subject',
                        'subject_id',
                    ),
                    targetType: 'role_assignment',
                    targetId: $assignmentId,
                    before: $before,
                    after: $this->snapshots->build(
                        $assignment,
                        'after',
                    ),
                    reason: $this->redactor->auditReason($reason),
                    context: $this->contexts->roleAssignment(
                        $this->requiredString($role, 'guard_name'),
                        $this->requiredString($role, 'name'),
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
        RoleAssignment $assignment,
        int|string $assignmentId,
        DateTimeInterface $requestedAt,
    ): void {
        if ($assignment->getAttribute('revoked_at') !== null) {
            throw AssignmentNotActive::forRole($assignmentId);
        }

        $expiresAt = $assignment->getAttribute('expires_at');

        if (
            $expiresAt !== null
            && (
                ! $expiresAt instanceof DateTimeInterface
                || $expiresAt <= $requestedAt
            )
        ) {
            throw AssignmentNotActive::forRole($assignmentId);
        }
    }

    private function identifier(
        Model $model,
        string $label,
        ?string $attribute = null,
    ): int|string {
        $id = $attribute === null
            ? $model->getKey()
            : $model->getAttribute($attribute);

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

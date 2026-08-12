<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations\Handlers;

use HangTuah\GateTrail\Assignments\AssignmentConflictQuery;
use HangTuah\GateTrail\Assignments\AssignmentDecisionMaker;
use HangTuah\GateTrail\Assignments\AssignmentDecisionOutcome;
use HangTuah\GateTrail\Assignments\AssignmentLockStore;
use HangTuah\GateTrail\Assignments\AssignmentScope;
use HangTuah\GateTrail\Assignments\EffectiveAssignmentPeriod;
use HangTuah\GateTrail\Audit\AuditContextBuilder;
use HangTuah\GateTrail\Audit\MetadataNormalizer;
use HangTuah\GateTrail\Audit\PendingAuditRecord;
use HangTuah\GateTrail\Audit\RoleAssignmentSnapshotBuilder;
use HangTuah\GateTrail\Audit\SensitiveAuditValueRedactor;
use HangTuah\GateTrail\Contracts\RoleAssignmentContract;
use HangTuah\GateTrail\Exceptions\DuplicateActiveAssignment;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\MutationResult;
use HangTuah\GateTrail\Mutations\ActorIdentity;
use HangTuah\GateTrail\Mutations\ActorIdentityResolver;
use HangTuah\GateTrail\Mutations\AuditedMutationTransaction;
use HangTuah\GateTrail\Mutations\Commands\AssignRoleCommand;
use HangTuah\GateTrail\Mutations\MutationName;
use HangTuah\GateTrail\Mutations\MutationReasonValidator;
use HangTuah\GateTrail\Mutations\PreparedMutation;
use HangTuah\GateTrail\Mutations\RoleInputResolver;
use HangTuah\GateTrail\Support\ModelRegistry;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;

/**
 * @internal
 */
final readonly class AssignRoleHandler
{
    public function __construct(
        private MetadataNormalizer $metadata,
        private ActorIdentityResolver $actors,
        private MutationReasonValidator $reasons,
        private AuditedMutationTransaction $mutations,
        private RoleInputResolver $roles,
        private AssignmentLockStore $locks,
        private AssignmentConflictQuery $conflicts,
        private AssignmentDecisionMaker $decisions,
        private ModelRegistry $models,
        private RoleAssignmentSnapshotBuilder $snapshots,
        private AuditContextBuilder $contexts,
        private SensitiveAuditValueRedactor $redactor,
    ) {}

    /**
     * @return MutationResult<Model&RoleAssignmentContract>
     */
    public function handle(AssignRoleCommand $command): MutationResult
    {
        $metadata = $this->metadata->normalize($command->metadata);
        $actor = $this->actors->resolve($command->actor);
        $reason = $this->reasons->assignment($command->reason);
        [$subjectType, $subjectId] = $this->subjectIdentity(
            $command->subject,
        );
        $candidate = EffectiveAssignmentPeriod::fromAssignment(
            $command->period,
            $command->requestedAt,
        );

        return $this->mutations->runCoordinated(
            MutationName::ROLE_ASSIGNED,
            function () use (
                $command,
                $metadata,
                $actor,
                $reason,
                $subjectType,
                $subjectId,
                $candidate,
            ): PreparedMutation {
                $role = $this->roles->resolve(
                    $command->subject,
                    $command->role,
                );
                $roleId = $this->modelIdentifier($role, 'role');
                $scope = AssignmentScope::forRole(
                    $subjectType,
                    $subjectId,
                    $roleId,
                );

                $this->locks->acquire($scope, $command->requestedAt);

                $decision = $this->decisions->decide(
                    $command->period,
                    $candidate,
                    $this->conflicts->find($scope, $candidate),
                );

                if (
                    $decision->outcome
                    === AssignmentDecisionOutcome::Unchanged
                ) {
                    if (
                        ! $decision->existingAssignment instanceof Model
                        || ! $decision->existingAssignment instanceof RoleAssignmentContract
                    ) {
                        throw new LogicException(
                            'GateTrail unchanged assignment decision has no existing model.',
                        );
                    }

                    return new PreparedMutation(
                        $decision->existingAssignment,
                        MutationOutcome::Unchanged,
                        null,
                    );
                }

                if (
                    $decision->outcome
                    === AssignmentDecisionOutcome::Conflict
                ) {
                    throw DuplicateActiveAssignment::forScope($scope);
                }

                $assignment = $this->createAssignment(
                    $subjectType,
                    $subjectId,
                    $roleId,
                    $actor,
                    $reason,
                    $command,
                );
                $audit = new PendingAuditRecord(
                    event: MutationName::ROLE_ASSIGNED,
                    actor: $actor,
                    subjectType: $subjectType,
                    subjectId: $subjectId,
                    targetType: 'role_assignment',
                    targetId: $this->modelIdentifier(
                        $assignment,
                        'role assignment',
                    ),
                    before: null,
                    after: $this->snapshots->build($assignment),
                    reason: $this->redactor->auditReason($reason),
                    context: $this->contexts->roleAssignment(
                        $this->requiredString(
                            $role,
                            'guard_name',
                        ),
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
                    MutationOutcome::Created,
                    $audit,
                );
            },
        );
    }

    /**
     * @return array{string, int|string}
     */
    private function subjectIdentity(Model $subject): array
    {
        if (! $subject->exists) {
            throw new InvalidArgumentException(
                'GateTrail role assignment subject must be persisted.',
            );
        }

        $type = $subject->getMorphClass();
        $id = $subject->getKey();

        if (
            $type === ''
            || (! is_int($id) && ! is_string($id))
        ) {
            throw new InvalidArgumentException(
                'GateTrail role assignment subject identity is invalid.',
            );
        }

        return [$type, $id];
    }

    private function modelIdentifier(
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

    private function createAssignment(
        string $subjectType,
        int|string $subjectId,
        int|string $roleId,
        ActorIdentity $actor,
        ?string $reason,
        AssignRoleCommand $command,
    ): Model&RoleAssignmentContract {
        $class = $this->models->roleAssignment();
        $assignment = new $class;
        $assignment->forceFill([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'role_id' => $roleId,
            'assigned_at' => $command->requestedAt,
            'assigned_by_type' => $actor->type,
            'assigned_by_id' => $actor->id,
            'assignment_reason' => $reason,
            'starts_at' => $command->period->startsAt(),
            'expires_at' => $command->period->expiresAt(),
            'revoked_at' => null,
            'revoked_by_type' => null,
            'revoked_by_id' => null,
            'revocation_reason' => null,
            'created_at' => $command->requestedAt,
            'updated_at' => $command->requestedAt,
        ]);
        $assignment->save();

        return $assignment;
    }

    private function requiredString(
        Model $model,
        string $attribute,
    ): string {
        $value = $model->getAttribute($attribute);

        if (! is_string($value) || trim($value) === '') {
            throw new LogicException(
                sprintf(
                    'GateTrail role attribute [%s] must be a non-empty string.',
                    $attribute,
                ),
            );
        }

        return trim($value);
    }
}

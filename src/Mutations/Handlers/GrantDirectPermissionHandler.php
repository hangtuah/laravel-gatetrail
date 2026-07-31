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
use HangTuah\GateTrail\Audit\PermissionAssignmentSnapshotBuilder;
use HangTuah\GateTrail\Audit\SensitiveAuditValueRedactor;
use HangTuah\GateTrail\Contracts\PermissionAssignmentContract;
use HangTuah\GateTrail\Exceptions\DuplicateActiveAssignment;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\MutationResult;
use HangTuah\GateTrail\Mutations\ActorIdentity;
use HangTuah\GateTrail\Mutations\ActorIdentityResolver;
use HangTuah\GateTrail\Mutations\AuditedMutationTransaction;
use HangTuah\GateTrail\Mutations\Commands\GrantDirectPermissionCommand;
use HangTuah\GateTrail\Mutations\MutationReasonValidator;
use HangTuah\GateTrail\Mutations\PermissionInputResolver;
use HangTuah\GateTrail\Mutations\PreparedMutation;
use HangTuah\GateTrail\Support\ModelRegistry;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;

/** @internal */
final readonly class GrantDirectPermissionHandler
{
    public function __construct(
        private MetadataNormalizer $metadata,
        private ActorIdentityResolver $actors,
        private MutationReasonValidator $reasons,
        private AuditedMutationTransaction $mutations,
        private PermissionInputResolver $permissions,
        private AssignmentLockStore $locks,
        private AssignmentConflictQuery $conflicts,
        private AssignmentDecisionMaker $decisions,
        private ModelRegistry $models,
        private PermissionAssignmentSnapshotBuilder $snapshots,
        private AuditContextBuilder $contexts,
        private SensitiveAuditValueRedactor $redactor,
    ) {}

    /** @return MutationResult<Model&PermissionAssignmentContract> */
    public function handle(GrantDirectPermissionCommand $command): MutationResult
    {

        $metadata = $this->metadata->normalize($command->metadata);
        $actor = $this->actors->resolve($command->actor);
        $reason = $this->reasons->assignment($command->reason);
        [$subjectType, $subjectId] = $this->subjectIdentity($command->subject);
        $candidate = EffectiveAssignmentPeriod::fromAssignment($command->period, $command->requestedAt);

        return $this->mutations->run(function () use (
            $command, $metadata, $actor, $reason, $subjectType, $subjectId, $candidate,
        ): PreparedMutation {
            $permission = $this->permissions->resolve($command->subject, $command->permission);
            $permissionId = $this->identifier($permission, 'permission');
            $scope = AssignmentScope::forPermission($subjectType, $subjectId, $permissionId);
            $this->locks->acquire($scope, $command->requestedAt);
            $decision = $this->decisions->decide(
                $command->period,
                $candidate,
                $this->conflicts->find($scope, $candidate),
            );

            if ($decision->outcome === AssignmentDecisionOutcome::Unchanged) {
                if (! $decision->existingAssignment instanceof Model
                    || ! $decision->existingAssignment instanceof PermissionAssignmentContract) {
                    throw new LogicException('GateTrail unchanged permission assignment has no existing model.');
                }

                return new PreparedMutation($decision->existingAssignment, MutationOutcome::Unchanged, null);
            }
            if ($decision->outcome === AssignmentDecisionOutcome::Conflict) {
                throw DuplicateActiveAssignment::forScope($scope);
            }

            $assignment = $this->createAssignment(
                $subjectType, $subjectId, $permissionId, $actor, $reason, $command,
            );
            $audit = new PendingAuditRecord(
                event: 'permission.granted_to_subject',
                actor: $actor,
                subjectType: $subjectType,
                subjectId: $subjectId,
                targetType: 'permission_assignment',
                targetId: $this->identifier($assignment, 'permission assignment'),
                before: null,
                after: $this->snapshots->build($assignment),
                reason: $this->redactor->auditReason($reason),
                context: $this->contexts->directPermissionAssignment(
                    $this->requiredString($permission, 'guard_name'),
                    $this->requiredString($permission, 'name'),
                    $metadata,
                ),
                ipAddress: null,
                userAgent: null,
                requestId: null,
                createdAt: $command->requestedAt,
            );

            return new PreparedMutation($assignment, MutationOutcome::Created, $audit);
        });
    }

    /** @return array{string, int|string} */
    private function subjectIdentity(Model $subject): array
    {
        $id = $subject->getKey();
        if (! $subject->exists || (! is_int($id) && ! is_string($id))) {
            throw new InvalidArgumentException('GateTrail permission assignment subject must be persisted.');
        }

        return [$subject->getMorphClass(), $id];
    }

    private function identifier(Model $model, string $label): int|string
    {
        $id = $model->getKey();
        if (! is_int($id) && ! is_string($id)) {
            throw new LogicException("GateTrail $label has no persisted identifier.");
        }

        return $id;
    }

    private function createAssignment(
        string $subjectType,
        int|string $subjectId,
        int|string $permissionId,
        ActorIdentity $actor,
        ?string $reason,
        GrantDirectPermissionCommand $command,
    ): Model&PermissionAssignmentContract {
        $class = $this->models->permissionAssignment();
        $assignment = new $class;
        $assignment->forceFill([
            'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'permission_id' => $permissionId, 'assigned_at' => $command->requestedAt,
            'assigned_by_type' => $actor->type, 'assigned_by_id' => $actor->id,
            'assignment_reason' => $reason, 'starts_at' => $command->period->startsAt(),
            'expires_at' => $command->period->expiresAt(), 'revoked_at' => null,
            'revoked_by_type' => null, 'revoked_by_id' => null, 'revocation_reason' => null,
            'created_at' => $command->requestedAt, 'updated_at' => $command->requestedAt,
        ])->save();

        return $assignment;
    }

    private function requiredString(Model $model, string $attribute): string
    {
        $value = $model->getAttribute($attribute);
        if (! is_string($value) || trim($value) === '') {
            throw new LogicException("GateTrail permission attribute [$attribute] must be a non-empty string.");
        }

        return trim($value);
    }
}

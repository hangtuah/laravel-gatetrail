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
use HangTuah\GateTrail\Audit\RolePermissionSnapshotBuilder;
use HangTuah\GateTrail\Audit\SensitiveAuditValueRedactor;
use HangTuah\GateTrail\Contracts\RolePermissionContract;
use HangTuah\GateTrail\Exceptions\DuplicateActiveAssignment;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\MutationResult;
use HangTuah\GateTrail\Mutations\ActorIdentity;
use HangTuah\GateTrail\Mutations\ActorIdentityResolver;
use HangTuah\GateTrail\Mutations\AuditedMutationTransaction;
use HangTuah\GateTrail\Mutations\Commands\GrantPermissionToRoleCommand;
use HangTuah\GateTrail\Mutations\MutationName;
use HangTuah\GateTrail\Mutations\MutationReasonValidator;
use HangTuah\GateTrail\Mutations\PreparedMutation;
use HangTuah\GateTrail\Mutations\RolePermissionDefinitionResolver;
use HangTuah\GateTrail\Support\ModelRegistry;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** @internal */
final readonly class GrantPermissionToRoleHandler
{
    public function __construct(
        private MetadataNormalizer $metadata,
        private ActorIdentityResolver $actors,
        private MutationReasonValidator $reasons,
        private AuditedMutationTransaction $mutations,
        private RolePermissionDefinitionResolver $definitions,
        private AssignmentLockStore $locks,
        private AssignmentConflictQuery $conflicts,
        private AssignmentDecisionMaker $decisions,
        private ModelRegistry $models,
        private RolePermissionSnapshotBuilder $snapshots,
        private AuditContextBuilder $contexts,
        private SensitiveAuditValueRedactor $redactor,
    ) {}

    /** @return MutationResult<Model&RolePermissionContract> */
    public function handle(
        GrantPermissionToRoleCommand $command,
    ): MutationResult {

        $metadata = $this->metadata->normalize($command->metadata);
        $actor = $this->actors->resolve($command->actor);
        $reason = $this->reasons->assignment($command->reason);
        $candidate = EffectiveAssignmentPeriod::fromAssignment(
            $command->period,
            $command->requestedAt,
        );

        return $this->mutations->runCoordinated(
            MutationName::PERMISSION_GRANTED_TO_ROLE,
            function () use (
                $command,
                $metadata,
                $actor,
                $reason,
                $candidate,
            ): PreparedMutation {
                [$role, $permission, $guard] = $this->definitions->resolve(
                    $command->role,
                    $command->permission,
                );
                $roleId = $this->identifier($role, 'role');
                $permissionId = $this->identifier(
                    $permission,
                    'permission',
                );
                $scope = AssignmentScope::forRolePermission(
                    $roleId,
                    $permissionId,
                );

                $this->locks->acquire($scope, $command->requestedAt);

                $decision = $this->decisions->decide(
                    $command->period,
                    $candidate,
                    $this->conflicts->find($scope, $candidate),
                );

                if ($decision->outcome
                    === AssignmentDecisionOutcome::Unchanged) {
                    if (! $decision->existingAssignment instanceof Model
                        || ! $decision->existingAssignment
                            instanceof RolePermissionContract) {
                        throw new LogicException(
                            'GateTrail unchanged role-permission assignment '
                            .'has no existing model.',
                        );
                    }

                    return new PreparedMutation(
                        $decision->existingAssignment,
                        MutationOutcome::Unchanged,
                        null,
                    );
                }

                if ($decision->outcome
                    === AssignmentDecisionOutcome::Conflict) {
                    throw DuplicateActiveAssignment::forScope($scope);
                }

                $assignment = $this->createAssignment(
                    $roleId,
                    $permissionId,
                    $actor,
                    $reason,
                    $command,
                );
                $audit = new PendingAuditRecord(
                    event: MutationName::PERMISSION_GRANTED_TO_ROLE,
                    actor: $actor,
                    subjectType: null,
                    subjectId: null,
                    targetType: 'role_permission',
                    targetId: $this->identifier(
                        $assignment,
                        'role-permission assignment',
                    ),
                    before: null,
                    after: $this->snapshots->build($assignment),
                    reason: $this->redactor->auditReason($reason),
                    context: $this->contexts->rolePermissionAssignment(
                        $guard,
                        $this->requiredString($role, 'name', 'role'),
                        $this->requiredString(
                            $permission,
                            'name',
                            'permission',
                        ),
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

    private function identifier(
        Model $model,
        string $label,
    ): int|string {
        $id = $model->getKey();

        if (! is_int($id) && ! is_string($id)) {
            throw new LogicException(
                "GateTrail $label has no persisted identifier.",
            );
        }

        return $id;
    }

    private function createAssignment(
        int|string $roleId,
        int|string $permissionId,
        ActorIdentity $actor,
        ?string $reason,
        GrantPermissionToRoleCommand $command,
    ): Model&RolePermissionContract {
        $class = $this->models->rolePermission();
        $assignment = new $class;

        $assignment->forceFill([
            'role_id' => $roleId,
            'permission_id' => $permissionId,
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
        ])->save();

        return $assignment;
    }

    private function requiredString(
        Model $model,
        string $attribute,
        string $label,
    ): string {
        $value = $model->getAttribute($attribute);

        if (! is_string($value) || trim($value) === '') {
            throw new LogicException(
                "GateTrail $label attribute [$attribute] "
                .'must be a non-empty string.',
            );
        }

        return trim($value);
    }
}

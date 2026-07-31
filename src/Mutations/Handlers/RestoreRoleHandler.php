<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations\Handlers;

use HangTuah\GateTrail\Audit\AuditContextBuilder;
use HangTuah\GateTrail\Audit\MetadataNormalizer;
use HangTuah\GateTrail\Audit\PendingAuditRecord;
use HangTuah\GateTrail\Audit\RoleSnapshotBuilder;
use HangTuah\GateTrail\Audit\SensitiveAuditValueRedactor;
use HangTuah\GateTrail\Contracts\RoleContract;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\MutationResult;
use HangTuah\GateTrail\Mutations\ActorIdentityResolver;
use HangTuah\GateTrail\Mutations\AuditedMutationTransaction;
use HangTuah\GateTrail\Mutations\Commands\RestoreRoleCommand;
use HangTuah\GateTrail\Mutations\DeactivatedRoleDefinitionInputResolver;
use HangTuah\GateTrail\Mutations\MutationReasonValidator;
use HangTuah\GateTrail\Mutations\PreparedMutation;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Reactivates one deactivated role definition without rewriting history.
 *
 * Restoration is definition-scoped and is the exact inverse of
 * {@see DeactivateRoleHandler}. Preserved subject-role and role-permission
 * assignments become eligible again on their own merits, while separately
 * revoked assignments stay revoked and no assignment row is touched.
 *
 * @internal
 */
final readonly class RestoreRoleHandler
{
    public function __construct(
        private MetadataNormalizer $metadata,
        private ActorIdentityResolver $actors,
        private MutationReasonValidator $reasons,
        private AuditedMutationTransaction $mutations,
        private DeactivatedRoleDefinitionInputResolver $roles,
        private RoleSnapshotBuilder $snapshots,
        private AuditContextBuilder $contexts,
        private SensitiveAuditValueRedactor $redactor,
    ) {}

    /**
     * @return MutationResult<Model&RoleContract>
     */
    public function handle(RestoreRoleCommand $command): MutationResult
    {
        $metadata = $this->metadata->normalize($command->metadata);
        $actor = $this->actors->resolve($command->actor);
        $reason = $this->reasons->definition($command->reason);

        return $this->mutations->run(
            function () use (
                $command,
                $metadata,
                $actor,
                $reason,
            ): PreparedMutation {
                $role = $this->roles->resolve($command->role);
                $guard = $this->requiredString($role, 'guard_name');
                $name = $this->requiredString($role, 'name');
                $before = $this->snapshots->build($role);

                // Eloquent's restore() would stamp an uncontrolled wall-clock
                // updated_at and emit restoring/restored model events. A plain
                // update stays symmetric with DeactivateRoleHandler and keeps
                // the single captured package clock timestamp.
                $role->forceFill([
                    'deleted_at' => null,
                    'updated_at' => $command->requestedAt,
                ]);
                $role->save();

                $audit = new PendingAuditRecord(
                    event: 'role.restored',
                    actor: $actor,
                    subjectType: null,
                    subjectId: null,
                    targetType: 'role',
                    targetId: $this->identifier($role),
                    before: $before,
                    after: $this->snapshots->build($role),
                    reason: $this->redactor->auditReason($reason),
                    context: $this->contexts->roleDefinition(
                        $guard,
                        $name,
                        $metadata,
                    ),
                    ipAddress: null,
                    userAgent: null,
                    requestId: null,
                    createdAt: $command->requestedAt,
                );

                return new PreparedMutation(
                    $role,
                    MutationOutcome::Restored,
                    $audit,
                );
            },
        );
    }

    private function identifier(Model $role): int|string
    {
        $id = $role->getKey();

        if (! is_int($id) && ! is_string($id)) {
            throw new LogicException(
                'GateTrail role has no persisted identifier.',
            );
        }

        return $id;
    }

    private function requiredString(
        Model $role,
        string $attribute,
    ): string {
        $value = $role->getAttribute($attribute);

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

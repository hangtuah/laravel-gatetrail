<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations\Handlers;

use HangTuah\GateTrail\Audit\AuditContextBuilder;
use HangTuah\GateTrail\Audit\MetadataNormalizer;
use HangTuah\GateTrail\Audit\PendingAuditRecord;
use HangTuah\GateTrail\Audit\PermissionSnapshotBuilder;
use HangTuah\GateTrail\Audit\SensitiveAuditValueRedactor;
use HangTuah\GateTrail\Contracts\PermissionContract;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\MutationResult;
use HangTuah\GateTrail\Mutations\ActorIdentityResolver;
use HangTuah\GateTrail\Mutations\AuditedMutationTransaction;
use HangTuah\GateTrail\Mutations\Commands\RestorePermissionCommand;
use HangTuah\GateTrail\Mutations\DeactivatedPermissionDefinitionInputResolver;
use HangTuah\GateTrail\Mutations\MutationReasonValidator;
use HangTuah\GateTrail\Mutations\PreparedMutation;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Reactivates one deactivated permission definition without rewriting history.
 *
 * Restoration is definition-scoped and is the exact inverse of
 * {@see DeactivatePermissionHandler}. Preserved direct permission assignments
 * and role-permission grants become eligible again on their own merits, while
 * separately revoked assignments stay revoked and no assignment row is
 * touched.
 *
 * @internal
 */
final readonly class RestorePermissionHandler
{
    public function __construct(
        private MetadataNormalizer $metadata,
        private ActorIdentityResolver $actors,
        private MutationReasonValidator $reasons,
        private AuditedMutationTransaction $mutations,
        private DeactivatedPermissionDefinitionInputResolver $permissions,
        private PermissionSnapshotBuilder $snapshots,
        private AuditContextBuilder $contexts,
        private SensitiveAuditValueRedactor $redactor,
    ) {}

    /**
     * @return MutationResult<Model&PermissionContract>
     */
    public function handle(
        RestorePermissionCommand $command,
    ): MutationResult {
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
                $permission = $this->permissions->resolve(
                    $command->permission,
                );
                $guard = $this->requiredString($permission, 'guard_name');
                $name = $this->requiredString($permission, 'name');
                $before = $this->snapshots->build($permission);

                // Eloquent's restore() would stamp an uncontrolled wall-clock
                // updated_at and emit restoring/restored model events. A plain
                // update stays symmetric with DeactivatePermissionHandler and
                // keeps the single captured package clock timestamp.
                $permission->forceFill([
                    'deleted_at' => null,
                    'updated_at' => $command->requestedAt,
                ]);
                $permission->save();

                $audit = new PendingAuditRecord(
                    event: 'permission.restored',
                    actor: $actor,
                    subjectType: null,
                    subjectId: null,
                    targetType: 'permission',
                    targetId: $this->identifier($permission),
                    before: $before,
                    after: $this->snapshots->build($permission),
                    reason: $this->redactor->auditReason($reason),
                    context: $this->contexts->permissionDefinition(
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
                    $permission,
                    MutationOutcome::Restored,
                    $audit,
                );
            },
        );
    }

    private function identifier(Model $permission): int|string
    {
        $id = $permission->getKey();

        if (! is_int($id) && ! is_string($id)) {
            throw new LogicException(
                'GateTrail permission has no persisted identifier.',
            );
        }

        return $id;
    }

    private function requiredString(
        Model $permission,
        string $attribute,
    ): string {
        $value = $permission->getAttribute($attribute);

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

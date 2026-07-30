<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations\Handlers;

use HangTuah\GateTrail\Audit\AccessAuditWriter;
use HangTuah\GateTrail\Audit\AuditContextBuilder;
use HangTuah\GateTrail\Audit\MetadataNormalizer;
use HangTuah\GateTrail\Audit\PendingAuditRecord;
use HangTuah\GateTrail\Audit\RoleSnapshotBuilder;
use HangTuah\GateTrail\Audit\SensitiveAuditValueRedactor;
use HangTuah\GateTrail\Contracts\RoleContract;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\MutationResult;
use HangTuah\GateTrail\Mutations\ActorIdentityResolver;
use HangTuah\GateTrail\Mutations\Commands\DeactivateRoleCommand;
use HangTuah\GateTrail\Mutations\MutationReasonValidator;
use HangTuah\GateTrail\Mutations\RoleDefinitionInputResolver;
use HangTuah\GateTrail\Mutations\StrictMutationTransaction;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Deactivates one role definition without touching assignment history.
 *
 * Deactivation is definition-scoped: the role stops being an effective
 * authorization source, but every subject-role and role-permission assignment
 * remains stored, unrevoked, and attributable, and the `(name, guard_name)`
 * namespace stays occupied.
 *
 * @internal
 */
final readonly class DeactivateRoleHandler
{
    public function __construct(
        private Repository $config,
        private MetadataNormalizer $metadata,
        private ActorIdentityResolver $actors,
        private MutationReasonValidator $reasons,
        private StrictMutationTransaction $transaction,
        private RoleDefinitionInputResolver $roles,
        private RoleSnapshotBuilder $snapshots,
        private AuditContextBuilder $contexts,
        private SensitiveAuditValueRedactor $redactor,
        private AccessAuditWriter $audits,
    ) {}

    /**
     * @return MutationResult<Model&RoleContract>
     */
    public function handle(DeactivateRoleCommand $command): MutationResult
    {
        $this->assertStrictAuditMode();
        $metadata = $this->metadata->normalize($command->metadata);
        $actor = $this->actors->resolve($command->actor);
        $reason = $this->reasons->definition($command->reason);

        return $this->transaction->run(
            function () use (
                $command,
                $metadata,
                $actor,
                $reason,
            ): MutationResult {
                $role = $this->roles->resolve($command->role);
                $guard = $this->requiredString($role, 'guard_name');
                $name = $this->requiredString($role, 'name');
                $before = $this->snapshots->build($role);

                // A plain update keeps the deletion timestamp under the single
                // captured package clock. Eloquent's soft-delete helper would
                // stamp an uncontrolled wall-clock value instead.
                $role->forceFill([
                    'deleted_at' => $command->requestedAt,
                    'updated_at' => $command->requestedAt,
                ]);
                $role->save();

                $auditId = $this->audits->append(
                    new PendingAuditRecord(
                        event: 'role.deactivated',
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
                    ),
                );

                return new MutationResult(
                    $role,
                    MutationOutcome::Deactivated,
                    $auditId,
                );
            },
        );
    }

    private function assertStrictAuditMode(): void
    {
        if (
            $this->config->get('gatetrail.audit.enabled') !== true
            || $this->config->get('gatetrail.audit.strict') !== true
        ) {
            throw new LogicException(
                'GateTrail DeactivateRoleHandler currently requires strict auditing.',
            );
        }
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

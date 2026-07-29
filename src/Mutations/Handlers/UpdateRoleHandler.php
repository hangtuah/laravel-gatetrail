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
use HangTuah\GateTrail\Mutations\Commands\UpdateRoleCommand;
use HangTuah\GateTrail\Mutations\DefinitionInputValidator;
use HangTuah\GateTrail\Mutations\MutationReasonValidator;
use HangTuah\GateTrail\Mutations\RoleDefinitionInputResolver;
use HangTuah\GateTrail\Mutations\StrictMutationTransaction;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @internal
 */
final readonly class UpdateRoleHandler
{
    public function __construct(
        private Repository $config,
        private DefinitionInputValidator $definitions,
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
    public function handle(UpdateRoleCommand $command): MutationResult
    {
        $this->assertStrictAuditMode();
        $description = $this->definitions->description(
            $command->changes->description,
        );
        $metadata = $this->metadata->normalize($command->metadata);
        $actor = $this->actors->resolve($command->actor);
        $reason = $this->reasons->definition($command->reason);

        return $this->transaction->run(
            function () use (
                $command,
                $description,
                $metadata,
                $actor,
                $reason,
            ): MutationResult {
                $role = $this->roles->resolve($command->role);
                $persisted = $this->currentDescription($role);

                if ($persisted === $description) {
                    return new MutationResult(
                        $role,
                        MutationOutcome::Unchanged,
                        null,
                    );
                }

                $before = $this->snapshots->build($role);
                $role->forceFill([
                    'description' => $description,
                    'updated_at' => $command->requestedAt,
                ]);
                $role->save();

                $auditId = $this->audits->append(
                    new PendingAuditRecord(
                        event: 'role.updated',
                        actor: $actor,
                        subjectType: null,
                        subjectId: null,
                        targetType: 'role',
                        targetId: $this->identifier($role),
                        before: $before,
                        after: $this->snapshots->build($role),
                        reason: $this->redactor->auditReason($reason),
                        context: $this->contexts->roleDefinition(
                            $this->requiredString($role, 'guard_name'),
                            $this->requiredString($role, 'name'),
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
                    MutationOutcome::Updated,
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
                'GateTrail UpdateRoleHandler currently requires strict auditing.',
            );
        }
    }

    private function currentDescription(Model $role): ?string
    {
        $value = $role->getAttribute('description');

        if ($value !== null && ! is_string($value)) {
            throw new LogicException(
                'GateTrail role description must be a string or null.',
            );
        }

        return $value;
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

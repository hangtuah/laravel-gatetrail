<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations\Handlers;

use HangTuah\GateTrail\Audit\AccessAuditWriter;
use HangTuah\GateTrail\Audit\AuditContextBuilder;
use HangTuah\GateTrail\Audit\MetadataNormalizer;
use HangTuah\GateTrail\Audit\PendingAuditRecord;
use HangTuah\GateTrail\Audit\PermissionSnapshotBuilder;
use HangTuah\GateTrail\Audit\SensitiveAuditValueRedactor;
use HangTuah\GateTrail\Contracts\PermissionContract;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\MutationResult;
use HangTuah\GateTrail\Mutations\ActorIdentityResolver;
use HangTuah\GateTrail\Mutations\Commands\UpdatePermissionCommand;
use HangTuah\GateTrail\Mutations\DefinitionInputValidator;
use HangTuah\GateTrail\Mutations\MutationReasonValidator;
use HangTuah\GateTrail\Mutations\PermissionDefinitionInputResolver;
use HangTuah\GateTrail\Mutations\StrictMutationTransaction;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @internal
 */
final readonly class UpdatePermissionHandler
{
    public function __construct(
        private Repository $config,
        private DefinitionInputValidator $definitions,
        private MetadataNormalizer $metadata,
        private ActorIdentityResolver $actors,
        private MutationReasonValidator $reasons,
        private StrictMutationTransaction $transaction,
        private PermissionDefinitionInputResolver $permissions,
        private PermissionSnapshotBuilder $snapshots,
        private AuditContextBuilder $contexts,
        private SensitiveAuditValueRedactor $redactor,
        private AccessAuditWriter $audits,
    ) {}

    /**
     * @return MutationResult<Model&PermissionContract>
     */
    public function handle(UpdatePermissionCommand $command): MutationResult
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
                $permission = $this->permissions->resolve(
                    $command->permission,
                );
                $persisted = $this->currentDescription($permission);

                if ($persisted === $description) {
                    return new MutationResult(
                        $permission,
                        MutationOutcome::Unchanged,
                        null,
                    );
                }

                $before = $this->snapshots->build($permission);
                $permission->forceFill([
                    'description' => $description,
                    'updated_at' => $command->requestedAt,
                ]);
                $permission->save();

                $auditId = $this->audits->append(
                    new PendingAuditRecord(
                        event: 'permission.updated',
                        actor: $actor,
                        subjectType: null,
                        subjectId: null,
                        targetType: 'permission',
                        targetId: $this->identifier($permission),
                        before: $before,
                        after: $this->snapshots->build($permission),
                        reason: $this->redactor->auditReason($reason),
                        context: $this->contexts->permissionDefinition(
                            $this->requiredString(
                                $permission,
                                'guard_name',
                            ),
                            $this->requiredString($permission, 'name'),
                            $metadata,
                        ),
                        ipAddress: null,
                        userAgent: null,
                        requestId: null,
                        createdAt: $command->requestedAt,
                    ),
                );

                return new MutationResult(
                    $permission,
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
                'GateTrail UpdatePermissionHandler currently requires strict auditing.',
            );
        }
    }

    private function currentDescription(Model $permission): ?string
    {
        $value = $permission->getAttribute('description');

        if ($value !== null && ! is_string($value)) {
            throw new LogicException(
                'GateTrail permission description must be a string or null.',
            );
        }

        return $value;
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

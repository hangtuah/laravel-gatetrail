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
use HangTuah\GateTrail\Database\UniqueConstraintViolationClassifier;
use HangTuah\GateTrail\Exceptions\DuplicateRole;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\MutationResult;
use HangTuah\GateTrail\Mutations\ActorIdentityResolver;
use HangTuah\GateTrail\Mutations\Commands\CreateRoleCommand;
use HangTuah\GateTrail\Mutations\DefinitionInputValidator;
use HangTuah\GateTrail\Mutations\MutationReasonValidator;
use HangTuah\GateTrail\Mutations\StrictMutationTransaction;
use HangTuah\GateTrail\Support\ModelRegistry;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use LogicException;

/**
 * @internal
 */
final readonly class CreateRoleHandler
{
    public function __construct(
        private Repository $config,
        private DefinitionInputValidator $definitions,
        private MetadataNormalizer $metadata,
        private ActorIdentityResolver $actors,
        private MutationReasonValidator $reasons,
        private StrictMutationTransaction $transaction,
        private ModelRegistry $models,
        private UniqueConstraintViolationClassifier $uniqueViolations,
        private RoleSnapshotBuilder $snapshots,
        private AuditContextBuilder $contexts,
        private SensitiveAuditValueRedactor $redactor,
        private AccessAuditWriter $audits,
    ) {}

    /**
     * @return MutationResult<Role&RoleContract>
     */
    public function handle(CreateRoleCommand $command): MutationResult
    {
        $this->assertStrictAuditMode();
        $name = $this->definitions->name($command->name);
        $guard = $this->definitions->guard($command->guard);
        $description = $this->definitions->description(
            $command->description,
        );
        $metadata = $this->metadata->normalize($command->metadata);
        $actor = $this->actors->resolve($command->actor);
        $reason = $this->reasons->definition($command->reason);

        return $this->transaction->run(
            function () use (
                $command,
                $name,
                $guard,
                $description,
                $metadata,
                $actor,
                $reason,
            ): MutationResult {
                $class = $this->models->role();
                $collision = $class::query()
                    ->withTrashed()
                    ->where('name', $name)
                    ->where('guard_name', $guard)
                    ->lockForUpdate()
                    ->first();

                if ($collision !== null) {
                    throw DuplicateRole::forNamespace($name, $guard);
                }

                $role = new $class;
                $role->forceFill([
                    'name' => $name,
                    'guard_name' => $guard,
                    'description' => $description,
                    'deleted_at' => null,
                    'created_at' => $command->requestedAt,
                    'updated_at' => $command->requestedAt,
                ]);

                try {
                    $role->save();
                } catch (QueryException $exception) {
                    if (
                        $this->uniqueViolations->isUniqueViolation(
                            $exception,
                        )
                    ) {
                        throw DuplicateRole::forNamespace($name, $guard);
                    }

                    throw $exception;
                }

                $auditId = $this->audits->append(
                    new PendingAuditRecord(
                        event: 'role.created',
                        actor: $actor,
                        subjectType: null,
                        subjectId: null,
                        targetType: 'role',
                        targetId: $this->identifier($role),
                        before: null,
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
                    MutationOutcome::Created,
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
                'GateTrail CreateRoleHandler currently requires strict auditing.',
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
}

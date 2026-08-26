<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations\Handlers;

use HangTuah\GateTrail\Audit\AuditContextBuilder;
use HangTuah\GateTrail\Audit\MetadataNormalizer;
use HangTuah\GateTrail\Audit\PendingAuditRecord;
use HangTuah\GateTrail\Audit\RoleSnapshotBuilder;
use HangTuah\GateTrail\Audit\SensitiveAuditValueRedactor;
use HangTuah\GateTrail\Contracts\RoleContract;
use HangTuah\GateTrail\Database\UniqueConstraintViolationClassifier;
use HangTuah\GateTrail\Exceptions\DuplicateRole;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\MutationResult;
use HangTuah\GateTrail\Mutations\ActorIdentityResolver;
use HangTuah\GateTrail\Mutations\AuditedMutationTransaction;
use HangTuah\GateTrail\Mutations\Commands\CreateRoleCommand;
use HangTuah\GateTrail\Mutations\DefinitionInputValidator;
use HangTuah\GateTrail\Mutations\MutationName;
use HangTuah\GateTrail\Mutations\MutationReasonValidator;
use HangTuah\GateTrail\Mutations\PreparedMutation;
use HangTuah\GateTrail\Support\ModelRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use LogicException;

/**
 * @internal
 */
final readonly class CreateRoleHandler
{
    private const NAMESPACE_CONSTRAINT = 'acl_role_name_guard_uq';

    private const NAMESPACE_COLUMNS = ['name', 'guard_name'];

    public function __construct(
        private DefinitionInputValidator $definitions,
        private MetadataNormalizer $metadata,
        private ActorIdentityResolver $actors,
        private MutationReasonValidator $reasons,
        private AuditedMutationTransaction $mutations,
        private ModelRegistry $models,
        private UniqueConstraintViolationClassifier $uniqueViolations,
        private RoleSnapshotBuilder $snapshots,
        private AuditContextBuilder $contexts,
        private SensitiveAuditValueRedactor $redactor,
    ) {}

    /**
     * @return MutationResult<Model&RoleContract>
     */
    public function handle(CreateRoleCommand $command): MutationResult
    {
        $name = $this->definitions->name($command->name);
        $guard = $this->definitions->guard($command->guard);
        $description = $this->definitions->description(
            $command->description,
        );
        $metadata = $this->metadata->normalize($command->metadata);
        $actor = $this->actors->resolve($command->actor);
        $reason = $this->reasons->definition($command->reason);

        return $this->mutations->run(
            function () use (
                $command,
                $name,
                $guard,
                $description,
                $metadata,
                $actor,
                $reason,
            ): PreparedMutation {
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
                        $this->uniqueViolations->matchesConstraint(
                            $exception,
                            self::NAMESPACE_CONSTRAINT,
                            $role->getTable(),
                            self::NAMESPACE_COLUMNS,
                        )
                    ) {
                        throw DuplicateRole::forNamespace($name, $guard);
                    }

                    throw $exception;
                }

                $audit = new PendingAuditRecord(
                    event: MutationName::ROLE_CREATED,
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
                );

                return new PreparedMutation(
                    $role,
                    MutationOutcome::Created,
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
}

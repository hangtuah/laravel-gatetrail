<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations\Handlers;

use DateTimeInterface;
use HangTuah\GateTrail\Audit\AuditContextBuilder;
use HangTuah\GateTrail\Audit\MetadataNormalizer;
use HangTuah\GateTrail\Audit\PendingAuditRecord;
use HangTuah\GateTrail\Audit\PermissionAssignmentSnapshotBuilder;
use HangTuah\GateTrail\Audit\SensitiveAuditValueRedactor;
use HangTuah\GateTrail\Exceptions\AssignmentNotActive;
use HangTuah\GateTrail\Models\PermissionAssignment;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\MutationResult;
use HangTuah\GateTrail\Mutations\ActorIdentityResolver;
use HangTuah\GateTrail\Mutations\AuditedMutationTransaction;
use HangTuah\GateTrail\Mutations\Commands\RevokeDirectPermissionCommand;
use HangTuah\GateTrail\Mutations\MutationReasonValidator;
use HangTuah\GateTrail\Mutations\PermissionAssignmentInputResolver;
use HangTuah\GateTrail\Mutations\PreparedMutation;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** @internal */
final readonly class RevokeDirectPermissionHandler
{
    public function __construct(private MetadataNormalizer $metadata, private ActorIdentityResolver $actors, private MutationReasonValidator $reasons, private AuditedMutationTransaction $mutations, private PermissionAssignmentInputResolver $assignments, private PermissionAssignmentSnapshotBuilder $snapshots, private AuditContextBuilder $contexts, private SensitiveAuditValueRedactor $redactor) {}

    /** @return MutationResult<PermissionAssignment> */
    public function handle(RevokeDirectPermissionCommand $command): MutationResult
    {
        $metadata = $this->metadata->normalize($command->metadata);
        $actor = $this->actors->resolve($command->actor);
        $reason = $this->reasons->assignment($command->reason);

        return $this->mutations->run(function () use ($command, $metadata, $actor, $reason): PreparedMutation {
            $a = $this->assignments->resolve($command->assignment);
            $id = $a->getKey();
            if (! is_int($id) && ! is_string($id)) {
                throw new LogicException('GateTrail permission assignment has no identifier.');
            }
            $expiry = $a->getAttribute('expires_at');
            if ($a->getAttribute('revoked_at') !== null || ($expiry !== null && (! $expiry instanceof DateTimeInterface || $expiry <= $command->requestedAt))) {
                throw AssignmentNotActive::forPermission($id);
            }
            $before = $this->snapshots->build($a, 'before');
            $a->forceFill(['revoked_at' => $command->requestedAt, 'revoked_by_type' => $actor->type, 'revoked_by_id' => $actor->id, 'revocation_reason' => $reason, 'updated_at' => $command->requestedAt])->save();
            $permission = $a->permission()->first();
            if (! $permission instanceof Model) {
                throw new LogicException('GateTrail permission assignment has no related permission.');
            }
            $subjectType = $a->getAttribute('subject_type');
            $subjectId = $a->getAttribute('subject_id');
            if (! is_string($subjectType) || (! is_int($subjectId) && ! is_string($subjectId))) {
                throw new LogicException('GateTrail permission assignment subject is invalid.');
            }
            $guard = $permission->getAttribute('guard_name');
            $name = $permission->getAttribute('name');
            if (! is_string($guard) || ! is_string($name)) {
                throw new LogicException('GateTrail permission identity is invalid.');
            }
            $audit = new PendingAuditRecord(event: 'permission.revoked_from_subject', actor: $actor, subjectType: $subjectType, subjectId: $subjectId, targetType: 'permission_assignment', targetId: $id, before: $before, after: $this->snapshots->build($a, 'after'), reason: $this->redactor->auditReason($reason), context: $this->contexts->directPermissionAssignment($guard, $name, $metadata), ipAddress: null, userAgent: null, requestId: null, createdAt: $command->requestedAt);

            return new PreparedMutation($a, MutationOutcome::Revoked, $audit);
        });
    }
}

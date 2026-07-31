<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Events;

use DateTimeImmutable;
use DateTimeZone;
use HangTuah\GateTrail\Audit\PendingAuditRecord;
use HangTuah\GateTrail\MutationOutcome;

/**
 * The one definition of GateTrail's safe mutation diagnostic payload.
 *
 * {@see MutationCommitted::context()} and the operational log written when an
 * event cannot even be constructed both route through here, so a failure is
 * described in exactly the same shape as a success and the two cannot drift
 * apart.
 *
 * Only safe scalars enter: no snapshots, no consumer metadata, no reason, no
 * credentials, no Eloquent models, and no exception detail.
 *
 * @internal
 */
final readonly class SafeMutationContext
{
    /**
     * @return array<string, mixed>
     */
    public static function build(
        string $mutation,
        MutationOutcome $outcome,
        string $targetType,
        int|string $targetId,
        ?string $subjectType,
        int|string|null $subjectId,
        string $actorType,
        string $actorId,
        int|string|null $auditId,
        DateTimeImmutable $occurredAt,
        ?string $requestId,
    ): array {
        $context = [
            'mutation' => $mutation,
            'outcome' => $outcome->value,
            'target_type' => $targetType,
            'target_id' => (string) $targetId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId === null
                ? null
                : (string) $subjectId,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'audit_id' => $auditId === null
                ? null
                : (string) $auditId,
            'occurred_at' => $occurredAt
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.u\Z'),
        ];

        if ($requestId !== null) {
            $context['request_id'] = $requestId;
        }

        return $context;
    }

    /**
     * Describes a mutation straight from its canonical audit record.
     *
     * This is the fallback used when {@see MutationCommitted} itself could not
     * be built, so it reads only the record's already-safe scalar fields and
     * never touches its before, after, or context documents.
     *
     * @return array<string, mixed>
     */
    public static function fromRecord(
        PendingAuditRecord $record,
        MutationOutcome $outcome,
        int|string|null $auditId,
    ): array {
        return self::build(
            mutation: $record->event,
            outcome: $outcome,
            targetType: $record->targetType,
            targetId: $record->targetId,
            subjectType: $record->subjectType,
            subjectId: $record->subjectId,
            actorType: $record->actor->type,
            actorId: $record->actor->id,
            auditId: $auditId,
            occurredAt: $record->createdAt,
            requestId: $record->requestId,
        );
    }
}

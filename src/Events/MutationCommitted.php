<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Events;

use DateTimeImmutable;
use DateTimeZone;
use HangTuah\GateTrail\Contracts\GateTrailEvent;
use HangTuah\GateTrail\MutationOutcome;
use InvalidArgumentException;

/**
 * Reports one durable authorization change, after the outermost commit.
 *
 * This is a domain event, not an audit record and not a failure signal. It
 * carries a stable mutation name and safe identifiers only, because it may be
 * queued, logged, or forwarded to a third-party integration. Snapshots,
 * consumer metadata, reasons, credentials, and Eloquent models deliberately
 * stay in audit storage.
 *
 * A null `auditId` means no audit identifier is available — non-strict audit
 * failure, or disabled auditing. It never means the mutation failed: the
 * event's existence is the success signal.
 *
 * Consumers receive instances and never construct them.
 *
 * @see docs/decisions/0010-after-commit-mutation-events.md
 */
final readonly class MutationCommitted implements GateTrailEvent
{
    /**
     * Every mutation GateTrail dispatches, using the canonical audit event
     * vocabulary so an event and its audit row join on one value.
     *
     * @var list<string>
     */
    private const MUTATIONS = [
        'role.assigned',
        'role.revoked',
        'permission.granted_to_subject',
        'permission.revoked_from_subject',
        'permission.granted_to_role',
        'permission.revoked_from_role',
        'role.created',
        'role.updated',
        'role.deactivated',
        'role.restored',
        'permission.created',
        'permission.updated',
        'permission.deactivated',
        'permission.restored',
    ];

    private function __construct(
        public string $mutation,
        public MutationOutcome $outcome,
        public string $targetType,
        public int|string $targetId,
        public ?string $subjectType,
        public int|string|null $subjectId,
        public string $actorType,
        public string $actorId,
        public int|string|null $auditId,
        public DateTimeImmutable $occurredAt,
        public ?string $requestId,
    ) {}

    /**
     * @internal
     */
    public static function record(
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
    ): self {
        if (! in_array($mutation, self::MUTATIONS, true)) {
            throw new InvalidArgumentException(
                'GateTrail cannot dispatch an unknown mutation event.',
            );
        }

        if ($outcome === MutationOutcome::Unchanged) {
            throw new InvalidArgumentException(
                'An unchanged GateTrail mutation changed no authorization state.',
            );
        }

        if (trim($targetType) === '') {
            throw new InvalidArgumentException(
                'GateTrail mutation events require a target type.',
            );
        }

        if (($subjectType === null) !== ($subjectId === null)) {
            throw new InvalidArgumentException(
                'GateTrail mutation event subject type and ID must both be present or absent.',
            );
        }

        if (trim($actorType) === '' || trim($actorId) === '') {
            throw new InvalidArgumentException(
                'GateTrail mutation events require an actor type and identifier.',
            );
        }

        return new self(
            $mutation,
            $outcome,
            $targetType,
            $targetId,
            $subjectType,
            $subjectId,
            $actorType,
            $actorId,
            $auditId,
            $occurredAt,
            $requestId,
        );
    }

    /**
     * The safe diagnostic payload, in a fixed key order.
     *
     * Numeric identifiers render as canonical decimal strings so non-PHP
     * consumers keep 64-bit precision; string identifiers such as UUIDs and
     * ULIDs pass through untouched. `request_id` is present only when one was
     * captured.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        $context = [
            'mutation' => $this->mutation,
            'outcome' => $this->outcome->value,
            'target_type' => $this->targetType,
            'target_id' => (string) $this->targetId,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId === null
                ? null
                : (string) $this->subjectId,
            'actor_type' => $this->actorType,
            'actor_id' => $this->actorId,
            'audit_id' => $this->auditId === null
                ? null
                : (string) $this->auditId,
            'occurred_at' => $this->occurredAt
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.u\Z'),
        ];

        if ($this->requestId !== null) {
            $context['request_id'] = $this->requestId;
        }

        return $context;
    }
}

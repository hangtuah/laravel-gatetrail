<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Events;

use DateTimeImmutable;
use HangTuah\GateTrail\Contracts\GateTrailEvent;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\Mutations\MutationName;
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
 * The accepted mutation vocabulary lives in
 * {@see MutationName}, so an event name, its
 * audit row and a concurrency exhaustion always describe the same mutation.
 *
 * Consumers receive instances and never construct them.
 *
 * @see docs/decisions/0010-after-commit-mutation-events.md
 */
final readonly class MutationCommitted implements GateTrailEvent
{
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
        if (! MutationName::isKnown($mutation)) {
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
        return SafeMutationContext::build(
            mutation: $this->mutation,
            outcome: $this->outcome,
            targetType: $this->targetType,
            targetId: $this->targetId,
            subjectType: $this->subjectType,
            subjectId: $this->subjectId,
            actorType: $this->actorType,
            actorId: $this->actorId,
            auditId: $this->auditId,
            occurredAt: $this->occurredAt,
            requestId: $this->requestId,
        );
    }
}

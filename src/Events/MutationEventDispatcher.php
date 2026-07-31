<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Events;

use HangTuah\GateTrail\Audit\PendingAuditRecord;
use HangTuah\GateTrail\MutationOutcome;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Builds and dispatches one {@see MutationCommitted} per committed mutation.
 *
 * Every field the event needs already exists on the mutation's canonical audit
 * record, so the two always describe the same facts and cannot drift.
 *
 * Dispatch runs after the mutation is durable. A listener that throws
 * therefore cannot be allowed to surface as a failure from a manager method
 * that already succeeded, because the natural consumer response — retry the
 * mutation — would duplicate authorization state. Listener failures are caught
 * and reported operationally instead.
 *
 * @internal
 */
final readonly class MutationEventDispatcher
{
    public const MESSAGE = 'GateTrail mutation event dispatch failed.';

    public function __construct(
        private Dispatcher $events,
        private LoggerInterface $logger,
    ) {}

    public function dispatch(
        PendingAuditRecord $record,
        MutationOutcome $outcome,
        int|string|null $auditId,
    ): void {
        $event = MutationCommitted::record(
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

        try {
            $this->events->dispatch($event);
        } catch (Throwable $failure) {
            // Laravel runs synchronous listeners in sequence and a throw
            // aborts the rest, so later listeners for this event do not run.
            // GateTrail cannot isolate them without replacing the dispatcher.
            $this->report($event, $failure);
        }
    }

    /**
     * Records the failure without leaking anything the event itself excludes.
     * Only the exception's class is added: no message, no trace.
     */
    private function report(
        MutationCommitted $event,
        Throwable $failure,
    ): void {
        try {
            $this->logger->error(
                self::MESSAGE,
                $event->context() + ['exception' => $failure::class],
            );
        } catch (Throwable) {
            // The mutation is already committed. A broken log channel must
            // not surface as an application error, and dispatch is never
            // retried.
        }
    }
}

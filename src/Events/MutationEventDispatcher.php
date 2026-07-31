<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Events;

use Closure;
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
 * Everything here runs after the mutation is durable, so nothing here may
 * throw. A listener that fails, and an event that cannot even be constructed
 * because a package invariant was violated, would both otherwise surface as a
 * failure from a manager method that already succeeded — and the natural
 * consumer response, retrying the mutation, would duplicate authorization
 * state. Both are contained and reported operationally instead.
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
        try {
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
        } catch (Throwable $failure) {
            // An unknown mutation name or a violated event invariant is a
            // package bug, but the mutation is already committed. Report it
            // from the audit record's safe scalars and dispatch nothing.
            $this->report(
                static fn (): array => SafeMutationContext::fromRecord(
                    $record,
                    $outcome,
                    $auditId,
                ),
                $failure,
            );

            return;
        }

        try {
            $this->events->dispatch($event);
        } catch (Throwable $failure) {
            // Laravel runs synchronous listeners in sequence and a throw
            // aborts the rest, so later listeners for this event do not run.
            // GateTrail cannot isolate them without replacing the dispatcher.
            $this->report(
                static fn (): array => $event->context(),
                $failure,
            );
        }
    }

    /**
     * Records the failure without leaking anything the event itself excludes.
     * Only the exception's class is added: no message, no trace.
     *
     * Building the context is inside the guard as well, so even a diagnostic
     * that cannot be assembled cannot break a committed mutation.
     *
     * @param  Closure(): array<string, mixed>  $context
     */
    private function report(Closure $context, Throwable $failure): void
    {
        try {
            $this->logger->error(
                self::MESSAGE,
                $context() + ['exception' => $failure::class],
            );
        } catch (Throwable) {
            // The mutation is already committed. A broken log channel must
            // not surface as an application error, and neither the event nor
            // the mutation is ever retried.
        }
    }
}

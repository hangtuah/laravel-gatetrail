<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Audit;

use HangTuah\GateTrail\Events\AuditPersistenceFailed;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reports a non-strict audit failure operationally.
 *
 * Reporting is deliberately best effort. By the time it runs the mutation is
 * already committed, so a broken logger or a throwing listener must never turn
 * a successful mutation into a thrown application error.
 *
 * @internal
 */
final readonly class AuditFailureReporter
{
    public const MESSAGE = 'GateTrail non-strict audit persistence failed.';

    public function __construct(
        private LoggerInterface $logger,
        private Dispatcher $events,
    ) {}

    public function report(
        PendingAuditRecord $record,
        Throwable $failure,
    ): void {
        $event = AuditPersistenceFailed::forRecord($record, $failure);

        try {
            $this->logger->error(self::MESSAGE, $event->context());
        } catch (Throwable) {
            // The mutation is already committed. A logging failure must not
            // surface as an application error, and it must not prevent the
            // operational event below.
        }

        try {
            $this->events->dispatch($event);
        } catch (Throwable) {
            // Same contract: a throwing listener cannot invalidate a mutation
            // that has already committed.
        }
    }
}

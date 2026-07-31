<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Events;

use HangTuah\GateTrail\Audit\PendingAuditRecord;
use HangTuah\GateTrail\Exceptions\AuditWriteFailed;
use Throwable;

/**
 * Operational event dispatched when a non-strict audit write fails after its
 * mutation has already committed.
 *
 * This is not the successful mutation event. It exists so operators can detect
 * that a mutation succeeded without audit evidence.
 *
 * The payload carries safe diagnostics only. Snapshots, context, consumer
 * metadata, reasons, credentials, exception messages, and stack traces are
 * deliberately excluded: this event travels to logs, queues, and external
 * monitoring, none of which are audit storage.
 */
final readonly class AuditPersistenceFailed
{
    public function __construct(
        public string $event,
        public string $targetType,
        public int|string $targetId,
        public ?string $subjectType,
        public int|string|null $subjectId,
        public ?string $requestId,
        public string $exceptionClass,
    ) {}

    public static function forRecord(
        PendingAuditRecord $record,
        Throwable $failure,
    ): self {
        return new self(
            event: $record->event,
            targetType: $record->targetType,
            targetId: $record->targetId,
            subjectType: $record->subjectType,
            subjectId: $record->subjectId,
            requestId: $record->requestId,
            exceptionClass: self::cause($failure),
        );
    }

    /**
     * The safe diagnostic payload shared by the operational log.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        $context = [
            'event' => $this->event,
            'target_type' => $this->targetType,
            'target_id' => (string) $this->targetId,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId === null
                ? null
                : (string) $this->subjectId,
            'exception' => $this->exceptionClass,
        ];

        if ($this->requestId !== null) {
            $context['request_id'] = $this->requestId;
        }

        return $context;
    }

    /**
     * Reports the underlying cause rather than GateTrail's own wrapper, which
     * would otherwise be the only class ever recorded. Class names carry no
     * user data; messages and traces are never included.
     */
    private static function cause(Throwable $failure): string
    {
        $previous = $failure->getPrevious();

        return $failure instanceof AuditWriteFailed && $previous !== null
            ? $previous::class
            : $failure::class;
    }
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Audit;

use HangTuah\GateTrail\Exceptions\AuditWriteFailed;
use HangTuah\GateTrail\Support\ModelRegistry;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * @internal
 */
final readonly class AccessAuditWriter
{
    public function __construct(
        private ModelRegistry $models,
    ) {}

    public function append(PendingAuditRecord $record): int|string
    {
        $class = $this->models->accessAudit();
        $audit = new $class;
        $connection = $audit->getConnection();

        if ($connection->transactionLevel() === 0) {
            throw new LogicException(
                'GateTrail access audits must be written inside a database transaction.',
            );
        }

        try {
            $audit->setRawAttributes($record->databaseAttributes());
            $audit->save();
            $identifier = $audit->getKey();

            if (! is_int($identifier) && ! is_string($identifier)) {
                throw new RuntimeException(
                    'The persisted GateTrail audit has no identifier.',
                );
            }

            return $identifier;
        } catch (Throwable $exception) {
            throw AuditWriteFailed::forEvent(
                $record->event,
                $exception,
            );
        }
    }
}

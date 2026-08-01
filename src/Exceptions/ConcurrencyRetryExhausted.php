<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Exceptions;

use HangTuah\GateTrail\Concurrency\ClassifiedConcurrencyFailure;
use HangTuah\GateTrail\Mutations\MutationName;
use InvalidArgumentException;
use RuntimeException;

/**
 * Reports an assignment mutation that could not complete under concurrent
 * load.
 *
 * It is raised only for a positively classified transient failure — never for
 * a domain outcome, and never for an unclassified infrastructure failure,
 * which propagates unchanged with its own chain intact.
 *
 * `getPrevious()` is always null. The driver exception is classified into a
 * {@see ClassifiedConcurrencyFailure} and then dropped, so no `Throwable`
 * derived from it — and therefore no SQL, binding or trace argument — can
 * reach a consumer through this exception. What remains is four safe scalars
 * describing the failure, alongside the operation, the attempts actually made,
 * and whether retry was unavailable because a caller owned the transaction.
 *
 * @see docs/decisions/0011-bounded-concurrency-retry.md
 */
final class ConcurrencyRetryExhausted extends RuntimeException
{
    private function __construct(
        public readonly string $operation,
        public readonly int $attempts,
        public readonly bool $callerOwned,
        public readonly string $driver,
        public readonly string $category,
        public readonly ?string $sqlState,
        public readonly ?int $vendorCode,
        string $message,
    ) {
        // No previous exception, deliberately and permanently.
        parent::__construct($message);
    }

    /**
     * GateTrail owned the transaction and used every attempt it was allowed.
     */
    public static function afterAttempts(
        string $operation,
        int $attempts,
        ClassifiedConcurrencyFailure $failure,
    ): self {
        self::assertOperation($operation);

        if ($attempts < 1) {
            throw new InvalidArgumentException(
                'GateTrail cannot report fewer than one concurrency attempt.',
            );
        }

        return new self(
            $operation,
            $attempts,
            false,
            $failure->driver,
            $failure->category,
            $failure->sqlState,
            $failure->vendorCode,
            sprintf(
                'GateTrail could not complete [%s] after %d concurrent attempts.',
                $operation,
                $attempts,
            ),
        );
    }

    /**
     * A caller owned the transaction, so only one attempt was possible.
     *
     * GateTrail cannot restart a transaction it does not own: replaying a
     * savepoint would not restore the isolation snapshot, release the caller's
     * earlier locks, or undo work performed before the call.
     */
    public static function insideCallerTransaction(
        string $operation,
        ClassifiedConcurrencyFailure $failure,
    ): self {
        self::assertOperation($operation);

        return new self(
            $operation,
            1,
            true,
            $failure->driver,
            $failure->category,
            $failure->sqlState,
            $failure->vendorCode,
            sprintf(
                'GateTrail could not complete [%s] inside a caller-owned transaction under concurrent load.',
                $operation,
            ),
        );
    }

    /**
     * Only the six coordinated assignment mutations can exhaust retry, so a
     * definition name or a typo is a package bug rather than a load signal.
     */
    private static function assertOperation(string $operation): void
    {
        if (! MutationName::isAssignment($operation)) {
            throw new InvalidArgumentException(
                'GateTrail concurrency exhaustion requires a coordinated assignment mutation.',
            );
        }
    }
}

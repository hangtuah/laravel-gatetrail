<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Exceptions;

use RuntimeException;

/**
 * Reports a caller-owned transaction that cannot defer a non-strict audit
 * until its outermost commit.
 *
 * GateTrail rejects that combination before mutating anything rather than
 * silently writing the audit inside the caller's transaction, which would
 * make an outer rollback discard a mutation while keeping evidence of it, or
 * the reverse.
 */
final class UnsupportedAuditBoundary extends RuntimeException
{
    private function __construct(
        public readonly ?string $connection,
    ) {
        parent::__construct(
            $connection === null
                ? 'The GateTrail connection cannot defer a non-strict audit until its outermost commit.'
                : sprintf(
                    'GateTrail connection [%s] cannot defer a non-strict audit until its outermost commit.',
                    $connection,
                ),
        );
    }

    /**
     * An unnamed connection reports no name rather than an invented one.
     */
    public static function forConnection(?string $connection): self
    {
        return new self($connection);
    }
}

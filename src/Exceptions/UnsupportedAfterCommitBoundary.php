<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Exceptions;

use RuntimeException;

/**
 * Reports a caller-owned transaction that cannot defer GateTrail's
 * after-commit work until its outermost commit.
 *
 * That work is a non-strict audit write, the future cache invalidation, and
 * the successful mutation event, so the requirement applies in strict,
 * non-strict, and disabled audit modes alike. GateTrail rejects an
 * unverifiable boundary before mutating anything rather than running those
 * side effects inside the caller's transaction, which would make an outer
 * rollback discard a mutation while keeping evidence of it, or the reverse.
 */
final class UnsupportedAfterCommitBoundary extends RuntimeException
{
    private function __construct(
        public readonly ?string $connection,
    ) {
        parent::__construct(
            $connection === null
                ? 'The GateTrail connection cannot defer after-commit work until its outermost commit.'
                : sprintf(
                    'GateTrail connection [%s] cannot defer after-commit work until its outermost commit.',
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

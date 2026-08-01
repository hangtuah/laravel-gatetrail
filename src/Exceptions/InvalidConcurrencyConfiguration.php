<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Exceptions;

use InvalidArgumentException;

/**
 * Reports an `assignments.concurrency_attempts` value GateTrail refuses to
 * interpret.
 *
 * Bounded retry is a concurrency-safety control, so a malformed value fails
 * closed instead of being coerced. Only the configuration key is reported: the
 * rejected value is consumer-supplied and does not belong in an exception
 * message, a log, or a stack trace.
 *
 * @see docs/decisions/0011-bounded-concurrency-retry.md
 */
final class InvalidConcurrencyConfiguration extends InvalidArgumentException
{
    private function __construct(
        public readonly string $key,
    ) {
        parent::__construct(
            sprintf(
                'GateTrail configuration [%s] must be an integer between 1 and 10.',
                $key,
            ),
        );
    }

    public static function forKey(string $key): self
    {
        return new self($key);
    }
}

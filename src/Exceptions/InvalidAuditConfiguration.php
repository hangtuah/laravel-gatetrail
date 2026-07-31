<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Exceptions;

use InvalidArgumentException;

/**
 * Reports an audit-mode configuration value GateTrail refuses to interpret.
 *
 * Auditing is a security control, so a malformed flag fails closed instead of
 * being coerced. Only the configuration key is reported: the rejected value is
 * consumer-supplied and may carry data that does not belong in an exception
 * message, a log, or a stack trace.
 */
final class InvalidAuditConfiguration extends InvalidArgumentException
{
    private function __construct(
        public readonly string $key,
    ) {
        parent::__construct(
            sprintf(
                'GateTrail configuration [%s] must be a boolean.',
                $key,
            ),
        );
    }

    public static function forKey(string $key): self
    {
        return new self($key);
    }
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Concurrency;

use HangTuah\GateTrail\Exceptions\InvalidConcurrencyConfiguration;
use Illuminate\Contracts\Config\Repository;

/**
 * Resolves the bounded attempt count for assignment coordination retry.
 *
 * The value must be a real integer between 1 and 10. Nothing is coerced,
 * because every plausible coercion silently changes a concurrency-safety
 * control: a truthy string would become an arbitrary attempt count, and a
 * falsy one would disable retry. A missing or null key is invalid rather than
 * defaulted, so a partially published configuration file fails loudly instead
 * of quietly adopting a different limit from the one the operator can see.
 *
 * The ceiling exists because every attempt is a full transaction holding
 * coordination and row locks, so a large limit turns a contended scope into a
 * retry storm that starves the writer it is waiting for.
 *
 * This class knows only about configuration. It knows nothing about mutation
 * names, database drivers, SQLSTATE values, driver exceptions, or the retry
 * loop that will consume its answer.
 *
 * @internal
 *
 * @see docs/decisions/0011-bounded-concurrency-retry.md
 */
final readonly class RetryPolicy
{
    public const KEY = 'gatetrail.assignments.concurrency_attempts';

    private const MINIMUM = 1;

    private const MAXIMUM = 10;

    public function __construct(
        private Repository $config,
    ) {}

    public function attempts(): int
    {
        $value = $this->config->get(self::KEY);

        // is_int() already rejects booleans, floats and numeric strings.
        if (
            ! is_int($value)
            || $value < self::MINIMUM
            || $value > self::MAXIMUM
        ) {
            throw InvalidConcurrencyConfiguration::forKey(self::KEY);
        }

        return $value;
    }
}

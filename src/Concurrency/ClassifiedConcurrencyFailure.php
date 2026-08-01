<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Concurrency;

use HangTuah\GateTrail\Exceptions\ConcurrencyRetryExhausted;
use InvalidArgumentException;

/**
 * One positively classified transient database failure, reduced to safe
 * scalars.
 *
 * This is deliberately **not** a `Throwable`. Every `Throwable` carries its
 * own stack trace, and whether that trace retains function arguments depends
 * on runtime configuration such as `zend.exception_ignore_args`, which
 * GateTrail does not control. A plain immutable value constructed away from
 * the driver exception is the only way to promise that no reference to the
 * classification input survives, and it is what lets
 * {@see ConcurrencyRetryExhausted} have an
 * empty previous chain.
 *
 * It stores exactly four scalars and nothing else: no `QueryException`, no
 * `Throwable`, no closure, no SQL, no bindings, no driver message, no stack,
 * no table, constraint, column or identifier, no model, no reason, no
 * metadata, and no credentials.
 *
 * @internal
 *
 * @see docs/decisions/0011-bounded-concurrency-retry.md
 */
final readonly class ClassifiedConcurrencyFailure
{
    /**
     * The database drivers GateTrail supports.
     *
     * @var list<string>
     */
    private const DRIVERS = [
        'mysql',
        'pgsql',
        'sqlsrv',
        'oracle',
        'sqlite',
    ];

    /**
     * The failure categories ADR 0011 accepts as retryable.
     *
     * @var list<string>
     */
    private const CATEGORIES = [
        'coordination_race',
        'deadlock',
        'serialization_failure',
    ];

    private function __construct(
        public string $driver,
        public string $category,
        public ?string $sqlState,
        public ?int $vendorCode,
    ) {}

    /**
     * Builds a classified failure from already-safe scalar diagnostics.
     *
     * A classifier calls this only after it has positively identified the
     * failure. The driver exception is read, classified, and then allowed to
     * fall out of scope; it is never passed here.
     */
    public static function classified(
        string $driver,
        string $category,
        ?string $sqlState = null,
        ?int $vendorCode = null,
    ): self {
        if (! in_array($driver, self::DRIVERS, true)) {
            throw new InvalidArgumentException(
                'GateTrail cannot classify a concurrency failure for an unsupported driver.',
            );
        }

        if (! in_array($category, self::CATEGORIES, true)) {
            throw new InvalidArgumentException(
                'GateTrail cannot classify an unsupported concurrency failure category.',
            );
        }

        // SQLSTATE is exactly five alphanumeric characters by the SQL
        // standard. Requiring the canonical form keeps driver prose out of a
        // field that is meant to be a code.
        if (
            $sqlState !== null
            && preg_match('/^[0-9A-Za-z]{5}$/D', $sqlState) !== 1
        ) {
            throw new InvalidArgumentException(
                'GateTrail concurrency SQLSTATE must be five alphanumeric characters.',
            );
        }

        return new self($driver, $category, $sqlState, $vendorCode);
    }
}

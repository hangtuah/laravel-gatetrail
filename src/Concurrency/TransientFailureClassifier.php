<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Concurrency;

use HangTuah\GateTrail\Database\Schema\SchemaAdapterFactory;
use HangTuah\GateTrail\Exceptions\UnsupportedDatabaseDriver;
use Illuminate\Database\QueryException;

/**
 * Classifies deadlock and serialization-failure rollbacks from the structured
 * SQLSTATE and vendor code a driver reports.
 *
 * These are the two conditions the database itself resolved by rolling **this**
 * transaction back, which is why they need no identity anchor: whatever
 * statement raised them, the attempt is gone and a fresh attempt is a fresh
 * transaction. That is the entire justification for classifying them on codes
 * alone, and it is why nothing else may be added here on the same reasoning.
 *
 * Classification is driver-aware. A bare `40001` is not sufficient everywhere:
 * PostgreSQL reserves it for serialization failure with no useful vendor code,
 * while MySQL and SQL Server pair it with a specific vendor code that
 * distinguishes a deadlock victim from a lock-wait timeout wearing a similar
 * SQLSTATE. Where ADR 0011 requires the vendor code to agree, it must agree
 * exactly.
 *
 * Deliberately excluded, per ADR 0011: lock wait timeouts on every driver,
 * SQLite `BUSY` and `LOCKED`, every unique, foreign-key, NOT NULL and check
 * violation, and every syntax, missing-table, permission, authentication and
 * connectivity failure. Those either need bounded backoff GateTrail does not
 * yet have, or are deterministic and would fail identically on a retry.
 *
 * No rule reads driver prose. Deadlock and serialization messages are
 * localized and rewritten between versions; SQLSTATE and vendor code are not.
 *
 * This class knows nothing about coordination rows, attempt counting or
 * transaction orchestration, and it stores no exception, message or driver
 * text in any property.
 *
 * @internal
 *
 * @see docs/decisions/0011-bounded-concurrency-retry.md
 */
final readonly class TransientFailureClassifier
{
    public function __construct(
        private SchemaAdapterFactory $adapters,
    ) {}

    /**
     * Returns the classified failure, or null when the exception is not a
     * confirmed deadlock or serialization rollback.
     *
     * The `QueryException` is read and then allowed to fall out of scope. It is
     * never stored, chained, logged, rethrown or returned.
     *
     * @param  string  $driver  A connection driver name. Aliases are canonicalized
     *                          through the schema adapters, so `mariadb` resolves
     *                          to `mysql` and `oci8` to `oracle`.
     *
     * @throws UnsupportedDatabaseDriver When the driver is not one GateTrail
     *                                   supports. An unsupported driver is not
     *                                   guessed at and is not silently treated
     *                                   as unclassified.
     */
    public function classify(
        QueryException $exception,
        string $driver,
    ): ?ClassifiedConcurrencyFailure {
        $canonical = $this->adapters->forDriver($driver)->driver();

        $sqlState = DriverDiagnostics::sqlState($exception);

        if ($sqlState === null) {
            return null;
        }

        $vendorCode = DriverDiagnostics::vendorCode($exception);

        $category = $this->category($canonical, $sqlState, $vendorCode);

        if ($category === null) {
            return null;
        }

        return ClassifiedConcurrencyFailure::classified(
            $canonical,
            $category,
            $sqlState,
            $vendorCode,
        );
    }

    /**
     * The accepted rows of ADR 0011's deadlock and serialization matrix.
     *
     * SQLite is absent on purpose. Its contention surface is `BUSY` and
     * `LOCKED`, both of which mean a competing writer still holds the lock, so
     * recovering from them needs bounded backoff rather than an immediate
     * retry. Neither is retryable in this increment.
     */
    private function category(
        string $driver,
        string $sqlState,
        ?int $vendorCode,
    ): ?string {
        return match ($driver) {
            // PostgreSQL reports its vendor code as a libpq error class that
            // carries no identity, so the SQLSTATE alone decides here.
            'pgsql' => match ($sqlState) {
                '40001' => 'serialization_failure',
                '40P01' => 'deadlock',
                default => null,
            },
            'mysql' => $sqlState === '40001' && $vendorCode === 1213
                ? 'deadlock'
                : null,
            'sqlsrv' => $sqlState === '40001' && $vendorCode === 1205
                ? 'deadlock'
                : null,
            'oracle' => match (true) {
                $sqlState === '61000' && $vendorCode === 60 => 'deadlock',
                $sqlState === '72000' && $vendorCode === 8177 => 'serialization_failure',
                default => null,
            },
            default => null,
        };
    }
}

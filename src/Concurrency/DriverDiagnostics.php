<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Concurrency;

use Illuminate\Database\QueryException;

/**
 * Reads the three structured PDO diagnostic fields from a `QueryException`.
 *
 * This has exactly one responsibility: turning `errorInfo` into safe, canonical
 * values, or `null` when the driver did not supply one in the expected shape.
 * It decides nothing. It does not know what a coordination race is, what a
 * deadlock is, which drivers exist, or that retry exists at all, and it is
 * deliberately not the place where terminal-constraint translation and retry
 * classification could grow into one another.
 *
 * It holds no state: every method is static, nothing is constructed, and no
 * `QueryException`, message, SQL or binding is ever stored anywhere.
 *
 * @internal
 *
 * @see docs/decisions/0011-bounded-concurrency-retry.md
 */
final class DriverDiagnostics
{
    private function __construct() {}

    /**
     * The canonical SQLSTATE, or null when the driver did not report one in
     * the standard five-character uppercase alphanumeric form.
     *
     * Nothing is uppercased or trimmed. A value that is not already canonical
     * is not a SQLSTATE GateTrail will classify on, and quietly repairing it
     * would mean classifying a retry decision from a value no driver produced.
     */
    public static function sqlState(QueryException $exception): ?string
    {
        $value = $exception->errorInfo[0] ?? null;

        if (! is_string($value)) {
            return null;
        }

        return preg_match('/^[0-9A-Z]{5}$/D', $value) === 1 ? $value : null;
    }

    /**
     * The numeric vendor code, or null when the driver did not report one as a
     * real integer or a canonical decimal string.
     *
     * Drivers are inconsistent here: some hand PHP an `int`, others a decimal
     * string of the same number. Both are accepted, and nothing else is. A
     * sign, surrounding whitespace, a decimal point, exponent notation, a hex
     * literal, a leading zero and a value too large for a PHP integer are each
     * rejected, because every one of them means the field did not carry the
     * plain vendor code the classification matrix compares against.
     */
    public static function vendorCode(QueryException $exception): ?int
    {
        $value = $exception->errorInfo[1] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1) {
            return null;
        }

        // A round trip rejects both a non-canonical leading zero and a value
        // that silently saturated at PHP_INT_MAX during the cast.
        $code = (int) $value;

        return (string) $code === $value ? $code : null;
    }

    /**
     * The driver's own diagnostic text — `errorInfo[2]` and nothing else.
     *
     * This is deliberately not `QueryException::getMessage()`. Laravel's
     * message wraps the driver text together with the executed SQL and, in
     * several versions, its bindings, which can carry a subject identifier, an
     * assignment reason or consumer metadata. Identity confirmation reads the
     * narrow driver field so that no classification rule can ever match
     * against a value GateTrail was asked to store.
     */
    public static function text(QueryException $exception): ?string
    {
        $value = $exception->errorInfo[2] ?? null;

        return is_string($value) ? $value : null;
    }
}

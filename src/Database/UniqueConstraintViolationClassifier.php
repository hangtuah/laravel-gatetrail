<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * @internal
 */
final readonly class UniqueConstraintViolationClassifier
{
    /**
     * Confirms that the exception is a unique-constraint violation of one
     * specific named constraint. SQLite reports violated columns instead of
     * the constraint name, so the exact table/column identity is matched
     * there. Every other integrity failure is left untranslated.
     *
     * @param  list<string>  $columns
     */
    public function matchesConstraint(
        QueryException $exception,
        string $constraint,
        string $table,
        array $columns,
    ): bool {
        if (! $this->isUniqueViolation($exception)) {
            return false;
        }

        if ($this->mentionsConstraint($exception, $constraint)) {
            return true;
        }

        return $this->matchesReportedColumns(
            $exception,
            $table,
            $columns,
        );
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        if ($exception instanceof UniqueConstraintViolationException) {
            return true;
        }

        $sqlState = $this->sqlState($exception);
        $driverCode = $this->driverCode($exception);

        if ($sqlState === '23505') {
            return true;
        }

        if (
            $sqlState === '23000'
            && in_array($driverCode, [1062, 1586, 2601, 2627], true)
        ) {
            return true;
        }

        if (
            $sqlState === '23000'
            && $driverCode === 19
            && str_contains(
                $exception->getMessage(),
                'UNIQUE constraint failed',
            )
        ) {
            return true;
        }

        return $driverCode === 1
            && str_contains($exception->getMessage(), 'ORA-00001');
    }

    private function mentionsConstraint(
        QueryException $exception,
        string $constraint,
    ): bool {
        $pattern = sprintf(
            '/(?<![A-Za-z0-9_])%s(?![A-Za-z0-9_])/i',
            preg_quote($constraint, '/'),
        );

        return preg_match($pattern, $exception->getMessage()) === 1;
    }

    /**
     * @param  list<string>  $columns
     */
    private function matchesReportedColumns(
        QueryException $exception,
        string $table,
        array $columns,
    ): bool {
        $listed = preg_match(
            '/UNIQUE constraint failed: ([\w".]+(?:,\s*[\w".]+)*)/',
            $exception->getMessage(),
            $matches,
        );

        if ($listed !== 1) {
            return false;
        }

        $reported = array_map(
            static fn (string $token): string => trim($token, ' "'),
            explode(',', $matches[1]),
        );
        $expected = array_map(
            static fn (string $column): string => $table.'.'.$column,
            $columns,
        );
        sort($reported);
        sort($expected);

        return $reported === $expected;
    }

    private function sqlState(QueryException $exception): ?string
    {
        $value = $exception->errorInfo[0] ?? null;

        return is_string($value) ? $value : null;
    }

    private function driverCode(QueryException $exception): ?int
    {
        $value = $exception->errorInfo[1] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/D', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}

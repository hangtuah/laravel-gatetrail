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
    public function isUniqueViolation(QueryException $exception): bool
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

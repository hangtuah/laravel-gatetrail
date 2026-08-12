<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Fixtures;

use Closure;
use Illuminate\Database\Connection;

/**
 * Makes the coordination-row `INSERT` lose a real race, a bounded number of
 * times.
 *
 * The seam is `Connection::beforeExecuting()`, which fires immediately before a
 * statement runs. When the statement about to run is the coordination insert,
 * this plants the very row that insert is about to write. The store's own
 * insert then hits a genuine duplicate-key failure raised by the real database
 * engine, carrying the real SQLSTATE, the real vendor code and the real
 * diagnostic text for the real lock table — at exactly the statement position
 * ADR 0011 scopes classification to.
 *
 * That matters: nothing here fakes a classification or stubs a classifier. The
 * production `CoordinationRaceClassifier` reads the driver's own diagnostics
 * and decides for itself, so these tests exercise the same path a lost race
 * takes in production.
 *
 * Planting inside the attempt's transaction means the planted row is discarded
 * with it, so the following attempt finds the table empty and proceeds. A real
 * winner would have committed and the loser's retry would find the row and
 * finish as unchanged; both shapes are exercised separately.
 *
 * Nothing weakens production code to make this work: no class loses `final`, no
 * property becomes public, and no method exists only for tests.
 */
final class CoordinationRacePlanter
{
    private int $planted = 0;

    private bool $planting = false;

    /**
     * @param  array<string, mixed>  $row  The exact coordination row to plant.
     * @param  int  $times  How many consecutive inserts should lose.
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly string $table,
        private readonly array $row,
        private readonly int $times = 1,
    ) {}

    public function attach(): self
    {
        $this->connection->beforeExecuting($this->planter());

        return $this;
    }

    /**
     * How many inserts have actually been made to lose.
     */
    public function planted(): int
    {
        return $this->planted;
    }

    private function planter(): Closure
    {
        return function (string $query): void {
            if (
                $this->planting
                || $this->planted >= $this->times
                || ! $this->targetsCoordinationInsert($query)
            ) {
                return;
            }

            // The plant is itself an insert into the same table, so guard
            // against re-entering this callback.
            $this->planting = true;

            try {
                $this->connection->table($this->table)->insert($this->row);
                $this->planted++;
            } finally {
                $this->planting = false;
            }
        };
    }

    private function targetsCoordinationInsert(string $query): bool
    {
        return str_starts_with($query, 'insert into')
            && str_contains($query, $this->table);
    }
}

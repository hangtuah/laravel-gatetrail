<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations;

use Closure;
use HangTuah\GateTrail\Support\ModelRegistry;
use Illuminate\Database\Connection;

/**
 * Runs one closure inside a single-attempt transaction on the configured
 * access-audit connection.
 *
 * This is the lowest-level transaction primitive. It never retries, so a
 * caller can rely on its closure running exactly once.
 *
 * @internal
 */
final readonly class StrictMutationTransaction
{
    public function __construct(
        private ModelRegistry $models,
    ) {}

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function run(Closure $operation): mixed
    {
        return $this->connection()->transaction(
            static fn (): mixed => $operation(),
            1,
        );
    }

    /**
     * The connection every GateTrail mutation and audit write shares.
     */
    public function connection(): Connection
    {
        $class = $this->models->accessAudit();

        return (new $class)->getConnection();
    }
}

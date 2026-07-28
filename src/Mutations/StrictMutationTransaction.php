<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations;

use Closure;
use HangTuah\GateTrail\Support\ModelRegistry;

/**
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
        $class = $this->models->accessAudit();
        $connection = (new $class)->getConnection();

        return $connection->transaction(
            static fn (): mixed => $operation(),
            1,
        );
    }
}

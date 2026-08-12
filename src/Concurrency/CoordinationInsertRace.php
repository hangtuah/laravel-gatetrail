<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Concurrency;

use HangTuah\GateTrail\Exceptions\ConcurrencyRetryExhausted;
use InvalidArgumentException;
use RuntimeException;

/**
 * The internal control-flow signal that the assignment-lock coordination
 * `INSERT` lost a confirmed transient race.
 *
 * # Inert in this increment
 *
 * **Nothing in GateTrail throws this yet, and nothing may.** The retry
 * orchestrator that catches it does not exist, so a marker thrown today would
 * travel straight out of a public manager method as an unhandled internal
 * exception. `AssignmentLockStore` is therefore deliberately not wired: the
 * throw site and the catch site must land together, atomically, in the same
 * change as `runCoordinated()`. Until then this type exists, is tested
 * directly, and is created by nothing on any production path.
 *
 * # Contract once it is live
 *
 * It is control flow, not a domain outcome. It is created only by
 * `AssignmentLockStore`, only from the `catch` around the coordination-row
 * `INSERT` itself, and only after {@see CoordinationRaceClassifier} has
 * confirmed the failure. It is caught by the retry orchestrator and either
 * consumed by a retry or converted into
 * {@see ConcurrencyRetryExhausted}. It never reaches a consumer, is never
 * returned as a `MutationResult`, and is never registered as a container
 * service.
 *
 * It carries exactly one {@see ClassifiedConcurrencyFailure}, whose category
 * must be `coordination_race` — a deadlock or serialization failure is
 * classified elsewhere, is not positionally anchored to the coordination row,
 * and must not be smuggled through this type.
 *
 * It accepts, stores and chains no `QueryException` and no `Throwable`, so
 * `getPrevious()` is always null. Its message is a fixed safe sentence: no
 * SQL, no bindings, no driver prose, no table, constraint, column or other
 * identifier, no reason, no metadata and no credentials. The four safe scalars
 * on its classified failure are the only diagnostics it carries.
 *
 * @internal
 *
 * @see docs/decisions/0011-bounded-concurrency-retry.md
 */
final class CoordinationInsertRace extends RuntimeException
{
    /**
     * The only category this marker may ever carry.
     */
    private const CATEGORY = 'coordination_race';

    /**
     * Fixed, safe, and identical for every race on every driver.
     */
    private const MESSAGE = 'GateTrail lost an assignment coordination race.';

    private function __construct(
        public readonly ClassifiedConcurrencyFailure $failure,
    ) {
        // No previous exception, deliberately and permanently.
        parent::__construct(self::MESSAGE);
    }

    /**
     * The smallest safe way to create the marker: one already-classified
     * failure, and nothing else.
     *
     * There is no constructor that takes a `QueryException`, because there is
     * no point in the design where one should be handed to this type. The
     * driver exception is classified and then allowed to fall out of scope.
     */
    public static function confirmed(
        ClassifiedConcurrencyFailure $failure,
    ): self {
        if ($failure->category !== self::CATEGORY) {
            throw new InvalidArgumentException(
                'GateTrail can only mark a confirmed coordination race.',
            );
        }

        return new self($failure);
    }
}

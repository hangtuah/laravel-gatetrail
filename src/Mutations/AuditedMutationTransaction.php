<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations;

use Closure;
use HangTuah\GateTrail\Audit\AccessAuditWriter;
use HangTuah\GateTrail\Audit\AuditFailureReporter;
use HangTuah\GateTrail\Audit\AuditMode;
use HangTuah\GateTrail\Audit\AuditModeResolver;
use HangTuah\GateTrail\Audit\PendingAuditRecord;
use HangTuah\GateTrail\Events\MutationEventDispatcher;
use HangTuah\GateTrail\Exceptions\UnsupportedAfterCommitBoundary;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\MutationResult;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Applies the configured audit mode to every managed mutation and announces
 * the result once it is durable.
 *
 * Handlers stay mode-agnostic and dispatch-agnostic: they validate, mutate,
 * build canonical snapshots, and return a {@see PreparedMutation}. This class
 * owns the transaction state machine, the audit write, and the after-commit
 * side effects.
 *
 * Strict:
 *   begin -> mutate -> append audit -> commit -> announce. An audit failure
 *   rolls the mutation back, throws `AuditWriteFailed`, and announces nothing.
 *
 * Non-strict, GateTrail owns the outermost transaction:
 *   begin -> mutate -> commit -> append audit in a second transaction ->
 *   announce. An audit failure leaves the mutation committed, reports
 *   operationally, and the announcement carries a null audit identifier.
 *
 * Non-strict, inside a caller-owned transaction:
 *   mutate -> return a null identifier -> at the outermost commit, append the
 *   audit in a new transaction and then announce.
 *
 * Disabled:
 *   begin or join -> mutate -> commit -> announce with a null identifier. No
 *   audit is written and nothing failed, so nothing is reported.
 *
 * Announcing is the reserved cache-invalidation point followed by the
 * successful mutation event. Inside a caller-owned transaction it is one
 * callback rather than several, so ordering never depends on the framework's
 * callback order.
 *
 * @internal
 *
 * @see docs/decisions/0010-after-commit-mutation-events.md
 */
final readonly class AuditedMutationTransaction
{
    public function __construct(
        private AuditModeResolver $modes,
        private StrictMutationTransaction $transaction,
        private AfterCommitBoundary $boundaries,
        private AccessAuditWriter $audits,
        private AuditFailureReporter $failures,
        private MutationEventDispatcher $events,
    ) {}

    /**
     * @template TResource of \Illuminate\Database\Eloquent\Model
     *
     * @param  Closure(): PreparedMutation<TResource>  $operation
     * @return MutationResult<TResource>
     */
    public function run(Closure $operation): MutationResult
    {
        return match ($this->modes->resolve()) {
            AuditMode::Strict => $this->runStrict($operation),
            AuditMode::NonStrict => $this->runNonStrict($operation),
            AuditMode::Disabled => $this->runUnaudited($operation),
        };
    }

    /**
     * @template TResource of \Illuminate\Database\Eloquent\Model
     *
     * @param  Closure(): PreparedMutation<TResource>  $operation
     * @return MutationResult<TResource>
     */
    private function runStrict(Closure $operation): MutationResult
    {
        $connection = $this->begin();

        /** @var array{PreparedMutation<TResource>, int|string|null} $completed */
        $completed = $this->transaction->run(
            function () use ($operation): array {
                $prepared = $operation();

                return [
                    $prepared,
                    $prepared->audit === null
                        ? null
                        : $this->audits->append($prepared->audit),
                ];
            },
        );
        [$prepared, $auditId] = $completed;

        $this->announce(
            $connection,
            $prepared,
            static fn (): int|string|null => $auditId,
        );

        return new MutationResult(
            $prepared->resource,
            $prepared->outcome,
            $auditId,
        );
    }

    /**
     * @template TResource of \Illuminate\Database\Eloquent\Model
     *
     * @param  Closure(): PreparedMutation<TResource>  $operation
     * @return MutationResult<TResource>
     */
    private function runNonStrict(Closure $operation): MutationResult
    {
        $connection = $this->begin();
        $prepared = $this->transaction->run($operation);
        $audit = $prepared->audit;

        if ($audit === null) {
            return new MutationResult(
                $prepared->resource,
                $prepared->outcome,
                null,
            );
        }

        if ($connection->transactionLevel() > 0) {
            // The mutation is still uncommitted inside the caller's
            // transaction, so the audit attempt and the announcement both wait
            // for its outermost commit and this result reports a pending audit
            // as a null identifier.
            $this->announce(
                $connection,
                $prepared,
                fn (): int|string|null => $this->attempt($audit),
            );

            return new MutationResult(
                $prepared->resource,
                $prepared->outcome,
                null,
            );
        }

        $auditId = $this->attempt($audit);

        $this->announce(
            $connection,
            $prepared,
            static fn (): int|string|null => $auditId,
        );

        return new MutationResult(
            $prepared->resource,
            $prepared->outcome,
            $auditId,
        );
    }

    /**
     * Runs the mutation in its normal single-attempt transaction and discards
     * the prepared audit document.
     *
     * Handlers stay mode-agnostic, so they still build a `PendingAuditRecord`.
     * It never reaches {@see AccessAuditWriter} and produces no access-audit
     * statement, but it still describes the change, so the announcement is
     * built from it exactly as in the other modes.
     *
     * @template TResource of \Illuminate\Database\Eloquent\Model
     *
     * @param  Closure(): PreparedMutation<TResource>  $operation
     * @return MutationResult<TResource>
     */
    private function runUnaudited(Closure $operation): MutationResult
    {
        $connection = $this->begin();
        $prepared = $this->transaction->run($operation);

        $this->announce(
            $connection,
            $prepared,
            static fn (): int|string|null => null,
        );

        return new MutationResult(
            $prepared->resource,
            $prepared->outcome,
            null,
        );
    }

    /**
     * Resolves the mutation connection and rejects a caller-owned transaction
     * that cannot carry GateTrail's after-commit work.
     *
     * The check runs before the operation closure, so an unsupported boundary
     * never leaves a mutation inside a caller transaction whose audit, cache
     * invalidation, and event could not follow it. It cannot know yet whether
     * the outcome will be unchanged, so it applies to every caller-owned
     * mutation; that is deliberately the safe direction, and the check itself
     * registers nothing.
     */
    private function begin(): Connection
    {
        $connection = $this->transaction->connection();

        if (
            $connection->transactionLevel() > 0
            && ! $this->boundaries->defersCallbacks($connection)
        ) {
            throw UnsupportedAfterCommitBoundary::forConnection(
                $connection->getName(),
            );
        }

        return $connection;
    }

    /**
     * Runs the reserved cache-invalidation point and dispatches the successful
     * mutation event, immediately or at the caller's outermost commit.
     *
     * An unchanged mutation changed no authorization state, so it resolves no
     * audit identifier, registers no callback, and dispatches nothing.
     *
     * @template TResource of Model
     *
     * @param  PreparedMutation<TResource>  $prepared
     * @param  Closure(): (int|string|null)  $auditId
     */
    private function announce(
        Connection $connection,
        PreparedMutation $prepared,
        Closure $auditId,
    ): void {
        $audit = $prepared->audit;

        if (
            $audit === null
            || $prepared->outcome === MutationOutcome::Unchanged
        ) {
            return;
        }

        $outcome = $prepared->outcome;
        $announce = function () use ($audit, $outcome, $auditId): void {
            $resolved = $auditId();

            // Reserved ordering point. Future after-commit cache invalidation
            // runs here, between audit persistence and event dispatch. No
            // cache behavior exists yet.

            $this->events->dispatch($audit, $outcome, $resolved);
        };

        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit($announce);

            return;
        }

        $announce();
    }

    /**
     * Writes one audit record in its own transaction, reporting rather than
     * rethrowing a failure. The mutation is already committed by this point,
     * so throwing would invite an unsafe retry of a successful mutation.
     */
    private function attempt(PendingAuditRecord $record): int|string|null
    {
        try {
            return $this->transaction->run(
                fn (): int|string => $this->audits->append($record),
            );
        } catch (Throwable $failure) {
            $this->failures->report($record, $failure);

            return null;
        }
    }
}

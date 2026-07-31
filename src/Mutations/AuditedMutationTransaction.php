<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations;

use Closure;
use HangTuah\GateTrail\Audit\AccessAuditWriter;
use HangTuah\GateTrail\Audit\AuditFailureReporter;
use HangTuah\GateTrail\Audit\AuditMode;
use HangTuah\GateTrail\Audit\AuditModeResolver;
use HangTuah\GateTrail\Audit\PendingAuditRecord;
use HangTuah\GateTrail\Exceptions\UnsupportedAuditBoundary;
use HangTuah\GateTrail\MutationResult;
use Illuminate\Database\Connection;
use Throwable;

/**
 * Applies the configured audit mode to every managed mutation.
 *
 * Handlers stay mode-agnostic: they validate, mutate, build canonical
 * snapshots, and return a {@see PreparedMutation}. This class owns the
 * transaction state machine.
 *
 * Strict:
 *   begin -> mutate -> append audit -> commit. An audit failure rolls the
 *   mutation back and throws `AuditWriteFailed`.
 *
 * Non-strict, GateTrail owns the outermost transaction:
 *   begin -> mutate -> commit -> append audit in a second transaction. An
 *   audit failure leaves the mutation committed, returns a null identifier,
 *   and is reported operationally instead of thrown.
 *
 * Non-strict, inside a caller-owned transaction:
 *   verify after-commit support -> mutate -> return a null identifier ->
 *   append the audit in a new transaction once the caller's outermost
 *   transaction commits. A caller rollback discards both.
 *
 * Disabled:
 *   begin or join -> mutate -> commit -> return a null identifier. No audit is
 *   written, no callback is registered, and no after-commit boundary is
 *   required. Nothing failed, so no operational failure is reported either.
 *
 * @internal
 */
final readonly class AuditedMutationTransaction
{
    public function __construct(
        private AuditModeResolver $modes,
        private StrictMutationTransaction $transaction,
        private AfterCommitBoundary $boundaries,
        private AccessAuditWriter $audits,
        private AuditFailureReporter $failures,
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
     * Runs the mutation in its normal single-attempt transaction and discards
     * the prepared audit document.
     *
     * Handlers stay mode-agnostic, so they still build a `PendingAuditRecord`.
     * It is dropped with the closure's stack frame here: it never reaches
     * {@see AccessAuditWriter}, is never captured in a callback, and produces
     * no access-audit statement. Nothing is deferred either, so a caller-owned
     * transaction needs no after-commit boundary and none is checked.
     *
     * @template TResource of \Illuminate\Database\Eloquent\Model
     *
     * @param  Closure(): PreparedMutation<TResource>  $operation
     * @return MutationResult<TResource>
     */
    private function runUnaudited(Closure $operation): MutationResult
    {
        return $this->transaction->run(
            static function () use ($operation): MutationResult {
                $prepared = $operation();

                return new MutationResult(
                    $prepared->resource,
                    $prepared->outcome,
                    null,
                );
            },
        );
    }

    /**
     * @template TResource of \Illuminate\Database\Eloquent\Model
     *
     * @param  Closure(): PreparedMutation<TResource>  $operation
     * @return MutationResult<TResource>
     */
    private function runStrict(Closure $operation): MutationResult
    {
        return $this->transaction->run(
            function () use ($operation): MutationResult {
                $prepared = $operation();

                return new MutationResult(
                    $prepared->resource,
                    $prepared->outcome,
                    $prepared->audit === null
                        ? null
                        : $this->audits->append($prepared->audit),
                );
            },
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
        $connection = $this->transaction->connection();
        $callerOwned = $connection->transactionLevel() > 0;

        if ($callerOwned) {
            // Checked before the operation closure runs, so an unsupported
            // boundary never leaves a mutation inside a caller transaction
            // without a path to evidence. The check registers no callback, so
            // an unchanged result leaves nothing scheduled at all.
            $this->assertAfterCommitSupport($connection);
        }

        $prepared = $this->transaction->run($operation);
        $audit = $prepared->audit;

        if ($audit === null) {
            return new MutationResult(
                $prepared->resource,
                $prepared->outcome,
                null,
            );
        }

        if ($callerOwned) {
            // The mutation is still uncommitted inside the caller's
            // transaction, so the audit waits for its outermost commit and
            // this result reports a pending audit as a null identifier.
            $connection->afterCommit(function () use ($audit): void {
                $this->attempt($audit);
            });

            return new MutationResult(
                $prepared->resource,
                $prepared->outcome,
                null,
            );
        }

        return new MutationResult(
            $prepared->resource,
            $prepared->outcome,
            $this->attempt($audit),
        );
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

    /**
     * Rejects a caller-owned transaction that would not defer the audit to
     * its outermost commit.
     *
     * {@see AfterCommitBoundary} answers this by inspecting transaction state
     * rather than by registering a probe callback, so nothing is scheduled
     * and nothing has to be undone when the mutation turns out unchanged.
     */
    private function assertAfterCommitSupport(Connection $connection): void
    {
        if (! $this->boundaries->defersCallbacks($connection)) {
            throw UnsupportedAuditBoundary::forConnection(
                $connection->getName(),
            );
        }
    }
}

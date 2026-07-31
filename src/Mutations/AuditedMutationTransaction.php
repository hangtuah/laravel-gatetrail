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
use LogicException;
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
 * @internal
 */
final readonly class AuditedMutationTransaction
{
    public function __construct(
        private AuditModeResolver $modes,
        private StrictMutationTransaction $transaction,
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
        $mode = $this->modes->resolve();

        if ($mode === AuditMode::Disabled) {
            throw new LogicException(
                'GateTrail currently requires enabled auditing.',
            );
        }

        return $mode === AuditMode::Strict
            ? $this->runStrict($operation)
            : $this->runNonStrict($operation);
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
            // Checked before the mutation runs, so an unsupported boundary
            // never leaves a committed mutation without a path to evidence.
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
     * Proves the connection defers an after-commit callback to its outermost
     * commit.
     *
     * Laravel runs the callback immediately when the connection has no
     * transaction manager tracking the open transaction, which would write
     * the audit inside the caller's transaction. Registering a no-op probe
     * and observing whether it ran inline tests that behavior directly rather
     * than assuming a method's existence implies the semantics. The probe
     * persists nothing.
     */
    private function assertAfterCommitSupport(Connection $connection): void
    {
        $deferred = true;

        try {
            $connection->afterCommit(
                static function () use (&$deferred): void {
                    $deferred = false;
                },
            );
        } catch (Throwable) {
            throw UnsupportedAuditBoundary::forConnection(
                $connection->getName(),
            );
        }

        if (! $deferred) {
            throw UnsupportedAuditBoundary::forConnection(
                $connection->getName(),
            );
        }
    }
}

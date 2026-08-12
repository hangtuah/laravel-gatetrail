<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Concurrency;

use Closure;
use HangTuah\GateTrail\Exceptions\ConcurrencyRetryExhausted;
use HangTuah\GateTrail\Mutations\StrictMutationTransaction;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

/**
 * Counts attempts, reruns the mutation transaction, and decides exhaustion.
 *
 * It knows nothing about SQLSTATE values, vendor codes, coordination rows or
 * audit modes. Classification is asked of {@see TransientFailureClassifier} and
 * of the confirmed marker the lock store throws; ownership and the attempt
 * ceiling arrive already decided on a {@see CoordinationPlan}. Its whole job is
 * the loop.
 *
 * # What it catches, and nothing else
 *
 * - {@see CoordinationInsertRace}, the internal marker the coordination
 *   `INSERT` throws once its identity is confirmed. It carries safe scalars
 *   only, so there is nothing to classify and nothing to drop.
 * - {@see QueryException}, which is offered to the transient classifier. An
 *   unclassified one is rethrown as the same object with its own chain intact.
 *
 * Everything else — every GateTrail domain exception, `AuditWriteFailed`, an
 * `InvalidArgumentException` from a package invariant, an arbitrary `Throwable`
 * — is never caught here, so it ends the attempt immediately and propagates
 * unchanged. There is deliberately no `catch (Throwable)` anywhere in this
 * class.
 *
 * {@see UnsupportedDatabaseDriver} is a third thing that leaves this class
 * unchanged. Classification asks the schema adapters to canonicalize the
 * driver, and an unsupported one raises that typed failure from inside the
 * `QueryException` handler. It is deliberately not caught: the package already
 * has one answer for an unsupported driver, and pretending the failure was
 * merely unclassified would quietly extend retry semantics to a driver whose
 * error surface GateTrail has never verified.
 *
 * # Raw exception lifetime
 *
 * A classified driver exception is reduced to a
 * {@see ClassifiedConcurrencyFailure} and then explicitly dropped inside the
 * `catch` block, before the loop continues or the terminal exception is built.
 * Nothing retains it: it is not stored on this object, not chained, not logged,
 * not placed in an array, and not passed to
 * {@see ConcurrencyRetryExhausted}. Only the latest classified value survives
 * between attempts, and it holds four safe scalars.
 *
 * This class is a stateless singleton. Every counter, closure and classified
 * value in the loop is a local variable belonging to one call.
 *
 * @internal
 *
 * @see docs/decisions/0011-bounded-concurrency-retry.md
 */
final readonly class RetryOrchestrator
{
    public function __construct(
        private StrictMutationTransaction $transaction,
        private TransientFailureClassifier $classifier,
    ) {}

    /**
     * Runs one complete mutation transaction attempt, retrying only a
     * positively classified transient failure and only while GateTrail owns
     * the outermost transaction.
     *
     * @template TValue
     *
     * @param  Closure(): TValue  $attempt  The whole transaction-bound operation:
     *                                      reference resolution, coordination
     *                                      acquisition, locking, the decision,
     *                                      persistence and the strict audit
     *                                      write. It reruns in full.
     * @return TValue
     *
     * @throws ConcurrencyRetryExhausted When a classified failure has no
     *                                   attempt left, or retry was never
     *                                   available because a caller owned the
     *                                   transaction.
     */
    public function run(
        CoordinationPlan $plan,
        Connection $connection,
        Closure $attempt,
    ): mixed {
        $permitted = $plan->permittedAttempts();
        $made = 0;

        while (true) {
            $made++;

            try {
                return $this->transaction->run($attempt);
            } catch (CoordinationInsertRace $race) {
                $failure = $race->failure;

                // The marker is internal control flow and must never travel
                // further than this frame.
                unset($race);
            } catch (QueryException $exception) {
                // An unsupported driver raises UnsupportedDatabaseDriver from
                // here and is deliberately allowed to propagate. GateTrail
                // cannot classify a failure on a driver it does not support,
                // and guessing "unclassified" would silently extend retry
                // semantics to a driver whose error surface is unknown. The
                // package's existing typed contract answers instead.
                $classified = $this->classifier->classify(
                    $exception,
                    $connection->getDriverName(),
                );

                if ($classified === null) {
                    // Not retryable, not ours to reinterpret: the original
                    // object propagates with its own chain intact.
                    throw $exception;
                }

                $failure = $classified;

                // Reduced to four safe scalars. Drop the driver exception here,
                // while it is still obvious that nothing below may see it.
                unset($exception, $classified);
            }

            if ($plan->callerOwned) {
                throw ConcurrencyRetryExhausted::insideCallerTransaction(
                    $plan->mutation,
                    $failure,
                );
            }

            if ($made >= $permitted) {
                throw ConcurrencyRetryExhausted::afterAttempts(
                    $plan->mutation,
                    $made,
                    $failure,
                );
            }

            if ($connection->transactionLevel() !== 0) {
                // The failed attempt did not leave the connection where it was
                // found, so a "retry" would run inside the wreckage of the
                // previous one rather than in a fresh transaction. Fail closed
                // and report the classified failure instead of retrying
                // silently on state GateTrail cannot vouch for.
                throw ConcurrencyRetryExhausted::afterAttempts(
                    $plan->mutation,
                    $made,
                    $failure,
                );
            }
        }
    }
}

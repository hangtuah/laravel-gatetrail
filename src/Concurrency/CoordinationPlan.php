<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Concurrency;

use HangTuah\GateTrail\Mutations\MutationName;
use InvalidArgumentException;

/**
 * Everything the retry orchestrator needs to know about one coordinated
 * mutation, decided before any transaction opens.
 *
 * All three values are fixed for the whole invocation. The mutation name is
 * captured from the handler's own vocabulary constant rather than derived from
 * a `PreparedMutation`, because a first attempt can fail during reference
 * resolution and there would then be no prepared mutation to read it from. The
 * attempt ceiling is read once from validated configuration. Ownership is
 * decided once from the connection's transaction level, so a nested savepoint
 * opened by GateTrail's own attempt can never later be mistaken for a caller's
 * transaction.
 *
 * This is a per-invocation value. It is never a container service, and nothing
 * mutable — no counter, no closure, no failure and no exception — lives on it.
 *
 * @internal
 *
 * @see docs/decisions/0011-bounded-concurrency-retry.md
 */
final readonly class CoordinationPlan
{
    /**
     * @param  string  $mutation  One of the six coordinated assignment names.
     * @param  int  $attempts  The validated ceiling from `RetryPolicy`.
     * @param  bool  $callerOwned  True when a caller already owned a transaction.
     */
    public function __construct(
        public string $mutation,
        public int $attempts,
        public bool $callerOwned,
    ) {
        if (! MutationName::isAssignment($mutation)) {
            throw new InvalidArgumentException(
                'GateTrail can only coordinate an assignment mutation.',
            );
        }

        if ($attempts < 1) {
            throw new InvalidArgumentException(
                'GateTrail cannot plan fewer than one concurrency attempt.',
            );
        }
    }

    /**
     * How many attempts this invocation may actually make.
     *
     * A caller-owned transaction gets exactly one, whatever the configuration
     * says. The configured count is permission to restart a transaction
     * GateTrail opened; it is not permission to replay a caller's work.
     */
    public function permittedAttempts(): int
    {
        return $this->callerOwned ? 1 : $this->attempts;
    }
}

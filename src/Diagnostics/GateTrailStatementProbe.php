<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Diagnostics;

use HangTuah\GateTrail\Database\Schema\SchemaContext;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use LogicException;

/**
 * Counts the statements the **GateTrail connection** actually executed.
 *
 * ADR 0012 section 8 rejects a query counter the resolver increments itself:
 * such a counter reports what the algorithm believed it did, and would sit
 * unchanged at 3 while an accidental eager-load, relation touch or `count()`
 * added a fourth statement — precisely the regression a query budget exists to
 * catch. This observes the connection instead, so a budget assertion is a
 * claim about executed SQL rather than about intent.
 *
 * **It records counts and fixed categories only.** SQL text is read inside the
 * event callback, reduced to one of {@see StatementMeasurement}'s fixed
 * categories, and discarded when the callback returns. Nothing here retains
 * SQL, bindings, subject identifiers, definition names, guards, models, rows,
 * durations or the `QueryExecuted` object itself.
 *
 * # Connection filtering
 *
 * `QueryExecuted` is dispatched through the application's shared event
 * dispatcher, which every connection uses. Registering through one connection
 * therefore filters nothing — the callback still sees every connection's
 * statements. Filtering is done on **object identity**:
 *
 * ```php
 * $event->connection === $this->connection
 * ```
 *
 * Identity rather than a matching connection name, because a name is a string
 * that two configurations can share and that a reconfigured manager can reuse,
 * while the resolved `Connection` instance is the one object the resolver's
 * statements actually ran through. The instance itself is resolved exactly as
 * the resolver resolves it — `DatabaseManager::connection()` given
 * `SchemaContext::connectionName()` — so a package installed on its own
 * connection is measured there and the application's default connection is
 * ignored.
 *
 * # Listener lifecycle, stated honestly
 *
 * Laravel offers no safe per-listener detachment, so the listener registered
 * in the constructor **stays on the dispatcher for the life of that
 * dispatcher**. That is accepted rather than worked around, and it is made
 * harmless by design:
 *
 * - the listener is **inert whenever no measurement is active** — it returns
 *   on a single boolean before it looks at anything else;
 * - one probe registers **one** listener and reuses it for every measurement,
 *   so repeated measurement does not accumulate listeners. Repeated
 *   *construction* does, so construct one probe per test or diagnostic run;
 * - the probe is **never registered by `GateTrailServiceProvider`**, never
 *   injected into `EffectiveAccessResolver` or either public resolver, has no
 *   container binding, no configuration key and no facade. It exists only
 *   where a test or a diagnostic explicitly constructs one, and a normal web,
 *   queue or CLI authorization check adds no listener at all.
 *
 * Phase 8's reproducible benchmark is intended to reuse this same mechanism,
 * so "query-count instrumentation" and "benchmark" measure one thing by one
 * means.
 *
 * @internal Test and diagnostic use only. Not consumer API.
 *
 * @see docs/decisions/0012-effective-access-resolution.md section 8
 */
final class GateTrailStatementProbe
{
    /**
     * The closed category vocabulary, doubling as the zeroed tally.
     *
     * A constant rather than a parameter: the categories are fixed by
     * {@see StatementMeasurement}, and a caller-supplied key would be a
     * caller-supplied string retained on this object.
     */
    private const EMPTY_TALLY = [
        'select' => 0,
        'insert' => 0,
        'update' => 0,
        'delete' => 0,
        'schema' => 0,
        'other' => 0,
    ];

    /**
     * The one connection whose statements are counted, resolved through
     * `SchemaContext` and compared by identity.
     */
    private readonly Connection $connection;

    /**
     * The listener's on/off switch. False outside `measure()`, so the
     * permanently registered listener does nothing at all between windows.
     */
    private bool $measuring = false;

    /**
     * @var array<string, int>
     */
    private array $tally = self::EMPTY_TALLY;

    /**
     * Registers exactly one listener, once.
     *
     * The connection is resolved here rather than injected so that every probe
     * measures the same connection the resolver reads — `SchemaContext` is the
     * single place that answers "which connection is GateTrail's?".
     */
    public function __construct(
        DatabaseManager $connections,
        SchemaContext $schema,
        Dispatcher $events,
    ) {
        /** @var Connection $connection */
        $connection = $connections->connection($schema->connectionName());

        $this->connection = $connection;

        $events->listen(QueryExecuted::class, $this->observe(...));
    }

    /**
     * Counts the statements `$work` causes on the GateTrail connection.
     *
     * The window is closed in a `finally`, so an exception cannot leave the
     * listener armed and poison every later measurement in the process. The
     * exception itself propagates **unchanged**: it is not caught, wrapped,
     * logged or copied into a measurement, because a failing query is the
     * caller's problem to see, and its message may carry SQL this class exists
     * to keep out.
     *
     * The closure's return value is deliberately dropped. A measurement is a
     * count, not a result carrier.
     *
     * @param  callable(): mixed  $work
     *
     * @throws LogicException if a measurement is already active on this probe
     */
    public function measure(callable $work): StatementMeasurement
    {
        if ($this->measuring) {
            // Nested windows would silently share one tally, so the inner
            // window's statements would be counted twice and the outer
            // window's budget would be wrong in a way no assertion could see.
            // The message is fixed and names nothing.
            throw new LogicException(
                'A GateTrail statement measurement is already active on this probe.',
            );
        }

        // Reset first, so a measurement never inherits the counts of a window
        // that ended by throwing.
        $this->tally = self::EMPTY_TALLY;
        $this->measuring = true;

        try {
            $work();
        } finally {
            $this->measuring = false;
        }

        return StatementMeasurement::of(
            $this->tally['select'],
            $this->tally['insert'],
            $this->tally['update'],
            $this->tally['delete'],
            $this->tally['schema'],
            $this->tally['other'],
        );
    }

    /**
     * The permanently registered listener.
     *
     * Both guards come before anything reads the event's payload, so between
     * measurements this costs one boolean test and touches no SQL at all.
     */
    private function observe(QueryExecuted $event): void
    {
        if (! $this->measuring) {
            return;
        }

        if ($event->connection !== $this->connection) {
            return;
        }

        $this->tally[self::classify($event->sql)]++;
    }

    /**
     * One fixed category from the leading keyword, and nothing else.
     *
     * Static and returning a category constant, so there is no path by which
     * the string it inspects can reach a property: the argument is a local,
     * the return value is one of six fixed words.
     *
     * Deliberately conservative and deliberately driver-agnostic. It reads the
     * first alphabetic token and nothing more — it parses no identifier, no
     * value and no clause, and it carries no per-engine branch. A statement it
     * does not recognize is `other`, never a guess at `select`, so an
     * unexpected shape breaks a "3 statements, 3 select" budget assertion
     * instead of hiding inside it.
     */
    private static function classify(string $sql): string
    {
        if (preg_match('/^[a-z]+/', strtolower(ltrim($sql)), $matches) !== 1) {
            return 'other';
        }

        return match ($matches[0]) {
            'select' => 'select',
            'insert' => 'insert',
            'update' => 'update',
            'delete' => 'delete',
            'create', 'alter', 'drop', 'truncate', 'rename' => 'schema',
            default => 'other',
        };
    }
}

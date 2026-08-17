<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Access;

/**
 * One scope's memoized evaluations, bounded and least-recently-used.
 *
 * This is a plain in-memory map of immutable snapshots. It holds **no** model,
 * query builder, connection, container, cache store, event dispatcher or
 * closure, so it cannot execute a statement, cannot reach a subject and cannot
 * keep anything alive past its scope.
 *
 * **Scoped, never singleton.** The store dies with its request or queued job.
 * A singleton would serve one request's authorization answers to the next, and
 * a long-lived Octane or queue worker would leak entries for the life of the
 * process — the hazard ADR 0012 section 7.4 exists to prevent.
 *
 * **Bounded at a fixed 64 entries, evicting the least recently used.** The
 * bound matters because a scope is not always short: a queue worker processing
 * a batch, or a console command sweeping thousands of subjects, would otherwise
 * accumulate one entry per (subject, guard, second) tuple until memory ran out.
 * Recency rather than insertion order is used because the hot subject in a
 * request is usually the one checked repeatedly.
 *
 * PHP arrays preserve insertion order, so the array *is* the recency list:
 * re-inserting on read moves an entry to the end, and the first element is
 * always the least recently used. That keeps the bookkeeping to one structure —
 * a second index would be one more thing to leave stale after an eviction.
 *
 * @internal
 *
 * @see docs/decisions/0012-effective-access-resolution.md
 */
final class EffectiveAccessMemo
{
    /**
     * The initial fixed bound, chosen conservatively by maintainers.
     *
     * It is **not configuration** and **not a public guarantee**: it is an
     * internal constant that a future Phase 8 benchmark may raise or lower
     * without any consumer migration, following the posture ADR 0011 took on
     * retry backoff — public configuration surface must be earned by evidence.
     *
     * 64 comfortably covers a request that checks a handful of subjects across
     * a few evaluation seconds, while staying small enough that clearing the
     * whole map on `MutationCommitted` costs nothing measurable.
     */
    private const CAPACITY = 64;

    /**
     * @var array<string, EffectiveAccessSnapshot>
     */
    private array $entries = [];

    /**
     * A hit promotes the entry to most-recently-used, so the bound evicts what
     * the scope has actually stopped asking about.
     */
    public function get(EffectiveAccessMemoKey $key): ?EffectiveAccessSnapshot
    {
        $index = $key->value();

        if (! array_key_exists($index, $this->entries)) {
            return null;
        }

        $snapshot = $this->entries[$index];

        unset($this->entries[$index]);
        $this->entries[$index] = $snapshot;

        return $snapshot;
    }

    /**
     * Storing an existing key replaces it in place rather than growing the
     * map, so a repeated write cannot evict anything.
     */
    public function put(
        EffectiveAccessMemoKey $key,
        EffectiveAccessSnapshot $snapshot,
    ): void {
        $index = $key->value();

        unset($this->entries[$index]);
        $this->entries[$index] = $snapshot;

        // The first element is the least recently used, because every read and
        // every write re-inserts at the end.
        while (count($this->entries) > self::CAPACITY) {
            unset($this->entries[array_key_first($this->entries)]);
        }
    }

    /**
     * Wholesale, because a definition mutation may affect an unknown number of
     * subjects — a deactivated role carries no subject at all yet suspends
     * every path that depends on it. Correctness is preferred over preserving
     * unrelated entries, and there are at most 64 of them.
     */
    public function clear(): void
    {
        $this->entries = [];
    }
}

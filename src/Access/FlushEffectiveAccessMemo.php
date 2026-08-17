<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Access;

use HangTuah\GateTrail\Events\MutationCommitted;
use Illuminate\Contracts\Container\Container;

/**
 * Clears the current scope's memo when a managed mutation commits.
 *
 * **The memo is resolved from the container inside `handle()`, never in the
 * constructor.** That is the whole point of this class. `EffectiveAccessMemo`
 * is a scoped binding — a fresh instance per request and per queued job — so a
 * listener registered once at boot and constructor-injected with the memo
 * would capture the *first* scope's store and hold it for the process
 * lifetime. Under Octane or a queue worker that fails in both directions at
 * once: later requests would flush a store nobody reads, while the captured
 * store would never be flushed and never evicted, serving one request's
 * authorization answers to the next. Only the container is retained, and it
 * outlives every scope by design.
 *
 * **The flush is wholesale within the scope, and only within it.** Every entry
 * goes, not a subset: `MutationCommitted` carries a nullable subject pair, and
 * a definition mutation such as a role deactivation carries no subject at all
 * while suspending every effective path that depends on that role. No targeted
 * rule could be correct for that, and clearing at most 64 in-memory entries
 * costs nothing measurable.
 *
 * **This is request-local memo coherence, not cache invalidation.** Nothing is
 * broadcast, queued, published or written to a cache store, and the event
 * payload is not read or modified — the event's existence is the entire
 * signal. A mutation committed in another process or container does not reach
 * this scope's map; that boundary is documented in ADR 0012 section 7.3.1 and
 * is not a defect this listener could close.
 *
 * @internal
 *
 * @see docs/decisions/0012-effective-access-resolution.md
 */
final readonly class FlushEffectiveAccessMemo
{
    public function __construct(
        private Container $container,
    ) {}

    public function handle(MutationCommitted $event): void
    {
        $this->container->make(EffectiveAccessMemo::class)->clear();
    }
}

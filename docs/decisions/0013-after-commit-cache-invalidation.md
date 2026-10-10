# ADR 0013: Keep the After-Commit Invalidation Slot Reserved and Defer Cross-Request Caching to Phase 8

- Status: **Accepted**
- Proposed: 2026-08-19
- Accepted: 2026-08-19
- Decision owners: GateTrail maintainers
- **What was accepted: a sequencing and deferral decision, not a cache
  feature.** This ADR decides *when* cross-request caching is decided and
  *under what constraints*. It accepts no cache design
- Cross-request cache implementation status: **not required and not
  implemented** — Phase 8 benchmark disposition, 2026-10-10
- Cross-request invalidation implementation status: **not required and not
  implemented**
- Owner: **resolved by Phase 8** — request-local memoization remains the only
  cache-like behavior
- Implementation status of this ADR: **no code.** It adds no cache class, no
  container binding, no configuration key and no public API
- Supersedes: nothing
- Resolves: the Phase 4 `After-commit cache invalidation` deliverable, by moving
  it to Phase 8 rather than by delivering it
- Depends on: ADR 0010 (reserved ordering point), ADR 0011 (exactly-once
  after-commit announcement), ADR 0012 (request-local memoization)

## Context

Phase 5 completed on 2026-08-19. The resolver is implemented, accepted and
verified across SQLite, live MySQL and targeted live Oracle. Phase 4 stood at 19
of 21 when this ADR was written, and one of its two remaining deliverables was
**`After-commit cache invalidation`**.

That deliverable cannot be completed as written, and the reason is a roadmap
sequencing defect rather than missing work. This ADR exists to name it and
propose a resolution rather than let it be resolved silently by whoever picks up
Phase 4 next — because the two silent resolutions available are both bad. One is
to ship a cross-request cache before any benchmark justifies it. The other is to
add an empty abstraction and tick the box.

### What the documents currently say

| Source | Statement |
|---|---|
| ROADMAP Phase 4 | `- [ ] After-commit cache invalidation.` — an unchecked deliverable of a phase otherwise complete |
| ROADMAP Phase 8 | A seven-step sequence: measure uncached → keep request memoization → **decide whether cross-request cache is necessary** → record a cache ADR → implement deterministic invalidation → expiry safety → parity |
| PACKAGE_SPEC 14.2 | Request-level memoization is designed and accepted; the optional cache and its invalidation are "**undesigned**" |
| PACKAGE_SPEC 14.6 | "Cross-request caching remains disabled by default until its store, TTL, role-change fan-out, and invalidation strategy are accepted and measured" |
| PACKAGE_SPEC 21 | "No performance claim may be published without a reproducible benchmark" |
| ADR 0010 | Fixes **where** invalidation sits in the after-commit order and states it "does not define or implement cache behavior" |
| ADR 0012 §7.3.1 | Request memoization is in-process only, with "no distributed invalidation claim" |

Phase 4 asks for a thing Phase 8 has not yet decided to have, using a strategy
the specification calls undesigned, justified by a benchmark that does not
exist. **The deliverable is out of order, not overdue.**

### Request-local memoization is not a cache

The distinction is the whole basis of this ADR, and the codebase already draws
it explicitly. `src/Access/FlushEffectiveAccessMemo.php` says, in its own
docblock:

> **This is request-local memo coherence, not cache invalidation.** Nothing is
> broadcast, queued, published or written to a cache store.

| | Request memo (exists) | Cross-request cache (does not exist) |
|---|---|---|
| Lifetime | One container scope | Until evicted, expired or invalidated |
| Storage | A PHP array in `EffectiveAccessMemo` | A Laravel cache store, possibly shared and remote |
| Bound | 64 entries, LRU | Store-dependent |
| Key | Subject identity, guard, UTC whole second | Undesigned |
| Invalidation | Wholesale clear on a **same-process** `MutationCommitted` | Undesigned |
| Failure mode of staleness | Bounded by one second and one scope | Unbounded until something invalidates |
| Cross-process claim | **None** | Would be the entire point |

Anything that treats the existing memo flush as satisfying "after-commit cache
invalidation" is claiming a distributed guarantee from a process-local array.

### A reserved slot is not an implementation

ADR 0010 reserved the ordering position, and the reservation is real. In
`src/Mutations/AuditedMutationTransaction::announce()` the slot is a comment:

```php
$resolved = $auditId();

// Reserved ordering point. Future after-commit cache invalidation
// runs here, between audit persistence and event dispatch. No
// cache behavior exists yet.

$this->events->dispatch($audit, $outcome, $resolved);
```

That is valuable — it means a future invalidator inherits the outermost-commit
boundary, the exactly-once retry guarantee and the audit-then-cache-then-event
order without renegotiating any of them. It is **not** invalidation. Knowing
where a thing goes is not having the thing.

## Verified codebase facts

Everything below was read from the repository at commit `1e8decf`, not assumed.

**No cache exists anywhere.** `config/gatetrail.php` contains no cache, store,
TTL or tag key. `GateTrailServiceProvider` registers no cache binding. No class
under `src/` reads or writes a cache store; every occurrence of the word is a
docblock stating that something deliberately holds no cache store, or marking
the reserved slot. `gatetrail:doctor` is **not implemented yet**, so PACKAGE_SPEC
14.6's "never clears caches implicitly" is currently a constraint on future work
rather than a property of existing code.

**The after-commit boundary is one combined callback.** `announce()` registers a
single closure through `$connection->afterCommit()` when a transaction is open,
and runs it inline otherwise. It returns early when the audit record is `null`
or the outcome is `MutationOutcome::Unchanged`, so an unchanged mutation reaches
neither the reserved slot nor the event.

**`MutationCommitted` carries eleven fields:**

```text
mutation      MutationName string, one of fourteen
outcome       Created | Updated | Deactivated | Restored | Revoked
targetType    'role_assignment' | 'permission_assignment' | 'role_permission'
              | 'role' | 'permission'
targetId      the identifier of the mutated row
subjectType   nullable
subjectId     nullable, both-or-neither with subjectType
actorType, actorId, auditId, occurredAt, requestId
```

Construction rejects an unknown mutation name and rejects
`MutationOutcome::Unchanged` outright.

**Three facts about that payload matter more than the rest:**

1. **There is no guard.** PACKAGE_SPEC 14.3 requires cache keys to include the
   guard. The event does not carry one, and for a subject whose guard comes from
   configuration it cannot be derived from the payload at all.
2. **`targetId` is the mutated row, not the definition.** For `role.assigned`,
   `targetType` is `role_assignment` and `targetId` is the **assignment row's**
   identifier — not the role's. The event cannot say which role changed.
3. **Only four of fourteen mutations carry a subject**, so **ten of fourteen
   carry none.** Verified per handler: `role.assigned`, `role.revoked`,
   `permission.granted_to_subject` and `permission.revoked_from_subject` pass a
   real subject. `permission.granted_to_role`, `permission.revoked_from_role`
   and all eight definition mutations pass `subjectType: null, subjectId:
   null` — two plus eight, ten in total.

**Supported framework range is `illuminate/* ^12.0 || ^13.0`.** Any cache-store
capability claim must hold across both.

## Existing constraints

These are inherited, not proposed. A future cache design may not weaken any of
them.

1. **The resolver is correct without cross-request caching.** PACKAGE_SPEC 14.1,
   and now demonstrated: the full acceptance matrix passed on three engines with
   no cache in existence.
2. **Request memoization is scoped, bounded and wholesale-cleared** by a
   same-process `MutationCommitted` (ADR 0012 §7.3).
3. **`MutationCommitted` is an in-process Laravel event.** It is not broadcast,
   queued or published. A mutation committed in another process does not reach
   this process's memo, and would not reach a future in-process invalidator
   either.
4. **Invalidation may only run after the outermost successful commit** (ADR
   0010).
5. **A rollback and a discarded retry attempt must produce no invalidation.**
   ADR 0011 puts the retry loop *inside* the announcement, so a discarded
   attempt provably cannot announce.
6. **A successful bounded retry produces at most one invalidation** — ADR 0011
   states "at most one cache invalidation" explicitly.
7. **`Unchanged` outcomes must not invalidate.** Enforced twice already: the
   early return in `announce()` and the constructor guard in
   `MutationCommitted::record()`.
8. **Direct unmanaged writes cannot trigger package invalidation** (ADR 0009,
   PACKAGE_SPEC 14.6).
9. **Cache behaviour must be guard-aware** (PACKAGE_SPEC 14.3) — while the
   event carries no guard.
10. **A cached authorization must never outlive the relevant lifecycle
    boundary** (PACKAGE_SPEC 14.5).
11. **A definition mutation may affect an unknown number of subjects.** ADR 0012
    made the request memo's flush wholesale for exactly this reason: a
    deactivated role carries no subject and yet suspends every path through it.
12. **Database portability is not cache portability.** GateTrail's five-engine
    table portability says nothing about Laravel cache stores. They are
    orthogonal, and the acceptance matrix of ADR 0012 provides zero evidence
    about `array`, `file`, `database`, `redis`, `memcached` or `dynamodb`
    behaviour.

## Decision

**Accepted: keep the ADR 0010 ordering slot reserved and empty. Do not
introduce cross-request caching in Phase 4. Move real cross-request cache
selection and deterministic invalidation to Phase 8, behind a reproducible
uncached benchmark.**

Specifically:

1. No cross-request cache is introduced during Phase 4.
2. ADR 0010's reserved ordering point is preserved exactly as it is, including
   its position between deferred audit persistence and `MutationCommitted`
   dispatch.
3. Request-local memoization remains the only active cache-like optimization,
   and continues to work whether or not cross-request caching ever exists.
4. Cache-store selection, key design, fan-out strategy, TTL policy and failure
   posture are Phase 8 work, sequenced after step 1 of the Phase 8 list
   ("measure uncached resolver queries and latency").
5. The Phase 4 deliverable moves to Phase 8 — roadmap correction B, applied on
   acceptance. See [Sequencing correction](#sequencing-correction).
6. A no-op invalidator does not count as invalidation and must not be used to
   close the deliverable.
7. No abstraction is added to satisfy a checklist.

### Why

The recommendation was tested against the repository rather than assumed, and
the evidence strengthened it in three places.

**The benchmark ordering is not bureaucratic.** PACKAGE_SPEC 21 forbids
publishing a performance claim without a reproducible benchmark. A cache built
before the benchmark cannot be justified by it afterwards — the benchmark's only
remaining job would be to rationalize a decision already made. Increment 9 built
`GateTrailStatementProbe` precisely so Phase 8 can measure honestly; using it
after choosing the answer wastes it.

**The event payload is not ready, and that is new information.** The gaps in
§"Verified codebase facts" — no guard, `targetId` pointing at the assignment row
rather than the role, ten of fourteen mutations carrying no subject — mean
targeted invalidation cannot be built on the current public event without either
expanding it or issuing post-commit queries. Both are decisions with permanent
consequences. Discovering that *during* a Phase 4 sprint would produce a rushed
public event change; discovering it before any cache work began cost nothing.

**The measured cost of a miss is lower than assumed.** The accepted resolver
costs 2 or 3 statements cold and 0 warm within an evaluation second. A
cross-request cache is not rescuing an N+1; it is trading 3 indexed selects for
a network round trip plus an invalidation-correctness obligation. Whether that
trade is worth making is exactly what a benchmark decides.

## Sequencing correction

**Roadmap correction B is the accepted outcome and is applied with this
acceptance.** Correction A was considered and is not selected; it is preserved
below as the rejected alternative.

Two lettering sets appear in this ADR and must not be confused:

| Set | Meaning |
|---|---|
| **Architecture options A–E**, in [Options considered](#options-considered) | What GateTrail should *do* about caching. The accepted decision is architecture option C |
| **Roadmap corrections A and B**, in this section | How the ROADMAP *records* that decision for Phase 4. The accepted correction is roadmap correction B |

Architecture option C is the decision. Roadmap correction B is the bookkeeping
that follows from it. They are separate choices and neither implies the other's
letter.

### Roadmap correction B — move the item to Phase 8 — **accepted**

The Phase 4 checklist said `After-commit cache invalidation`, and that is what it
meant. Real cross-request invalidation does not exist, so the item is not
complete, and it belongs in the phase where the cache decision and its
implementation genuinely live.

Applied on acceptance:

- `After-commit cache invalidation` is removed from Phase 4;
- real after-commit cross-request invalidation is added explicitly to Phase 8,
  where the benchmark-and-decide sequence already governs it, and remains
  unchecked and conditional on cross-request caching being retained;
- the Phase 4 denominator drops from 21 to 20;
- **Phase 4 becomes 19 of 20 = 95%**, `In progress`, leaving `Consumer
  documentation and pre-v1 API freeze` as its only incomplete item.

The **global deliverable tracker does not move**. It stays at 12 of 32 ≈ 38%,
because relocating an unfinished item between phases completes nothing.

**Moving the item is not deleting work.** It records the dependency in the phase
that actually owns it, so the deliverable stays visible and unfinished rather
than being quietly satisfied by something else.

**ADR 0010's ordering guarantee remains real and valuable**, and moving this item
takes nothing away from it. The reserved slot sits inside a tested after-commit
boundary and already carries the outermost-commit rule, ADR 0011's exactly-once
retry semantics and the audit-then-cache-then-event order. A future invalidator
inherits all of that for free. What it is not is invalidation, and Phase 4's
checklist asked for invalidation.

### Roadmap correction A — rename in place — considered and rejected

Rename the deliverable to `After-commit invalidation ordering contract` and check
it, on the grounds that the ordering contract exists, is implemented and is
evidenced by ADR 0010's acceptance. Denominator unchanged at 21, Phase 4 would
have become 20 of 21 ≈ 95%.

It reaches the same ≈95% and it is defensible, which is why it was considered.
It was **rejected** because it changes the meaning of the original promise after
the fact. A reader comparing the roadmap before and after would see a
cache-invalidation deliverable become an ordering deliverable and be checked, and
would reasonably conclude that after-commit cache invalidation had been delivered
when only its ordering point exists. Renaming a deliverable to match what was
built, rather than building what the deliverable named, is the kind of
bookkeeping that makes a checklist stop meaning anything.

Both corrections land Phase 4 at ≈95% with one item left. They differ only in
whether the roadmap keeps telling the truth about what was promised, which is
why correction B was accepted.

## Options considered

### A. Implement an optional cross-request cache and invalidation now

| Dimension | Assessment |
|---|---|
| Correctness | Highest risk of the five. Every constraint in this ADR would be decided under Phase 4 time pressure |
| Security | A stale `allow` is a privilege-escalation window whose duration is set by whatever TTL is guessed |
| Operational | Adds a store dependency, a failure mode after durable commit, and stampede behaviour |
| Portability | Would need per-store capability handling across `illuminate ^12 \|\| ^13` with no current evidence |
| API cost | Permanent public configuration keys chosen before measurement |
| Test burden | Large: multi-process, expiry, fan-out, store-unavailable, parity against uncached |
| Phase effect | Closes Phase 4; pre-empts and largely voids Phase 8 steps 1–4 |

**Rejected.** It inverts the accepted Phase 8 sequence and spends public API on
an unmeasured guess.

### B. Add a no-op `CacheInvalidator` abstraction now

| Dimension | Assessment |
|---|---|
| Correctness | Neutral — it does nothing |
| Security | Actively negative. A named component implies a guarantee it does not provide, and the roadmap would read as though invalidation were solved |
| Operational | Nil |
| Portability | Nil |
| API cost | An interface shipped before its only implementation exists, which constrains the design it was supposed to enable |
| Test burden | Tests that assert nothing happens |
| Phase effect | Closes Phase 4 dishonestly |

**Rejected.** This is the "check the box" outcome the recommendation warns
against, and it is worse than doing nothing because it manufactures false
assurance.

### C. Preserve the ordering contract, defer real caching to Phase 8 — **accepted**

This is the accepted **architecture** decision. Its roadmap consequence is
roadmap correction B in [Sequencing correction](#sequencing-correction) — move
the Phase 4 deliverable to Phase 8 — and the two should not be conflated because
they carry different letters.

| Dimension | Assessment |
|---|---|
| Correctness | Highest. The resolver is already correct and acceptance-verified without a cache |
| Security | No new stale-authorization surface is created |
| Operational | No new dependency, no new failure mode after commit |
| Portability | Deferred until store capabilities can be assessed against real needs |
| API cost | Zero now. The eventual cost is paid with benchmark evidence behind it |
| Test burden | Zero now |
| Phase effect | Requires the Phase 4 correction above; leaves Phase 8 intact and meaningful |

**Accepted.**

### D. Remove cross-request caching permanently from pre-v1 scope

| Dimension | Assessment |
|---|---|
| Correctness | Equal to C |
| Security | Equal to C, and permanently forecloses the stale-allow surface |
| Operational | Simplest possible |
| Portability | Moot |
| API cost | Zero, permanently |
| Test burden | Zero |
| Phase effect | Would gut Phase 8 and contradict PACKAGE_SPEC 14.2's "optional Laravel cache" |

**Not recommended now, but not dismissed.** It is a legitimate Phase 8 outcome
if the benchmark shows the uncached resolver is fast enough — which is precisely
what Phase 8 step 3 exists to decide. Deciding it *now*, without the benchmark,
would be the same error as option A in the opposite direction. Carried forward
as [deferred decision 8](#deferred-to-phase-8).

### E. Consumer-provided invalidation only

GateTrail would document the after-commit hook and let each application build
its own cache and invalidation.

| Dimension | Assessment |
|---|---|
| Correctness | Moves the hardest correctness problem to consumers, who have less context |
| Security | Poor. Authorization staleness becomes an application-by-application property |
| Operational | Low for the package, high for every consumer |
| Portability | Consumer's problem |
| API cost | Requires making a hook public — a real, permanent API decision |
| Test burden | Low for the package; the package can prove nothing about the result |
| Phase effect | Closes Phase 4 by delegation |

**Rejected as the primary strategy.** Authorization correctness is what this
package is for; exporting its hardest part is not a design. A documented
extension point may still be *part* of a Phase 8 outcome, but not instead of one.

## Mutation invalidation matrix

All fourteen managed mutations. "Subject in event" is verified from the handler
source, not inferred. "Guard in event" is `no` for all fourteen, because
`MutationCommitted` carries no guard field at all.

### Assignment mutations — six

| Mutation | Changes authorization? | Subject in event | Targeted invalidation possible? |
|---|---|---|---|
| `role.assigned` | Yes | **Yes** | Subject known; guard is not. `targetId` is the assignment row, so the role is unknown from the event |
| `role.revoked` | Yes | **Yes** | As above |
| `permission.granted_to_subject` | Yes | **Yes** | As above, with `targetType: permission_assignment` |
| `permission.revoked_from_subject` | Yes | **Yes** | As above |
| `permission.granted_to_role` | Yes | **No — `null`** | **Fan-out.** Affects every subject holding that role. The event names the `role_permission` row, not the role, and not the subjects |
| `permission.revoked_from_role` | Yes | **No — `null`** | As above. This is the widest-blast-radius assignment mutation |

These four subject-scoped mutations are the only ones in the whole vocabulary
where targeted invalidation is even conceivable from the current event — and even they lack the guard that
PACKAGE_SPEC 14.3 requires in the key.

### Role-definition mutations — four

| Mutation | Changes authorization? | Subject in event | Invalidation implication |
|---|---|---|---|
| `role.created` | **No** | No | A new role has no assignments yet, so it grants nothing. Invalidation is arguably unnecessary — but see the caveat below |
| `role.updated` | **Depends** | No | Renaming a role changes name-based `has()` results. A description-only change does not. The event does not say which columns changed |
| `role.deactivated` | Yes | No | Suspends every path through the role, for an unknown number of subjects |
| `role.restored` | Yes | No | Restores them |

### Permission-definition mutations — four

| Mutation | Changes authorization? | Subject in event | Invalidation implication |
|---|---|---|---|
| `permission.created` | **No** | No | Nothing grants it yet |
| `permission.updated` | **Depends** | No | Renaming changes name-based `allows()`; description-only does not |
| `permission.deactivated` | Yes | No | Removes the permission from every subject holding it directly or through any role |
| `permission.restored` | Yes | No | Restores it |

### What the matrix shows

- **Ten of fourteen mutations carry no subject** — the two role-permission
  mutations and all eight definition mutations — so ten cannot be targeted at
  all from the current event. Only the four subject-scoped assignment mutations
  name a subject.
- **Zero of fourteen carry a guard**, so no key satisfying PACKAGE_SPEC 14.3 can
  be built from the event alone.
- **`create` is the interesting edge.** A newly created role or permission grants
  nothing, so a cache holding *positive* answers needs no invalidation. A cache
  holding *negative* answers keyed by name might: `allows($u, 'reports.view')`
  cached as `false` before the permission existed stays `false` after it is
  created and granted — but the grant is itself a separate mutation that would
  invalidate. Whether `create` needs invalidation therefore depends on the cached
  representation, which is undesigned. **Recorded as an open design question, not
  answered here.**
- **`update` cannot be classified from the event.** `MutationOutcome::Updated`
  does not say whether the name changed. Either the cache treats every `update`
  as authorization-affecting, or the invalidation plan is built inside the
  transaction where the diff is known.
- **`Unchanged` never reaches the slot**, so idempotent no-ops invalidate
  nothing. This is already enforced in two places and needs no new rule.

## Event payload analysis

The matrix above is the case for **not** starting from the public event.

Four ways to close the gap, with the recommendation:

**1. Build an invalidation plan inside the transaction — preferred.** The
handler knows the guard, the role, the permission and, for an update, which
columns changed. An internal, non-public value assembled before the after-commit
callback carries exactly what invalidation needs. It expands no public API,
leaks nothing to listeners, and requires no post-commit query. Its cost is one
more internal type threaded through the mutation path.

**2. Expand `MutationCommitted`.** Adding a guard, a definition identifier or a
subject list makes the payload permanent public API. It also raises a privacy
question the event was deliberately designed to avoid: it currently carries safe
scalars only, precisely because it "may be queued, logged, or forwarded to a
third-party integration". A subject list on a role-permission change could
disclose the full membership of a role to any listener, including a log sink.
**Not recommended before benchmarks prove the need.**

**3. Post-commit database lookups.** Query the affected subjects after commit.
Correct, but it puts a potentially large query on the write path — the fan-out
case could return every subject holding a popular role — and it can race with
concurrent assignment changes.

**4. Guard-scoped generations.** Store a generation counter per guard; a
definition mutation bumps it and invalidates that guard wholesale without
enumerating anything. Cheap, tagless, and a good fit for the ten subject-less
mutations. Costs precision — an unrelated subject loses its entry —
and needs an atomic increment the baseline store may not provide (see
portability). Carried forward as [deferred decision 6](#deferred-to-phase-8).

**Recommendation: defer the choice to Phase 8, with option 1 preferred and
option 2 discouraged.** No public event change should be made on the strength of
this ADR.

## Cache-store portability

Laravel's `Illuminate\Contracts\Cache\Repository` is uniform; store capabilities
are not. Across `illuminate ^12.0 || ^13.0` a future design must not assume:

| Capability | Reality |
|---|---|
| **Tags** | Not universal. `file` and `database` stores do not support tagging; calling tag methods on them fails. A tag-based invalidation strategy silently excludes the two stores most likely in a small deployment |
| **Atomic increment** | `increment()` exists on the contract, but atomicity is store-dependent. A generation counter that must not lose a bump cannot assume it |
| **Locks** | `Illuminate\Contracts\Cache\LockProvider` is implemented by some stores, not all. Stampede protection built on locks needs a documented fallback |
| **Enumeration** | No store enumerates keys through the cache contract. "Invalidate every entry for this role" is not expressible without tags, a generation, or an external index |
| **Delete by prefix** | Not in the contract at all |
| **TTL precision** | Second-level granularity is the practical floor, and drivers differ in rounding and in eviction timing under memory pressure. Expiry-safe TTL cannot assume the store expires an entry the instant it is due |

**This ADR makes no claim that any of these is universally available.** Where a
Phase 8 design depends on one, it must state the required store capability and
define the behaviour when the configured store lacks it — degrade, refuse to
enable, or fail closed.

**A tagless baseline is mandatory**, per [decision 3](#decided). Tags may be an
optional optimization on stores that support them, never the baseline.

## Failure semantics after a durable commit

The hardest case, stated plainly: **the mutation is committed and durable, and
then invalidation fails.** The database is right; the cache is wrong; and the
cache is what authorization reads.

Four responses, each with a real defect:

| Response | Defect |
|---|---|
| **Report rolled back** | A lie. The row is committed and the audit record exists. This is not available |
| **Log and continue** | A stale `allow` may survive until TTL. For a revocation this is a privilege-escalation window, and the log does not close it |
| **Throw after commit** | The manager already succeeded. A consumer's natural response — retry — re-runs a mutation that has already happened. ADR 0010 and `MutationEventDispatcher` both already refuse to throw after commit for exactly this reason |
| **Fail closed by disabling cache reads** | Preserves correctness by falling back to the uncached resolver, at a performance cost, and needs a defined scope (this request? this process? until an operator intervenes?) and a recovery path |

The fourth is the most promising because it is the only one that restores
*correctness* rather than merely reporting its loss — the uncached resolver is
already proven correct, so falling back to it is a safe degradation. It is also
the least specified: process-scoped, cluster-scoped and operator-cleared
variants differ enormously in operational cost.

**This ADR deliberately does not finalize a failure policy.** Choosing one
requires knowing the store, the key strategy and the fan-out mechanism, none of
which exist. It is [deferred decision 5](#deferred-to-phase-8) and remains an
open Phase 8 decision.

One thing is settled regardless: **a post-commit invalidation failure must never
be reported as a failed mutation**, because it is not one.

## Unmanaged writes

Unchanged from ADR 0009 and PACKAGE_SPEC 14.6, restated because a cache changes
the consequence:

- direct SQL and direct Eloquent writes are outside managed invalidation and
  always will be. GateTrail sees no event and can invalidate nothing;
- **today the exposure is bounded**: the request memo's key includes a UTC whole
  second, so an unmanaged change is visible within one second and one scope;
- **with a cross-request cache the exposure becomes the TTL**, which may be
  minutes. This is a genuine widening of an existing hazard and must be
  documented as such if caching is retained;
- future explicit cache-clear tooling must be deliberate and operator-invoked;
- **`gatetrail:doctor` must not clear caches implicitly.** It does not exist yet,
  so this is a constraint on its future design, not a property of current code.

## Consequences

### Positive

- No public configuration key or API is spent before evidence justifies it.
- Authorization correctness stays simple: one path, already verified on three
  engines.
- The Phase 8 benchmark stays a real decision rather than a rationalization.
- ADR 0010's ordering contract keeps its value — a future invalidator inherits
  the outermost-commit boundary and exactly-once retry semantics for free.
- The event-payload gaps are surfaced now, in a document, instead of mid-sprint.

### Negative

- **The Phase 4 roadmap needed correcting.** A deliverable listed since the
  roadmap was written turned out to be misfiled, and correcting it is an
  admission rather than an achievement. That is the point of this ADR.
- Cross-request performance stays unoptimized. Consumers with hot authorization
  paths across requests get no help before Phase 8.
- Phase 4 still cannot reach 100%: the correction lifts it to 95%, and
  `Consumer documentation and pre-v1 API freeze` remains open.
- This ADR defers the cache design rather than producing one, which is only
  defensible because the deferral is itself the decision the accepted Phase 8
  sequence calls for. Three questions remain genuinely open.

### Neutral

- The reserved slot stays empty and costs nothing while empty.
- Request memo coherence is unaffected in either direction.

## Rejected alternatives

- **Treating the request-memo flush as distributed invalidation.** It is a PHP
  array cleared in one process. `FlushEffectiveAccessMemo` says so in its own
  docblock. Claiming otherwise would be a false security guarantee.
- **Flushing the entire Laravel cache store.** Destroys unrelated application
  data to solve a GateTrail problem. Never acceptable, at any phase.
- **Requiring cache tags as the baseline.** Excludes `file` and `database`
  stores, which are the likely choice for the deployments least able to absorb a
  surprise.
- **TTL-only invalidation with no mutation coherence.** Makes every revocation
  take effect "eventually". Unacceptable for authorization.
- **Invalidating before commit.** A rolled-back mutation would have invalidated
  a valid entry, and worse, a concurrent read could repopulate the cache from
  pre-commit state before the commit lands. ADR 0010's ordering exists to
  prevent exactly this.
- **Invalidating once per retry attempt.** ADR 0011 guarantees at most one
  announcement per successful mutation; per-attempt invalidation would break it
  and produce invalidations for attempts that never committed.
- **Counting a no-op invalidator as completion.** Architecture option B above.
- **Adding target information to the public event without proven need.**
  Permanent API and a disclosure surface, spent on a guess.

## What acceptance did and did not do

| | Status on acceptance |
|---|---|
| The sequencing decision — benchmark first, Phase 8 owns the cache question | **Accepted** |
| Roadmap correction B — move the Phase 4 item to Phase 8 | **Accepted and applied** |
| The constraint set any future cache design must satisfy | **Accepted** |
| A cross-request cache | **Not implemented, and not designed** |
| Cross-request invalidation | **Not implemented, and not designed** |
| Cache store, TTL, key format, fan-out or generation scheme | **Not accepted** — Phase 8 |
| Any configuration key or public API | **None added** |
| Phase 8 work | **Not started** |

Acceptance changed three documents and no code. `config/gatetrail.php` is
untouched, `GateTrailServiceProvider` registers nothing new, and the ADR 0010
ordering slot remains the same empty comment it was before.

## Accepted decisions

The eight questions this ADR raised are recorded below with their resolution.
Five are decided; three are deliberately deferred to Phase 8.

### Decided

**1. Move the Phase 4 item to Phase 8, or rename it in place? — DECIDED: move
it.** Roadmap correction B is applied. `After-commit cache invalidation` leaves
Phase 4 for Phase 8, the denominator drops 21 → 20, and Phase 4 becomes 19 of
20 = 95%. Renaming it to the ordering contract was rejected: ADR 0010's slot is
valuable foundation, but it is not invalidation, and checking it under a new name
would imply invalidation was delivered.

**2. Should cross-request caching remain optional and disabled by default? —
DECIDED: yes, permanently.** Any future cross-request cache is optional, ships
**disabled by default**, and **GateTrail never enables it automatically**. An
authorization cache that switches itself on is a stale-allow risk nobody opted
into.

**3. Is a tagless baseline mandatory? — DECIDED: yes.** Any retained cache design
must work without cache tags, so it functions on `file` and `database` stores.
Tags may be an **optional optimization** only. No claim is made — here or in any
later document — that tags, atomic increments, locks, enumeration or
delete-by-prefix are universally supported across `illuminate ^12.0 || ^13.0`.

**4. Should the public `MutationCommitted` event stay unchanged? — DECIDED:
yes.** No guard, role, permission or subject-list field is added. If future
invalidation needs more information, the preferred route is an **internal,
non-public invalidation plan constructed while the transaction context is still
available**, not an expanded public event. The disclosure hazard is part of the
reason: a subject list on a role-permission change would publish a role's
membership to every listener, including a log sink.

**7. What benchmark threshold justifies retaining a cross-request cache? —
DECIDED: it is set before the benchmark runs.** Phase 8 must define its
threshold in step 1, before results exist. A threshold chosen after the numbers
are known can justify any outcome, which is exactly what PACKAGE_SPEC 21 exists
to prevent.

### Deferred to Phase 8

These three cannot be answered without knowing the cache store, the key strategy
and the fan-out mechanism — none of which exist, and none of which this ADR
accepts. **They do not block acceptance**, because this ADR decides *sequencing*
and *constraints*, not design: every one of them is a question the Phase 8 cache
ADR must answer *if* cross-request caching is retained, and is moot if it is not.

**5. What failure posture is acceptable after a committed mutation? —
DEFERRED.** Fail-closed fallback to the proven uncached resolver remains the
leading direction. One rule is settled now and is not deferred: **a committed
authorization mutation must never be reported as rolled back or unsuccessful
merely because later invalidation failed.**

**6. Is a guard-wide generation acceptable for broad invalidation? —
DEFERRED.** It remains the leading candidate for the ten subject-less mutations
and the two role-permission mutations, because it is tagless and needs no
enumeration. It is **not accepted as the strategy**: its atomicity requirement,
its over-invalidation cost and its store-capability needs are Phase 8 work.

**8. Is cross-request caching required before `1.0.0`? — DEFERRED, by design.**
Phase 8's benchmark-and-decide sequence owns it. **"No cross-request cache"
remains a legitimate outcome** if the uncached resolver plus request-local
memoization proves sufficient.

## Follow-up actions

Strictly separated by owner and by phase.

**Applied with this acceptance — documentation only:**

1. Roadmap correction B: `After-commit cache invalidation` removed from Phase 4,
   real after-commit cross-request invalidation added to Phase 8 as an unchecked
   item conditional on caching being retained, Phase 4 recalculated to 19 of
   20 = 95%, `In progress`.
2. `PACKAGE_SPEC.md` section 14 and the architecture-decision index aligned: the
   accepted sequencing recorded, future cache requirements relabelled as
   requirements *only if* Phase 8 retains cross-request caching, and
   `gatetrail:doctor`'s constraint marked forward-looking because the command
   does not exist yet.
3. This ADR marked Accepted and added to the ROADMAP accepted-decision table.

**Phase 8, in order, and not started:**

4. Define the benchmark threshold — *before* running the benchmark (decision 7).
5. Reproducible uncached benchmark, reusing `GateTrailStatementProbe`.
6. Decide whether cross-request caching is retained at all (deferred question 8).
   **"No" is a legitimate answer.**
7. If retained, a dedicated cache-strategy ADR answering deferred questions 5 and
   6 and binding itself to the constraint set above.
8. Implementation, expiry-safe TTL, invalidation matrix and parity tests — only
   after that ADR, and only if caching is retained.

**Independent of all of the above:**

9. `Consumer documentation and pre-v1 API freeze`, now the only remaining Phase 4
   item. It is unrelated to caching and is not blocked by this ADR.

## Phase 8 disposition — 2026-10-10

The ordered follow-up above is complete. The threshold was committed before
measurement, then the isolated live MySQL 8.0.45 benchmark passed at 6.069 ms
cold p95 and 0.009 ms warm p95 for the representative 10-role,
100-permission workload, with three cold selects and zero warm statements.

Phase 8 therefore selected the legitimate **no cross-request cache** outcome
anticipated by this ADR. No cache-strategy ADR, cache store, shared key, TTL,
fan-out, stampede control or after-commit cross-request invalidator is required
for the internal alpha. The reserved ADR 0010 ordering slot remains empty.
Earlier wording in this ADR that says Phase 8 was not started records the state
when the decision was accepted; it is superseded operationally by this
disposition and the reproducible evidence in
`docs/performance/resolver-benchmark.md`.

## References

- [ADR 0009: Direct database mutation and diagnostics](0009-direct-database-mutation-and-diagnostics.md)
- [ADR 0010: After-commit mutation events](0010-after-commit-mutation-events.md)
- [ADR 0011: Bounded concurrency retry](0011-bounded-concurrency-retry.md)
- [ADR 0012: Effective access resolution](0012-effective-access-resolution.md)
- `PACKAGE_SPEC.md` sections 11.11, 14, 20.5, 21, 24
- `ROADMAP.md` Phase 4, Phase 5, Phase 8
- `src/Mutations/AuditedMutationTransaction.php` — the reserved ordering point
- `src/Events/MutationCommitted.php` — the current payload
- `src/Access/FlushEffectiveAccessMemo.php` — request-local memo coherence

# ADR 0010: Dispatch One Generic After-Commit Mutation Event

- Status: Accepted
- Date: 2026-07-31
- Owner approval date: 2026-07-31
- Decision owners: GateTrail maintainers

## Context

`PACKAGE_SPEC.md` section 16 requires domain events that mirror successful
mutations, dispatched after commit, carrying identifiers and safe context, and
never replacing persistent audit records. ADR 0002 places event dispatch in the
manager side-effect contract after cache invalidation, and requires that a
rolled-back mutation and an idempotent `Unchanged` replay dispatch nothing.

The mutation pipeline that must carry those events already exists. All fourteen
handlers return an immutable `PreparedMutation`, and
`AuditedMutationTransaction` owns the transaction state machine for the three
accepted audit modes. `AfterCommitBoundary` already answers, without
registering anything, whether a caller-owned transaction defers callbacks to
its outermost commit. `AuditPersistenceFailed` already establishes the
package's safe operational-event payload style.

What is missing is the event contract itself: its shape, its name set, its
payload, when it fires relative to auditing and future cache invalidation, and
what happens when a listener fails after the mutation is already durable.
Implementing dispatch before deciding those questions would make an
unversioned public API out of whatever the first implementation happened to do.

This ADR decides the contract. It was accepted and then implemented; see
Implementation status below.

## Decision

GateTrail dispatches exactly one generic public event,
`HangTuah\GateTrail\Events\MutationCommitted`, after the outermost successful
commit of every managed mutation whose outcome is not `Unchanged`.

The event carries a stable mutation name and safe identifiers only. It is a
domain event that reports a durable authorization change; it is not an audit
record and not an operational failure signal.

Event production is always on. There is no configuration flag that disables it,
and dispatching with no registered listeners is normal and cheap. Consumers opt
in by registering listeners, not by switching package behavior on, so mutation
side effects are identical across every installation.

## Public event shape

### Options compared

**A. Fourteen mutation-specific event classes.**
`RoleAssigned`, `RoleRevoked`, `RoleCreated`, and eleven more.

**B. One generic `MutationCommitted` carrying a stable mutation name.**

**C. A generic envelope plus mutation-specific subclasses.**

### Laravel listener resolution

One framework behavior decides more of this than ergonomics arguments do.
`Illuminate\Events\Dispatcher::getListeners()` merges listeners registered for
the event class, for matching wildcards, and — through
`addInterfaceListeners()` — for every interface the event class implements. It
does **not** resolve listeners registered against a parent class. The method is
byte-identical in Laravel 12.x and 13.x.

Two consequences follow:

- option C cannot deliver its headline benefit. A consumer cannot listen to the
  envelope class and receive the subclasses. C only works if the envelope is an
  interface, at which point C is A plus a marker interface;
- option A's main weakness is smaller than it looks, because a shared interface
  gives A a working catch-all.

### Accepted: option B

`MutationCommitted` is a single final readonly class carrying
`string $mutation`.

Reasons:

- **The fourteen payloads are identical.** Models are excluded from the payload
  (see below), so mutation-specific classes would differ only in their class
  name. Fourteen classes that differ only by name are a string field with extra
  steps, and they would carry no additional type information for a listener to
  use.
- **Compatibility surface.** Every public event class is a serialization and
  naming contract that a pre-v1 package must then keep. B commits to one; A
  commits to fourteen, and to one more for every mutation GateTrail ever adds.
- **Package consistency.** GateTrail already models this exact domain with one
  row shape and a string `event` column in `acl_access_audits`, and with one
  generic `AuditPersistenceFailed` operational event carrying an event name. A
  fourteen-class event API would be the only place the package discriminates by
  type rather than by name.
- **Consumer ergonomics for the common cases.** "React to every authorization
  change" is one listener. "React to role assignment only" is one listener plus
  one `match` or early return on `$event->mutation`.
- **Future mutations.** A fifteenth mutation adds one name constant, not one
  public class, one listener registration convention, and one more thing to
  serialize.

Rejected: **A**, because fourteen structurally identical classes buy
compile-time discrimination that a `match` on a stable string already provides,
at a permanent maintenance and compatibility cost. Rejected: **C**, because
Laravel's listener resolution makes its central promise false, and the
interface-based repair collapses it into A.

`MutationCommitted` implements a marker interface,
`HangTuah\GateTrail\Contracts\GateTrailEvent`. That costs nothing now and
preserves the only migration path that Laravel's resolution rules allow: if
per-mutation classes are ever genuinely needed, they can implement the same
interface and existing catch-all listeners keep working. Listeners bound
directly to `MutationCommitted::class` would still need migrating, which is a
major-version concern.

## Stable mutation names

Event names reuse the canonical audit event names already persisted in the
`event` column of `acl_access_audits`. One name means one thing across the
audit trail and the event stream, and no translation table exists to drift.

| Manager | Method | Mutation name |
|---|---|---|
| `AssignmentManager` | `assignRole()` | `role.assigned` |
| `AssignmentManager` | `revokeRole()` | `role.revoked` |
| `AssignmentManager` | `grantDirectPermission()` | `permission.granted_to_subject` |
| `AssignmentManager` | `revokeDirectPermission()` | `permission.revoked_from_subject` |
| `AssignmentManager` | `grantPermissionToRole()` | `permission.granted_to_role` |
| `AssignmentManager` | `revokePermissionFromRole()` | `permission.revoked_from_role` |
| `RoleManager` | `create()` | `role.created` |
| `RoleManager` | `update()` | `role.updated` |
| `RoleManager` | `deactivate()` | `role.deactivated` |
| `RoleManager` | `restore()` | `role.restored` |
| `PermissionManager` | `create()` | `permission.created` |
| `PermissionManager` | `update()` | `permission.updated` |
| `PermissionManager` | `deactivate()` | `permission.deactivated` |
| `PermissionManager` | `restore()` | `permission.restored` |

The direct-permission mutations keep `permission.granted_to_subject` and
`permission.revoked_from_subject`. A `*_directly` spelling was considered and
rejected: those two names are already the implemented handlers' audit events and
already persisted in every existing audit row, so renaming would either break
the audit `event` vocabulary or leave two names for one fact.

Names are not independently versioned. They are the same identifiers already
frozen by the audit contract, and a rename is a major-version change.

## Safe event payload

```php
final readonly class MutationCommitted implements GateTrailEvent
{
    public string $mutation;
    public MutationOutcome $outcome;
    public string $targetType;
    public int|string $targetId;
    public ?string $subjectType;
    public int|string|null $subjectId;
    public string $actorType;
    public string $actorId;
    public int|string|null $auditId;
    public DateTimeImmutable $occurredAt;
    public ?string $requestId;
}
```

Included, and why:

- `mutation` — the stable name above; the field every listener branches on;
- `outcome` — the `MutationOutcome` the manager returned, never `Unchanged`;
- `targetType` / `targetId` — the logical target alias (`role`, `permission`,
  `role_assignment`, `permission_assignment`, `role_permission`) and the
  persisted key, matching the audit record's target columns;
- `subjectType` / `subjectId` — present only for subject-scoped mutations,
  matching the audit record's subject columns;
- `actorType` / `actorId` — the resolved `ActorIdentity`, already normalized to
  portable strings by ADR 0005; a listener must be able to attribute a change
  without a second query;
- `auditId` — the audit identifier when one exists synchronously, so a listener
  can join to the audit trail; see audit-mode semantics below;
- `occurredAt` — the command's captured `requestedAt`, in UTC with whole-second
  precision, identical to the audit record's `created_at`;
- `requestId` — the correlation identifier when a request-metadata resolver
  supplied one.

Excluded, and why:

- **Eloquent model instances** — a model is mutable, lazily loads relations,
  serializes unpredictably onto queues, and would expose consumer application
  columns that ADR 0008 already keeps out of audit documents;
- **`before` / `after` snapshots** — full security-relevant state belongs in
  audit storage, not in an event that may be queued, logged, or forwarded to a
  third-party integration;
- **consumer metadata** — arbitrary consumer-supplied content with its own
  bounded, redacted destination under `context.metadata`;
- **reason** — free text that ADR 0008 already treats as potentially sensitive
  and subjects to redaction;
- **credentials, request bodies, request headers** — never in scope;
- **exception objects or messages** — this event reports success;
- **command or handler objects** — internal, `@internal`, and mutable in the
  sense that they are not part of any public contract.

### Constructor invariants

- `mutation` must be one of the fourteen names; an unknown name is a package
  bug and throws;
- `outcome` must not be `MutationOutcome::Unchanged`;
- `targetType` and `targetId` are always present; `targetId` is `int|string`
  under the package's existing identifier rules and is never coerced;
- the subject pair is both-or-neither, mirroring `PendingAuditRecord`'s
  existing invariant; supplying one without the other throws;
- `actorType` and `actorId` are non-empty strings;
- `auditId` is `int|string|null`; `null` carries the meanings defined below and
  never means "failed mutation";
- the constructor is not public. Consumers receive instances; they do not build
  them. An internal named factory constructs the event from the winning
  `PreparedMutation`, its `PendingAuditRecord`, and the resolved audit
  identifier.

### Serialization and context ordering

The event exposes `context(): array` returning a fixed key order, mirroring
`AuditPersistenceFailed::context()` so operational tooling reads one shape:

```text
mutation
outcome
target_type
target_id
subject_type
subject_id
actor_type
actor_id
audit_id
occurred_at
request_id
```

Numeric identifiers are rendered as canonical decimal strings, matching ADR
0008's rule for JSON consumers. `outcome` renders as its backing string value.
`occurred_at` renders in the canonical UTC microsecond form. `request_id` is
omitted when absent, exactly as `AuditPersistenceFailed` omits it.

## Audit-mode semantics

`auditId` describes evidence, never success. The event's existence is the
success signal.

| Situation | `auditId` |
|---|---|
| Strict success | audit identifier |
| Owned non-strict, audit succeeded | audit identifier |
| Owned non-strict, audit failed | `null` |
| Caller-owned non-strict, audit deferred and then succeeded | audit identifier |
| Caller-owned non-strict, audit deferred and then failed | `null` |
| Disabled auditing | `null` |

Because the event is dispatched after the deferred audit attempt in
caller-owned non-strict mode (see ordering below), the event can report the
identifier that `MutationResult` could not. That is deliberate: the manager
must return before the outer commit, the event must not.

A `null` `auditId` therefore means only "no audit identifier is available".
Consumers distinguish the reasons through configuration, the operational log,
and `AuditPersistenceFailed`.

### Relationship to `AuditPersistenceFailed`

| | `MutationCommitted` | `AuditPersistenceFailed` |
|---|---|---|
| Kind | Domain event | Operational event |
| Means | An authorization change is durable | Evidence for a durable change is missing |
| Dispatched when | Every successful changed mutation | Only a non-strict audit write failure |
| Audit modes | Strict, non-strict, disabled | Non-strict only |
| Ordering | After the audit attempt | During the audit attempt |

Both can fire for the same mutation: a non-strict audit failure dispatches
`AuditPersistenceFailed` and then `MutationCommitted` with a `null` `auditId`.
That pair is the accurate description of what happened, and neither event
replaces the other.

## Dispatch timing

The cache-invalidation step below is a reserved ordering point. This ADR fixes
where it sits; it does not define or implement cache behavior.

**Strict, GateTrail owns the outermost transaction**

```text
persist mutation
-> persist audit
-> commit
-> invalidate cache        (reserved)
-> dispatch MutationCommitted
-> return MutationResult
```

**Non-strict, GateTrail owns the outermost transaction**

```text
persist mutation
-> commit
-> attempt audit in a second transaction
-> invalidate cache        (reserved)
-> dispatch MutationCommitted
-> return MutationResult
```

**Non-strict, caller-owned transaction**

```text
persist mutation inside the caller transaction
-> return MutationResult with a null auditId
   ... caller continues ...
-> caller's outermost commit
-> attempt deferred audit in a new transaction
-> invalidate cache        (reserved)
-> dispatch MutationCommitted
```

**Disabled auditing**

```text
persist mutation
-> commit
-> invalidate cache        (reserved)
-> dispatch MutationCommitted
```

Strict and disabled modes inside a caller-owned transaction follow the same
deferral: the mutation participates in the caller's transaction, the manager
returns, and cache invalidation and dispatch wait for the outermost commit.

The event is never dispatched:

- before the outermost successful commit;
- after a rollback at any level;
- for a validation failure, a domain exception, or any thrown mutation;
- for `MutationOutcome::Unchanged`;
- more than once for one logical mutation, regardless of nesting or retries.

## Caller-owned transactions

Deferral reuses the mechanism non-strict auditing already uses. When
`Connection::transactionLevel()` is greater than zero, GateTrail registers one
after-commit callback that performs the audit attempt, the reserved cache
invalidation, and the dispatch, in that order. One callback rather than three
guarantees the ordering above without depending on Laravel's callback
execution order.

Capability is checked with the existing `AfterCommitBoundary`, before the
mutation runs and without registering anything. That property matters more once
events exist: an unchanged result must still leave nothing scheduled.

Rollback discards the callback because Laravel's
`DatabaseTransactionsManager` drops pending transaction records and their
callbacks on rollback rather than executing them. That is the mechanism that
makes "no event after rollback" true, and it is the same mechanism that already
makes "no audit after rollback" true.

**Unsupported boundaries: uniform rejection.** Today an unsupported boundary is
rejected before mutation only in non-strict mode, because only non-strict mode
must defer something. Once events exist, strict and disabled modes must defer
too, so the rule becomes uniform:

- every changed mutation joining a caller-owned transaction requires reliable
  outermost after-commit semantics, in strict, non-strict, and disabled audit
  modes alike;
- an unsupported boundary is rejected before the mutation runs, so nothing is
  persisted that could not carry its deferred side effects;
- the boundary now protects deferred audit persistence, the reserved cache
  invalidation, and event dispatch, so the exception contract is renamed from
  `UnsupportedAuditBoundary` to `UnsupportedAfterCommitBoundary`.

The alternative — keeping today's audit-only rejection and degrading to "skip
dispatch and log" when a boundary cannot defer an event — was rejected. An
integration that silently stops receiving events is far harder to detect than a
boundary that fails immediately, and GateTrail's established posture for an
unverifiable boundary is to fail closed.

This is a pre-v1 behavior change: strict and disabled mode now reject
caller-owned transactions on connections that cannot defer callbacks. The
rename is likewise a pre-v1 public API change. Both were accepted deliberately
and are implemented; `UnsupportedAuditBoundary` no longer exists and no
compatibility alias was kept.

The check runs before the operation closure and therefore cannot yet know
whether the outcome will be unchanged, so it applies to every caller-owned
managed mutation. That is the safe direction, and the check registers nothing,
so an unchanged result still leaves no callback behind.

No distributed-transaction guarantee is claimed. GateTrail defers to the
outermost commit of its own configured connection. A listener that writes to
another connection, a queue, or a remote service is outside any transactional
guarantee this package can make.

## Listener failure policy

**Accepted policy: swallow and log. Do not throw. Do not dispatch a second
event.**

A listener runs after the mutation is durable. Propagating its exception would
surface as a failure from a manager method that already succeeded, and the
natural consumer response — retry the mutation — would create a duplicate
assignment or a second definition. This is the same reasoning ADR 0008 already
applied to post-commit audit failures.

Rules:

- exceptions thrown by synchronous listeners are caught and never propagated;
- so is a failure to construct the event itself. An unknown mutation name or a
  violated invariant is a package bug, but it is discovered after the commit,
  so it is reported from the audit record's safe scalar fields and no event is
  dispatched;
- one error-level log is written, with a stable package message and the
  event's `context()` payload plus the caught exception's class name;
- the log carries no snapshots, no metadata, no reason, no credentials, and no
  exception message or stack trace, matching `AuditFailureReporter`;
- if the logger also throws, that too is swallowed. Reporting is best effort
  and cannot invalidate a committed mutation;
- **no `MutationEventDispatchFailed` event is introduced.** It would be
  dispatched through the same dispatcher that just failed, and a listener on it
  could throw again. The recursion is unbounded in principle and pointless in
  practice: a broken dispatcher cannot reliably report that the dispatcher is
  broken. Logs are the correct channel.

Trade-off, stated plainly: a consumer whose listener throws loses the event
with only a log line to show for it. The alternative — throwing — converts a
successful, committed authorization change into an apparent failure, which is
strictly worse. Consumers needing stronger delivery guarantees should use
queued listeners backed by a durable queue and that queue's own retry policy.

**One honest limitation.** GateTrail wraps `Dispatcher::dispatch()`, not
individual listeners. Laravel executes synchronous listeners in sequence and a
throw aborts the remainder, so a throwing listener prevents later listeners for
the same event from running. GateTrail cannot isolate listeners from each other
without reimplementing the dispatcher, which it will not do. This is documented
rather than worked around.

**Queued listeners.** A `ShouldQueue` listener is pushed, not executed, so its
job failures are the queue's concern and GateTrail never sees them. A failure
to push — a dead queue connection — is a dispatch failure and follows the rules
above. GateTrail does not retry dispatch.

## Retry semantics

Bounded concurrency retry is a separate, later Phase 4 item. This ADR fixes how
the two interact so neither is designed into a corner:

- a failed or retried attempt dispatches nothing; the event is constructed only
  after the outermost commit succeeds;
- exactly one event is dispatched for one logical mutation, after the final
  successful commit, no matter how many attempts preceded it;
- exhausted retries throw and dispatch nothing;
- the payload is built solely from the winning attempt's `PreparedMutation` and
  its resolved audit identifier. Discarded attempts contribute nothing, which
  is already structurally true because `PreparedMutation` is immutable and is
  created inside the attempt that produced it;
- the current `StrictMutationTransaction` runs with `attempts: 1`, so retry
  cannot silently exist before it is implemented deliberately.

## Extension and compatibility

- `MutationCommitted` and `GateTrailEvent` are **public API**. Handlers,
  commands, and the orchestrator remain `@internal`.
- Property names and the internal factory's named parameters are compatibility
  surface once v1 is frozen. Property names are what listeners read; the
  factory's parameter names are not consumer-facing because the constructor is
  not public.
- Consumers **may** queue and serialize the event. The payload is scalars, one
  enum, and one `DateTimeImmutable`, all of which serialize deterministically
  and carry no lazily-loaded state.
- Adding an optional field is **not** breaking for listeners, and is safe
  precisely because consumers cannot construct the event positionally. New
  fields append to `context()` rather than reordering it.
- Removing a field, renaming a field, renaming a mutation name, or splitting
  the event into per-mutation classes is **breaking** and requires a major
  version.
- Event names are not versioned independently; they are the audit `event`
  vocabulary, which ADR 0008 already treats as stable public data.
- Event production has **no configuration surface**. There is nothing to enable,
  disable, or misconfigure, so the compatibility contract is the event class and
  its names alone.
- Handlers stay unaware of dispatch. They build a `PreparedMutation` and return
  it. All timing, deferral, and failure policy lives in the orchestrator, which
  is what makes uniform behavior across fourteen mutations testable at one
  point.

## Alternatives considered

### Fourteen mutation-specific event classes

Rejected. See the shape section: identical payloads, fourteen permanent public
contracts, one more per future mutation, and a catch-all that needs a marker
interface anyway.

### Generic envelope plus subclasses

Rejected. Laravel resolves interface listeners but not parent-class listeners,
so the envelope cannot receive subclass dispatches. The repair is a marker
interface, which reduces the option to the rejected fourteen-class design.

### Dispatching inside the mutation transaction

Rejected. A listener would observe state that a later rollback erases, and
`PACKAGE_SPEC.md` section 16 forbids dispatching for a rolled-back mutation.

### Dispatching for `Unchanged` results

Rejected. ADR 0002 states an idempotent replay changed no authorization state
and creates no second audit record, cache clear, or event. Dispatching would
make listeners fire for non-events, and every consumer would immediately need
the filter GateTrail can apply once.

### Carrying the Eloquent model in the payload

Rejected. Mutable, expensive to serialize onto a queue, exposes consumer
columns, and invites listeners to lazily load relations after the transaction
that produced them has closed. Listeners that need the model can load it from
`targetType` and `targetId`.

### Propagating listener exceptions

Rejected. The mutation is committed. Throwing invites a retry that duplicates
authorization state.

### A `MutationEventDispatchFailed` operational event

Rejected as recursively unsafe: it travels through the dispatcher whose
listener just failed.

### Reusing `AuditPersistenceFailed` for dispatch failures

Rejected. It would conflate "no evidence exists" with "an integration did not
run", and its payload is shaped for the audit pipeline.

## Consequences

### Positive

- One public event class, one name vocabulary, one dispatch point, one failure
  policy for all fourteen mutations, and for every mutation added later.
- Event behavior is deterministic across installations because production is
  always on and cannot be configured away.
- One boundary rule covers every audit mode, so a caller-owned transaction
  behaves the same whichever mode is configured.
- The event name set is already the audit vocabulary, so events and audit rows
  join on a value that cannot drift.
- The payload is safe by construction: no models, no snapshots, no metadata, no
  reason, no credentials.
- Caller-owned non-strict mutations can report an audit identifier through the
  event that `MutationResult` structurally could not.
- Handlers remain mode-agnostic and dispatch-agnostic.
- The single combined after-commit callback makes audit, cache, and event
  ordering deterministic without depending on framework callback order.

### Negative

- Listeners discriminate at runtime on a string rather than at compile time on
  a class.
- Static analysis cannot narrow a listener to one mutation's payload.
- A future split into per-mutation classes breaks listeners bound directly to
  `MutationCommitted::class`.
- Uniform boundary rejection makes strict-mode and disabled-mode caller-owned
  mutations fail on connections that cannot defer callbacks, which work today.
- Renaming `UnsupportedAuditBoundary` to `UnsupportedAfterCommitBoundary` is a
  pre-v1 break for anyone already catching it.
- Always-on event production means every installation pays the dispatch cost
  even with no listeners registered, and operators cannot switch it off.
- A throwing listener silently prevents later listeners for the same event, and
  GateTrail reports it only in a log.
- The event is one more public serialization contract to keep stable pre-v1.

### Security consequences

- The event is a lower-trust channel than the audit table: it may be queued,
  logged, or forwarded to third-party integrations, and its payload is
  restricted accordingly.
- Events never replace audit records. Disabled auditing still dispatches
  events, and a consumer must not read that as a history guarantee.
- Actor identity is included so integrations can attribute changes without a
  second query; it is already the normalized, portable identity from ADR 0005
  and carries no application attributes.
- A `null` `auditId` must never be interpreted as a failed mutation.

### Portability consequences

- Deferral uses Laravel's transaction manager, not database savepoints, triggers
  or engine-specific hooks, so behavior is identical across SQLite, MySQL,
  PostgreSQL, SQL Server and Oracle.
- No vendor-specific SQL is introduced.
- The listener-resolution behavior this decision relies on is byte-identical in
  Laravel 12.x and 13.x.

### Future extension points

- The reserved cache-invalidation slot is already ordered.
- Bounded concurrency retry has defined interaction rules.
- Additional optional payload fields are non-breaking.
- The marker interface preserves the only viable path to per-mutation classes.
- A future request-metadata resolver populates `requestId` with no contract
  change.

## Specification alignment

`PACKAGE_SPEC.md` section 16 was updated when this ADR was accepted to point at
it as the authoritative event contract. The existing event rules there are
unchanged and remain correct; this ADR fixes the details section 16 leaves
open — the event class and shape, the mutation name set, the exact payload,
ordering relative to auditing and the reserved cache-invalidation point, and
post-commit listener failure policy.

`ADR 0008` remains correct in substance. Its audit modes, snapshot contract,
and `AuditPersistenceFailed` operational event stand as written; only the
exception it names was renamed to `UnsupportedAfterCommitBoundary`, and the
boundary requirement it describes for non-strict auditing now applies in every
audit mode.

## Implementation status

Implemented. `MutationCommitted`, the `GateTrailEvent` marker interface, and
central dispatch in `AuditedMutationTransaction` are in place, and all fourteen
managed mutations dispatch exactly one event after their outermost successful
commit. The reserved cache-invalidation ordering point exists as a documented
slot in the announcement callback; no cache behavior is implemented. Bounded
concurrency retry is still outstanding, and nothing in the dispatch path
prevents its accepted exactly-once contract.

## Verification

Before this decision is considered implemented, tests must prove:

- each of the fourteen mutations dispatches exactly one event with the correct
  mutation name, outcome, target pair, subject pair, and actor pair;
- subject-scoped mutations carry a subject pair and definition mutations carry
  none;
- strict, non-strict, and disabled modes all dispatch the event;
- `auditId` matches the table above in all six situations;
- GateTrail-owned and caller-owned transactions both dispatch exactly once;
- an outer rollback dispatches nothing and writes no failure log;
- `MutationOutcome::Unchanged` dispatches nothing and registers no callback;
- a non-strict audit failure dispatches `AuditPersistenceFailed` and then
  `MutationCommitted` with a `null` `auditId`;
- callback ordering is audit, then cache, then event, observed rather than
  asserted from source;
- nested transactions and future retries dispatch exactly one event;
- a throwing listener does not propagate, does not roll back, and produces one
  safe error log;
- a throwing logger during that report is swallowed;
- no snapshot, metadata, reason, credential, exception message, or model
  instance appears in the event or its log payload;
- custom string primary keys, UUIDs, and ULIDs round-trip through
  `targetId` and `subjectId` unchanged;
- a caller-owned transaction that cannot defer callbacks is rejected before
  mutation with `UnsupportedAfterCommitBoundary` in strict, non-strict, and
  disabled audit modes alike;
- events are dispatched with no configuration flag involved, and dispatching
  with zero registered listeners is harmless;
- behavior is identical on Laravel 12 and Laravel 13.

## Resolved owner decisions

Approved on 2026-07-31, at which point this ADR moved from Proposed to
Accepted.

1. **Direct-permission mutation names stay as implemented.**
   `permission.granted_to_subject` and `permission.revoked_from_subject` are
   kept. The existing audit vocabulary is not renamed to `*_directly`.
2. **Boundary behavior is uniform and fail-closed.** Every changed mutation
   joining a caller-owned transaction requires reliable outermost after-commit
   semantics in all three audit modes, an unsupported boundary is rejected
   before mutation, and the exception contract is renamed to
   `UnsupportedAfterCommitBoundary` because it now protects deferred audit,
   cache, and event side effects. This increment records the rename only; the
   production class and the current non-strict-only check are unchanged.
3. **Successful mutation events are always enabled.** No `events.enabled`
   configuration is introduced. Dispatching with no registered listeners is
   acceptable, consumers opt in by registering listeners, and event behavior is
   therefore deterministic across installations.

## References

- [Laravel: events and database transactions](https://laravel.com/docs/13.x/events#queued-event-listeners-and-database-transactions)
- [Laravel: database transactions](https://laravel.com/docs/13.x/database#database-transactions)
- [GateTrail ADR 0001: unique active assignments](0001-unique-active-assignments.md)
- [GateTrail ADR 0002: public mutation API](0002-public-mutation-api.md)
- [GateTrail ADR 0005: actor identity representation](0005-actor-identity-representation.md)
- [GateTrail ADR 0008: audit snapshots and failure modes](0008-audit-snapshot-and-failure-modes.md)

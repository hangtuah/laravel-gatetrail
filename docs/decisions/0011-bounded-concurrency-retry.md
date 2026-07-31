# ADR 0011: Retry Only Assignment Coordination, Only on Confirmed Transient Failures

- Status: Proposed
- Date: 2026-07-31
- Decision owners: GateTrail maintainers

## Context

ADR 0001 requires bounded retry for assignment coordination. Its mutation
algorithm says that when one transaction loses the coordination-row insert
race, the loser must "roll back the whole attempt and retry from step 2", and
that "retries must be bounded" with exhaustion raising a package concurrency
exception that "must never silently allow a duplicate".

ADR 0002 constrains how: retries are bounded, reuse the immutable internal
command, and "apply only to classified transient database failures. Domain
validation and authorization exceptions are not retried."

ADR 0010 constrains what must not repeat: a failed attempt dispatches no
event, only the winning committed attempt dispatches one `MutationCommitted`,
exhausted retries dispatch nothing, and post-commit audit or event failures
never retry an already committed mutation.

None of that is implemented. `StrictMutationTransaction` deliberately calls
Laravel's `transaction()` with `attempts: 1`, so no retry can happen by
accident, and `config/gatetrail.php` carries an unused
`assignments.concurrency_attempts` of `3`.

The pieces that make classification possible already exist and are worth
naming, because the design below depends on their exact shape:

- `AssignmentLockStore::acquire()` reads the coordination row
  `FOR UPDATE`, inserts it when absent, re-reads it, and fails closed on a
  canonical-key mismatch. The insert is the only statement in GateTrail that
  can lose a coordination race.
- The lock table has a composite primary key over
  `(assignment_kind, scope_hash)`, named `acl_asgn_locks_pk` where the engine
  permits a named primary key.
- `UniqueConstraintViolationClassifier` already demonstrates the package's
  posture for constraint identity: confirm a named constraint, or confirm the
  exact reported table and column set where the driver reports columns
  instead of a name.
- Definition creation already translates a confirmed namespace violation into
  `DuplicateRole` or `DuplicatePermission`, which are terminal domain
  outcomes.

The open question is not whether to retry. It is exactly which failures are
retryable, where the loop lives so that ADR 0010's "exactly once" survives,
and what happens when GateTrail did not open the transaction it would need to
restart.

## Decision

GateTrail retries **only the six `AssignmentManager` mutations**, **only when
GateTrail owns the outermost transaction**, and **only for failures it has
positively classified as transient**, bounded by
`assignments.concurrency_attempts`.

Everything else — definition mutations, unclassified failures, domain
exceptions, and any failure discovered after a successful commit — is not
retried.

## 1. Scope

Retryable:

| Manager | Method |
|---|---|
| `AssignmentManager` | `assignRole()` |
| `AssignmentManager` | `revokeRole()` |
| `AssignmentManager` | `grantDirectPermission()` |
| `AssignmentManager` | `revokeDirectPermission()` |
| `AssignmentManager` | `grantPermissionToRole()` |
| `AssignmentManager` | `revokePermissionFromRole()` |

Not retryable: `RoleManager::create/update/deactivate/restore()` and
`PermissionManager::create/update/deactivate/restore()`.

The retry requirement originates entirely from assignment-scope coordination.
Only assignment mutations acquire a coordination row, so only they can lose
the insert race that ADR 0001 tells the loser to retry.

Definition mutations stay outside for a stronger reason than "no coordination
row". Their expected race is **semantic, not transient**. Two concurrent
`RoleManager::create('reviewer', 'web', …)` calls race on the
`acl_role_name_guard_uq` namespace, and exactly one must lose *permanently*:
the namespace is now occupied, so retrying would re-run the pre-check, find
the winner's row, and raise `DuplicateRole` anyway. Retrying a guaranteed
failure spends a transaction to arrive at the same deterministic answer more
slowly. The existing translation through
`UniqueConstraintViolationClassifier` is already the correct terminal
behaviour, and this ADR must not turn it into a retry candidate.

Definition mutations can in principle still deadlock. In the initial
implementation they surface that as the underlying `QueryException`.
Deadlock-only retry for definition mutations is recorded as a future
extension, not accepted here, because it has no evidence of being needed and
would widen the blast radius of the first retry implementation.

## 2. Retry boundary

The loop reruns the **whole mutation transaction attempt** and nothing else.

Runs once, before the loop, because it is pure, immutable and cannot be
invalidated by a concurrent writer:

- metadata normalization;
- actor identity resolution;
- reason validation;
- assignment period normalization;
- the immutable internal command itself, which is captured once with one
  clock reading and reused verbatim by every attempt.

Runs again on every attempt, because each step reads state a competing
transaction may have changed:

1. begin the transaction;
2. resolve transaction-bound role, permission and assignment references;
3. acquire or insert the coordination row and re-read it under a write lock;
4. verify the canonical key against the stored hash;
5. lock and read equivalent assignment rows;
6. make the assignment decision (create, unchanged, or conflict);
7. persist the mutation;
8. persist the audit record when strict auditing is enabled;
9. commit.

Runs at most once, strictly outside the loop, only after a committed attempt:

- non-strict audit persistence in its own transaction;
- the reserved cache-invalidation point;
- `MutationCommitted` dispatch.

The loop therefore sits **inside** `AuditedMutationTransaction`, wrapping the
step that opens and commits the mutation transaction — not around
`AuditedMutationTransaction::run()`. Wrapping the outer call would place the
after-commit announcement inside the retried region, which ADR 0010 forbids.
Because the loop is inner, a discarded attempt provably cannot announce
anything: the announcement code is not reachable until the loop has produced
a committed attempt.

`StrictMutationTransaction` keeps `attempts: 1`. Attempt counting belongs to
one place, and Laravel's own retry loop cannot distinguish a coordination
race from a foreign-key violation.

## 3. Audit-mode interaction

**Strict.** The audit write is inside the retried transaction, so an attempt
either commits mutation and audit together or leaves neither. A discarded
attempt leaves no partial row and no orphan audit. `AuditWriteFailed` is a
GateTrail exception, not a classified transient database failure, so a strict
audit failure is never retried — it rolls back and throws, exactly as today.

**Non-strict.** Audit persistence happens after the winning mutation commits,
in its own transaction, and is already contained: a failure there is reported
through `AuditPersistenceFailed` and a safe log, never rethrown. It must never
cause the mutation to retry, and structurally it cannot, because it runs
outside the loop and swallows its own failure.

**Disabled.** Only the mutation transaction is retried. There is no audit
work to repeat.

**Invariant across all three.** One logical successful changed mutation
produces at most one after-commit announcement: at most one non-strict audit
attempt, at most one cache invalidation, at most one `MutationCommitted`.
Failed attempts contribute nothing. Exhaustion throws before any of it.

## 4. Caller-owned transactions

**GateTrail does not retry inside a caller-owned transaction.**

A nested `Connection::transaction()` is a savepoint, not a transaction.
Rolling back to a savepoint does not restore the isolation snapshot the
attempt needs, does not release the caller's earlier locks, and does not undo
work the caller performed before calling GateTrail. On PostgreSQL the point is
sharper still: a constraint violation aborts the entire outer transaction, so
every subsequent statement fails with `25P02` until the caller rolls back.
Retrying only the savepoint would replay GateTrail's step while the caller's
surrounding work stays in an aborted or inconsistent state.

Accepted rule:

- **the first attempt is still allowed.** A caller-owned assignment mutation
  runs exactly once, with full coordination locking. Nothing about correctness
  changes; only recovery does;
- a **classified transient failure** during that attempt is surfaced as
  `ConcurrencyRetryExhausted` with `attempts: 1` and `callerOwned: true`,
  preserving the classified database exception as `previous`;
- an **unclassified** failure propagates unchanged, as it does today;
- the caller decides what to do, because only the caller can safely restart
  its own transaction;
- after-commit callbacks continue to belong only to a successful attempt, and
  an outer rollback discards them through the mechanism ADR 0010 already
  relies on.

This is fail-closed: GateTrail never silently degrades a coordination
guarantee, and never claims a retry it cannot perform.

## 5. Classification matrix

Classification is **positional first, code-based second**. That ordering is
the core safety property of this design.

**Positionally scoped.** Only the exception raised by the coordination-row
`INSERT` inside `AssignmentLockStore::acquire()` can be classified as a
coordination race. The store catches the `QueryException` from that one
statement, asks the classifier to confirm the expected identity, and rethrows
an internal marker carrying the original as `previous`. A unique violation
raised anywhere else in the attempt — a role namespace collision, an
application table, a consumer trigger — is structurally ineligible, without
depending on message text to tell them apart.

**Code-based.** Deadlock and serialization failures can be raised by any
statement in the attempt, so they are classified from SQLSTATE and vendor code
alone, with no identity anchor available or needed.

### Coordination-row insert race

The expected identity is the runtime-resolved lock table and its physical
primary key. `SchemaContext::tableName()` already applies the configured
prefix, and `SchemaContext::table()` adds the schema qualifier when one is
configured, so the classifier compares against resolved names and never
against the literal `acl_assignment_locks`. Column-identity matching uses the
unqualified table name, because SQLite reports `table.column` without a schema
prefix.

| Driver | SQLSTATE | Code | Confirmed identity | Reliability |
|---|---|---|---|---|
| PostgreSQL | `23505` | — | constraint name `acl_asgn_locks_pk` in `errorInfo[2]` | structured |
| Oracle | `23000` | `ORA-00001` | constraint name `ACL_ASGN_LOCKS_PK`, case-insensitive, optional schema prefix | structured |
| SQL Server | `23000` | `2627` | constraint name `acl_asgn_locks_pk` in the driver message | structured |
| SQLite | `23000` | `19` | reported columns equal `{table}.assignment_kind` and `{table}.scope_hash` | structured |
| MySQL / MariaDB | `23000` | `1062` | key name plus resolved table name | **narrow documented fallback** |

MySQL is the one genuine gap and it is stated rather than hidden. MySQL cannot
name a primary key: `MySqlAdapter::physicalPrimaryKeyName()` returns
`PRIMARY` for every table, and the key name appears only in the driver
message — qualified as `` `table`.`PRIMARY` `` from MySQL 8.0.19 and as bare
`'PRIMARY'` before it. Message inspection is therefore unavoidable on MySQL,
and it is made narrow and safe by three conditions that must all hold:

1. the exception was raised by the coordination insert, positionally;
2. SQLSTATE is `23000` and the vendor code is `1062`;
3. the message names `PRIMARY`, and additionally names the resolved lock table
   where the server qualifies it.

Because condition 1 already restricts the candidate set to a single statement
against a single table whose only unique constraint is that primary key,
conditions 2 and 3 are confirmation rather than identification. The fallback
is recorded as the only message-dependent rule in the classifier.

### Deadlock and serialization failure

| Driver | Condition | SQLSTATE | Code | Retryable |
|---|---|---|---|---|
| PostgreSQL | serialization failure | `40001` | — | yes |
| PostgreSQL | deadlock detected | `40P01` | — | yes |
| MySQL / MariaDB | deadlock, transaction rolled back | `40001` | `1213` | yes |
| SQL Server | deadlock victim | `40001` | `1205` | yes |
| Oracle | deadlock detected | `61000` | `ORA-00060` | yes |
| Oracle | cannot serialize access | `72000` | `ORA-08177` | yes |

Each is a failure the database itself resolved by discarding one transaction.
The competing transaction is already gone, so an immediate retry has a clean
field.

### Deliberately not retryable in the initial implementation

| Condition | Driver examples | Reason |
|---|---|---|
| Lock wait timeout | MySQL `1205` / `HY000`, SQL Server `1222`, Oracle `ORA-00054` | A timeout means a competing transaction is still holding the lock. Immediate retry makes contention worse; safe handling needs backoff, which section 7 defers. |
| Database busy / locked | SQLite `SQLITE_BUSY` (5), `SQLITE_LOCKED` (6) | Same reasoning. SQLite is a single-writer test database, not the reference production engine. |
| Any other unique violation | role and permission namespace constraints, consumer constraints | Semantic, terminal, already translated to typed domain exceptions. |
| Foreign-key violation | `23503`, MySQL `1452`/`1451`, Oracle `ORA-02291` | Indicates missing or removed referenced state; a retry repeats the same failure. |
| NOT NULL / check violation | `23502`, `23514`, Oracle `ORA-01400`, `ORA-02290` | A package or schema defect, deterministic. |
| Syntax, missing table, permission, connectivity | `42S02`, `42000`, `08006`, `28000` | Infrastructure or configuration faults, deterministic. |
| Arbitrary `QueryException` | — | Unclassified means not retryable. |
| Any other `Throwable` | — | Never inspected, never retried. |

No condition is retryable merely because it is a `23000`, a `40001`, an
`HY000`, an `ORA-00001`, or an
`Illuminate\Database\UniqueConstraintViolationException`. `40001` is the one
family that is retryable on code alone, because every engine reserves it for
serialization and deadlock rollback. Every other family requires confirmed
identity.

## 6. Exhaustion exception

```php
final class ConcurrencyRetryExhausted extends RuntimeException
{
    public readonly string $operation;   // 'role.assigned'
    public readonly int $attempts;       // attempts actually made
    public readonly bool $callerOwned;   // true when retry was unavailable
}
```

Named constructors: `afterAttempts(string $operation, int $attempts, Throwable $previous)`
and `insideCallerTransaction(string $operation, Throwable $previous)`.

Messages:

```text
GateTrail could not complete [role.assigned] after 3 concurrent attempts.
GateTrail could not complete [role.assigned] inside a caller-owned transaction under concurrent load.
```

Contract:

- `operation` is the mutation's canonical name — the same vocabulary ADR 0010
  and the audit `event` column already use. It identifies the logical
  operation without naming a subject, role, permission, table or column;
- no SQL, no bindings, no identifiers, no credentials, no driver message and
  no driver code enters the message or the public properties;
- the final classified database exception is preserved as `previous`, as
  required, so operators keep the diagnostic chain;
- domain exceptions are never wrapped. `DuplicateActiveAssignment`,
  `AssignmentNotActive`, `AssignmentScopeCollision`, `GuardMismatch`,
  `RoleNotFound`, `PermissionNotFound`, `ReasonRequired`, `InvalidActor`,
  `AuditWriteFailed` and every other GateTrail exception propagate unchanged
  and end the attempt immediately.

**Security note, stated rather than assumed.** Preserving the driver exception
as `previous` is an explicit instruction and this ADR follows it, but
`QueryException::getMessage()` contains the executed SQL and, depending on the
Laravel version, its bindings. That is a deliberate departure from the posture
`DuplicateRole` takes, where no previous exception is attached at all.
Consequences: GateTrail's own operational logging must never serialize the
exception chain, and consumers whose global handler logs full chains will see
GateTrail SQL in their logs. This is the one point in the design where
diagnostic value was chosen over minimal disclosure, and the owner should
confirm it.

**When `concurrency_attempts` is `1`.** One attempt is made and no retry is
possible. A classified transient failure raises `ConcurrencyRetryExhausted`
with `attempts: 1`. It does **not** rethrow the raw `QueryException`: a value
of `1` means "do not retry", not "disable GateTrail's exception contract", and
consumers must not have to handle a driver exception because of a
configuration value.

## 7. Configuration validation

`assignments.concurrency_attempts` is validated fail-closed, in the shape
`AuditModeResolver` already established:

- accepted type: a real PHP `int`;
- accepted range: `1` to `10` inclusive;
- rejected without coercion: `true`, `false`, `3.0`, `'3'`, `null`, arrays,
  objects, `0`, negatives, and anything above `10`;
- rejection raises `InvalidConcurrencyConfiguration`, carrying the
  configuration key only and never the rejected value, mirroring
  `InvalidAuditConfiguration`;
- validation happens before any mutation transaction opens.

The maximum of `10` is deliberate. Every attempt is a full transaction that
takes coordination and row locks, so a large ceiling turns a contended scope
into a retry storm that starves the very writer it is waiting for. Ten is far
beyond any legitimate coordination race, where the loser normally succeeds on
its second attempt because the winner's row is now present.

**Backoff is not part of the initial implementation.** Both retryable classes
argue against it:

- a coordination insert race means the winner has already inserted the row, so
  the loser's next attempt finds it immediately. Sleeping adds latency to a
  contention window that has already closed;
- a deadlock or serialization rollback means the database already discarded
  one transaction. The conflicting party is gone.

Backoff becomes necessary precisely for the class this ADR excludes — lock
timeouts and busy conditions, where a competing transaction is still holding
the lock. Backoff and that class are therefore recorded as one paired future
extension: if lock timeouts are ever made retryable, bounded exponential
backoff with jitter and an injectable sleeper must land in the same change,
with tests using a deterministic fake sleeper that records requested delays
instead of sleeping.

## 8. Architectural components

Four new pieces, each with one job:

| Component | Responsibility |
|---|---|
| `RetryPolicy` | Reads and validates `assignments.concurrency_attempts`; exposes the bounded attempt count. |
| `TransientFailureClassifier` | Driver-aware, structured classification of deadlock and serialization failures. Knows SQLSTATE and vendor codes; knows nothing about retry counting. |
| `CoordinationRaceClassifier` | Confirms that one specific exception is a coordination-row insert race against the resolved lock table and its physical primary key. |
| `ConcurrencyRetryExhausted` | The typed public exhaustion exception. |

`UniqueConstraintViolationClassifier` is **not** extended. It answers one
narrow question — "is this a violation of that exact named constraint?" — and
its answer is currently used to produce a terminal domain exception. Teaching
it transient-failure semantics would put "this is permanent" and "this is
retryable" behind one class, which is the exact confusion this ADR exists to
prevent. `CoordinationRaceClassifier` may reuse its matching helpers, but the
two answer different questions and stay separate.

**Opting in without duplicating six handlers.** `AuditedMutationTransaction`
gains one additional entry point:

```php
public function run(Closure $operation): MutationResult;            // unchanged
public function runCoordinated(Closure $operation): MutationResult; // retrying
```

The six assignment handlers change one call site each, from `run()` to
`runCoordinated()`. No handler gains a loop, an attempt counter, a SQLSTATE,
a vendor code, or a driver branch. Definition handlers keep calling `run()`
and are structurally incapable of retrying. A named method is preferred over a
boolean parameter because the call site then states what it means.

## 9. Retry state machine

```text
                    ┌─────────────────────────────┐
                    │ validate once (pure inputs) │
                    │ capture immutable command   │
                    └──────────────┬──────────────┘
                                   │
                     caller-owned transaction? ──── yes ──┐
                                   │ no                   │
                                   ▼                      ▼
                        ┌──────────────────┐   ┌──────────────────────┐
                   ┌───▶│  attempt begins  │   │ single attempt only  │
                   │    └────────┬─────────┘   └──────────┬───────────┘
                   │             │                        │
                   │   resolve → lock → decide            │
                   │   → persist → audit (strict)         │
                   │             │                        │
                   │      ┌──────┴───────┐                │
                   │      │              │                │
                   │   commit         throws              │
                   │      │              │                │
                   │      ▼              ▼                ▼
                   │  ┌────────┐   classify?         classify?
                   │  │ WINNER │        │                 │
                   │  └───┬────┘   ┌────┴─────┐      ┌────┴──────┐
                   │      │        │          │      │           │
                   │      │   retryable   not retryable   retryable / not
                   │      │        │          │      │           │
                   │      │   attempts       throw    ConcurrencyRetryExhausted
                   │      │   remaining?   unchanged   (attempts: 1,
                   │      │    │      │   (domain or    callerOwned: true)
                   │      │  yes     no    unknown)   / throw unchanged
                   └──────┼──┘       │
                          │          ▼
                          │   ConcurrencyRetryExhausted
                          │   (attempts: configured)
                          ▼
              ┌───────────────────────────────┐
              │ AFTER COMMIT — runs at most   │
              │ once, never inside the loop   │
              │  1. non-strict audit attempt  │
              │  2. cache invalidation (slot) │
              │  3. MutationCommitted         │
              └───────────────────────────────┘
```

Every discarded attempt leaves the database as it found it and reaches none of
the after-commit block. Exhaustion and unclassified failures throw before it.

## 10. Portability and testing

**Unit, no database required.** Classification is a pure function of
`QueryException` state, so the matrix is tested from recorded golden vectors —
one per driver per condition, carrying the real `errorInfo` triple and message
that engine produces. This is what lets every contributor run the full
classification suite on SQLite alone.

Required unit evidence:

- every row of the section 5 matrix classified correctly, per driver;
- coordination-race confirmation against the resolved lock table and physical
  primary key, including a configured table prefix and a configured schema
  qualifier;
- false-positive rejection: a role namespace violation, a permission namespace
  violation, a consumer-table unique violation, a primary-key collision on
  another GateTrail table, a foreign-key violation, a NOT NULL violation, a
  missing-table failure, and an arbitrary `QueryException` are all
  non-retryable;
- the MySQL fallback rejects a `1062` naming `PRIMARY` on a different table;
- `concurrency_attempts` validation across `int`, `bool`, `float`,
  numeric string, `null`, array, object, `0`, negative, `1`, `10`, `11`;
- `ConcurrencyRetryExhausted` carries no SQL, bindings, identifiers or driver
  message, and preserves `previous`.

Required integration evidence, SQLite by default:

- success on the first attempt makes exactly one attempt;
- an injected classified failure on attempt 1 then success on attempt 2 yields
  one committed mutation, one audit row, one event;
- exhaustion raises `ConcurrencyRetryExhausted` with the configured count and
  leaves no mutation, no audit row and no event;
- the same immutable command instance is reused by every attempt, and the
  captured timestamp does not change between attempts;
- domain exceptions are never retried — one attempt only, for
  `DuplicateActiveAssignment`, `AssignmentNotActive`, `AssignmentScopeCollision`,
  `GuardMismatch` and `ReasonRequired`;
- an unknown infrastructure `QueryException` is never retried;
- strict mode leaves neither mutation nor audit after a discarded attempt;
- non-strict audit failure never triggers a mutation retry;
- disabled auditing retries the mutation transaction only;
- a caller-owned transaction makes one attempt and raises
  `ConcurrencyRetryExhausted` with `callerOwned: true`;
- zero `MutationCommitted` for failed attempts, exactly one for the winner,
  zero on exhaustion;
- no duplicate audit rows across retried attempts;
- all four definition managers remain single-attempt.

Required real-concurrency evidence where infrastructure allows: parallel
writers against one assignment scope on the disposable MySQL service produce
exactly one active assignment, with the loser either succeeding on retry or
failing closed. Oracle, PostgreSQL and SQL Server coverage runs in CI; the
golden-vector suite keeps their classification honest locally.

## 11. Consequences

### Positive

- Concurrent writers to one assignment scope recover automatically instead of
  surfacing a driver exception.
- The retryable set is small, positively identified, and defended by
  false-positive tests.
- Positional scoping means a definition namespace collision can never be
  mistaken for a coordination race, whatever a driver's message says.
- ADR 0010's exactly-once guarantee is preserved structurally, not by
  convention: after-commit work is unreachable from a failed attempt.
- Handlers stay free of driver codes and attempt counters.
- Caller-owned behaviour is honest about what a savepoint cannot do.

### Negative

- MySQL coordination-race confirmation depends on message inspection, because
  MySQL cannot name a primary key.
- Lock timeouts and SQLite busy conditions are not recovered in the initial
  implementation.
- Definition mutations gain no deadlock recovery.
- `AuditedMutationTransaction` gains a second entry point, so "which method
  does this handler call" becomes a thing to get right.
- Preserving the driver exception as `previous` widens what a consumer's
  global exception logger can print.
- Golden vectors must be refreshed when a driver changes its error surface.

### Neutral and operational

- `ConcurrencyRetryExhausted` is a load signal; `callerOwned: true` is a usage
  signal. They warrant different operational responses and are distinguishable
  without parsing a message.
- Default behaviour changes for existing installations: three attempts instead
  of one. This is what the shipped configuration always advertised.
- Retry adds no new configuration keys.
- No cache behaviour, event semantics, migration, DBA artifact, public manager
  signature or identifier-length policy changes. `acl_asgn_locks_pk` is
  seventeen characters and remains inside the thirty-character limit.

## 12. Alternatives rejected

**Laravel `transaction(attempts > 1)` applied globally.** Laravel retries on
any `Throwable` whose message suggests a deadlock, across every mutation, with
no identity confirmation. It would retry definition namespace collisions and
would place after-commit work at risk of repetition. `attempts: 1` stays.

**Retrying every `QueryException`.** Turns deterministic faults — missing
table, bad credentials, NOT NULL violation — into three identical failures and
a misleading exhaustion exception, while masking the real cause.

**Retrying all fourteen mutations.** Definition races are semantic and
terminal. Retrying them re-derives the same `DuplicateRole` more slowly.

**Retrying after commit, or after an audit or event failure.** Directly
forbidden by ADR 0010 and by the containment already implemented for
post-commit audit and event failures. The mutation is durable; a retry would
duplicate authorization state.

**Silently returning a domain conflict on exhaustion.** ADR 0001 requires
exhaustion to fail closed and never silently allow a duplicate. Returning
`DuplicateActiveAssignment` would misreport a load problem as a semantic one
and hide the contention from operators.

**Retrying inside a caller-owned outer transaction.** Only the savepoint could
be replayed. The caller's earlier work, locks and — on PostgreSQL — aborted
transaction state remain, so the replay would not be a fresh attempt.

**Unbounded retry.** Converts contention into a livelock that starves the
writer it waits for. ADR 0001 requires bounded retry.

**Driver-message-only classification.** Messages are unversioned, localizable
and vendor-specific. Message inspection appears exactly once here — the MySQL
primary-key name — behind positional scoping and code confirmation, and is
documented as the single exception.

## Specification alignment

If this ADR is accepted, two documentation updates follow and are **not** made
by this proposal:

- `PACKAGE_SPEC.md` section 15 should add `ConcurrencyRetryExhausted` and
  `InvalidConcurrencyConfiguration` to the exception list;
- `PACKAGE_SPEC.md` section 17 should note that
  `assignments.concurrency_attempts` is a bounded integer from `1` to `10`.

No contradiction with the accepted specification was found. ADR 0001's adapter
table describes MySQL and SQL Server recovery after "a duplicate-key" failure;
this ADR narrows that to a duplicate key **on the coordination row's primary
key, confirmed positionally**, which is a refinement of ADR 0001's intent
rather than a departure from it, since only the coordination row's insert can
lose the race the algorithm describes.

## Open questions requiring owner approval

1. **Driver exception as `previous`.** Instructed and followed, but it exposes
   SQL — and possibly bindings — to any handler that logs the exception chain,
   unlike `DuplicateRole`, which attaches nothing. Confirm, or accept a safe
   summary instead of the raw exception.
2. **Lock timeouts and SQLite busy conditions.** Excluded here and paired with
   backoff as a future extension. Confirm that no environment needs them in
   the first implementation.
3. **Definition deadlock recovery.** Excluded here. Confirm that definition
   mutations may surface a raw `QueryException` on deadlock until a later
   increment.

## References

- [GateTrail ADR 0001: unique active assignments](0001-unique-active-assignments.md)
- [GateTrail ADR 0002: public mutation API](0002-public-mutation-api.md)
- [GateTrail ADR 0008: audit snapshots and failure modes](0008-audit-snapshot-and-failure-modes.md)
- [GateTrail ADR 0010: after-commit mutation events](0010-after-commit-mutation-events.md)
- [Laravel: database transactions](https://laravel.com/docs/13.x/database#database-transactions)

# ADR 0011: Retry Only Assignment Coordination, Only on Confirmed Transient Failures

- Status: Accepted
- Date: 2026-07-31
- Owner approval date: 2026-08-02
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
  carrying the safe classified fields defined in section 6, with an empty
  `previous` chain and no driver exception anywhere;
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
statement, asks `CoordinationRaceClassifier` to confirm the expected identity,
and — only on confirmation — rethrows the internal `CoordinationInsertRace`
marker described in section 8. A unique violation raised anywhere else in the
attempt — a role namespace collision, another GateTrail table, a consumer
table or trigger — is structurally ineligible, whatever any driver's
diagnostic text says.

**Code-based.** Deadlock and serialization failures can be raised by any
statement in the attempt, so they are classified from SQLSTATE and vendor code
alone, with no identity anchor available or needed.

### What PDO actually exposes

An earlier draft of this ADR claimed that PostgreSQL, Oracle and SQL Server
expose constraint identity structurally and that only MySQL needed message
inspection. That is wrong and is corrected here, because the whole safety
argument depends on being honest about it.

Through PDO:

- **SQLSTATE and the numeric vendor code are structured.** They arrive as
  `errorInfo[0]` and `errorInfo[1]` and are stable, locale-independent, and
  safe to switch on.
- **Constraint identity is not.** No supported driver surfaces the violated
  constraint, table or column set as a dedicated PDO field. On PostgreSQL,
  Oracle, SQL Server and MySQL alike, the constraint name reaches PHP only
  inside driver diagnostic text — `errorInfo[2]` and, through Laravel, the
  `QueryException` message. SQLite reports violated columns rather than a
  name, also as text.

So **every** driver requires narrow diagnostic-text parsing to confirm
identity. Diagnostic text is never sufficient by itself, on any driver. The
rules below therefore apply uniformly:

1. **Positional eligibility first.** The exception must come from the exact
   coordination `INSERT`. This is the primary safety boundary and the only one
   that does not depend on text at all.
2. **Structured agreement second.** SQLSTATE and, where the driver provides
   one, the numeric vendor code must match the expected values.
3. **Identity confirmation third.** The expected constraint name — or, on
   SQLite, the expected column set — must be found in the diagnostic text
   using identifier-token boundaries, never a substring search.
4. All three must agree. Any one of them failing means "not a coordination
   race", and the original exception propagates unchanged.

Token matching rules:

- matching is case-insensitive where the engine folds identifier case, which
  covers Oracle's upper-cased names and PostgreSQL's lower-cased names;
- the match is bounded by non-identifier characters, so `acl_asgn_locks_pk`
  never matches `acl_asgn_locks_pk_2`, `x_acl_asgn_locks_pk`, or any other
  similar-prefix or similar-suffix constraint;
- schema-qualified (`gatetrail.acl_asgn_locks_pk`), table-qualified
  (`acl_assignment_locks.PRIMARY`) and quoted forms — double quotes,
  backticks, brackets — are each accepted, narrowly and explicitly;
- names are compared against the runtime-resolved values.
  `SchemaContext::tableName()` applies the configured prefix and
  `SchemaContext::table()` adds the schema qualifier, so the classifier never
  compares against the literal `acl_assignment_locks`. SQLite column matching
  uses the unqualified table name, because SQLite reports `table.column`
  without a schema prefix;
- **no locale-dependent prose is ever part of the identity.** Phrases such as
  "duplicate key", "duplicate entry", "unique constraint failed" or
  "violates unique constraint" are translatable and version-dependent. They
  may be absent from a matcher entirely; the classification rests on
  SQLSTATE, vendor code, and the identifier token.

### Coordination-row insert race

| Driver | SQLSTATE | Vendor code | Identity token to confirm | Where the token appears |
|---|---|---|---|---|
| PostgreSQL | `23505` | — | `acl_asgn_locks_pk` | diagnostic text |
| Oracle | `23000` | `1` (`ORA-00001`) | `ACL_ASGN_LOCKS_PK`, optional schema prefix | diagnostic text |
| SQL Server | `23000` | `2627` | `acl_asgn_locks_pk` | diagnostic text |
| SQLite | `23000` | `19` | `{table}.assignment_kind` and `{table}.scope_hash` | diagnostic text |
| MySQL / MariaDB | `23000` | `1062` | `PRIMARY`, plus the resolved table where the server qualifies it | diagnostic text |

The "where the token appears" column deliberately reads the same for every
row: there is no driver where PDO hands GateTrail a constraint field.

**MySQL remains the weakest row**, and the reason is specific rather than
general. MySQL cannot name a primary key at all —
`MySqlAdapter::physicalPrimaryKeyName()` returns `PRIMARY` for every table —
so the token is not unique to GateTrail's lock table. MySQL 8.0.19 and later
qualify it as `` `acl_assignment_locks`.`PRIMARY` ``, which restores
uniqueness; older servers report bare `'PRIMARY'`, which does not.

Positional scoping is what makes the older-server case acceptable. Condition 1
has already restricted the candidate set to a single statement, against a
single table, whose only unique constraint is that primary key. On that
statement, `23000` + `1062` + `PRIMARY` cannot describe anything else.
Identity confirmation is therefore doing confirmation work, not
identification work — which is true on every driver here, and is the reason
positional scoping is the primary boundary rather than a convenience.

### Deadlock and serialization failure

| Driver | Condition | SQLSTATE | Code | Retryable |
|---|---|---|---|---|
| PostgreSQL | serialization failure | `40001` | — | yes |
| PostgreSQL | deadlock detected | `40P01` | — | yes |
| MySQL / MariaDB | deadlock, transaction rolled back | `40001` | `1213` | yes |
| SQL Server | deadlock victim | `40001` | `1205` | yes |
| Oracle | deadlock detected | `61000` | `ORA-00060` | yes |
| Oracle | cannot serialize access | `72000` | `ORA-08177` | yes |

Each is a failure the database itself resolved by rolling back **this**
transaction. That is the precise guarantee, and it is narrower than it may
look: the victim's own work is gone, but the surviving transaction may still
be running and still holding locks. A serialization or deadlock winner will
often have committed by the time the victim retries, but that is a common
case, not a property of every deadlock surface. An immediate retry may
therefore block on ordinary lock acquisition rather than proceeding
immediately — which is correct behaviour, not a failure.

### Deliberately not retryable in the initial implementation

| Condition | Driver examples | Reason |
|---|---|---|
| Lock wait timeout | MySQL `1205` / `HY000`, SQL Server `1222`, Oracle `ORA-00054` | Accepted owner direction: excluded from the first implementation. A timeout means a competing transaction is still holding the lock, so recovery needs bounded backoff, which section 7 defers. Propagates as its original infrastructure exception. |
| Database busy / locked | SQLite `SQLITE_BUSY` (5), `SQLITE_LOCKED` (6) | Accepted owner direction: excluded from the first implementation, for the same reason. Propagates as its original infrastructure exception. |
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

**Excluded conditions stay safe.** A lock timeout or a SQLite `BUSY`/`LOCKED`
condition aborts the attempt before commit, so it can never leave a partial
mutation or duplicate authorization state — it fails closed, exactly like any
other unclassified infrastructure failure. Excluding them defers *automatic
recovery*, not correctness, and it says nothing about SQLite support
generally: GateTrail's persisted-state guarantees on SQLite are unchanged and
remain supported.

## 6. Exhaustion exception

```php
final class ConcurrencyRetryExhausted extends RuntimeException
{
    public readonly string $operation;   // 'role.assigned'
    public readonly int $attempts;       // attempts actually made
    public readonly bool $callerOwned;   // true when retry was unavailable

    public readonly string $driver;      // 'mysql' | 'pgsql' | 'sqlsrv' | 'oracle' | 'sqlite'
    public readonly string $category;    // 'coordination_race' | 'deadlock' | 'serialization_failure'
    public readonly ?string $sqlState;   // when the driver supplied one
    public readonly ?int $vendorCode;    // when the driver supplied one
}
```

Named constructors take only safe scalars and a classified value:
`afterAttempts(string $operation, int $attempts, ClassifiedConcurrencyFailure $failure)`
and `insideCallerTransaction(string $operation, ClassifiedConcurrencyFailure $failure)`.
Neither ever receives a `QueryException`.

Messages:

```text
GateTrail could not complete [role.assigned] after 3 concurrent attempts.
GateTrail could not complete [role.assigned] inside a caller-owned transaction under concurrent load.
```

Contract:

- `operation` is the mutation's canonical name, taken from the shared
  vocabulary in section 8. It identifies the logical operation without naming
  a subject, role, permission, guard, reason, table or column;
- the four diagnostic fields are copied from the last classified failure and
  are plain scalars;
- no SQL, no bindings, no identifiers, no table or constraint names, no driver
  prose, no reason, no metadata and no credentials enter the message or any
  public property;
- **`getPrevious()` is always `null`.** See below;
- domain exceptions are never wrapped. `DuplicateActiveAssignment`,
  `AssignmentNotActive`, `AssignmentScopeCollision`, `GuardMismatch`,
  `RoleNotFound`, `PermissionNotFound`, `ReasonRequired`, `InvalidActor`,
  `AuditWriteFailed` and every other GateTrail exception propagate unchanged
  and end the attempt immediately.

### No previous exception at all

Two earlier drafts got this wrong in two different ways. The first preserved
the raw `QueryException` as `previous`; that was rejected because
`QueryException::getMessage()` carries the executed SQL and, in several
Laravel versions, its bindings, which can contain a subject identifier, an
assignment reason or consumer metadata. The second replaced it with a
sanitized `Throwable` cause. That is also rejected, for a reason specific to
PHP:

- every `Throwable` carries its own stack trace, created at construction;
- whether a trace retains function *arguments* depends on runtime
  configuration such as `zend.exception_ignore_args`, which GateTrail does not
  control and must not assume;
- GateTrail therefore cannot promise that no reference to the classification
  input survives inside a `Throwable` it constructs near that input;
- and this ADR will not claim GateTrail can strip or rewrite a `Throwable`'s
  trace safely, because that is not a guarantee a library can make.

A non-`Throwable` scalar value plus an empty `previous` chain is both simpler
and stronger. The classified failure is therefore an ordinary immutable
object, not an exception:

```php
/** @internal */
final readonly class ClassifiedConcurrencyFailure
{
    public string $driver;    // 'mysql' | 'pgsql' | 'sqlsrv' | 'oracle' | 'sqlite'
    public string $category;  // 'coordination_race' | 'deadlock' | 'serialization_failure'
    public ?string $sqlState; // when the driver supplied one
    public ?int $vendorCode;  // when the driver supplied one
}
```

Rules:

- it is **not** an `Exception` or a `Throwable`, and is never thrown;
- it is constructed only by `TransientFailureClassifier` or
  `CoordinationRaceClassifier`, and only after a positive classification;
- it stores **only** those four scalars. No `QueryException`, no `Throwable`,
  no `Closure`, no SQL, no bindings, no driver message, no stack, no table,
  constraint, column or identifier, and no model;
- `TransientFailureClassifier` returns one for a confirmed deadlock or
  serialization failure and `null` otherwise;
- `CoordinationRaceClassifier` returns one only for the exact confirmed
  coordination insert race;
- after classification the raw exception is simply allowed to fall out of
  scope. It is not stored, chained, logged, or passed to any constructor;
- the retry orchestrator retains only the latest `ClassifiedConcurrencyFailure`
  between attempts, overwriting the previous one;
- on exhaustion the orchestrator copies its four scalars into
  `ConcurrencyRetryExhausted`, which then has no previous exception.

Terminal behaviour by path:

| Path | `attempts` | `callerOwned` | Diagnostic fields | `getPrevious()` |
|---|---|---|---|---|
| Owned, attempts exhausted | configured count actually used | `false` | from the last classified failure | `null` |
| Caller-owned, confirmed retryable failure | `1` | `true` | from that single classified failure | `null` |

Two supporting rules follow:

- **unclassified failures are never wrapped.** An arbitrary or unclassified
  `QueryException` is not a concurrency exhaustion and never becomes one. It
  propagates unchanged, with its own `previous` chain intact, exactly as
  today;
- **GateTrail does not log the raw classified exception.** Neither the retry
  loop nor exhaustion writes an operational log containing the driver
  exception, its message or its chain. Retry is a control-flow concern, not a
  reporting one.

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

**No package-level backoff in the initial implementation.** This is a bounded
decision for this retry set, not a claim that immediate retry is universally
optimal. An immediate retry may still block on ordinary lock acquisition if
the surviving transaction has not yet committed; that is normal database
behaviour and is not what backoff would fix.

The reasons it is adequate here:

- attempts are hard-bounded to `1`–`10`, default `3`, so the worst case is a
  small, finite number of transactions rather than an open-ended loop;
- the accepted retry candidates are exactly three — coordination insert races,
  deadlock victims and serialization failures — all of which have already been
  rolled back by the engine, so a retry is a fresh transaction rather than a
  poll;
- lock wait timeouts and SQLite `BUSY`/`LOCKED` are excluded, and those are
  the conditions where retrying without a delay genuinely amplifies
  contention;
- where the surviving transaction is still holding locks, the retry waits on
  normal blocking lock acquisition, which is the database's own admission
  control;
- there is no production evidence yet to size a delay, a ceiling or a jitter
  distribution, and sleeper, jitter and delay configuration are all public
  surface that would have to be maintained from the moment they ship.

Backoff must be reconsidered from pilot evidence rather than assumed
unnecessary. And it is paired with the excluded class: if lock timeouts or
SQLite `BUSY`/`LOCKED` are ever made retryable, bounded backoff with jitter
and an injectable delay seam must land in the **same** change, with tests
using a deterministic fake sleeper that records requested delays instead of
sleeping.

## 8. Architectural components

Each piece has exactly one job, and none overlaps another:

| Component | Visibility | Responsibility |
|---|---|---|
| `RetryPolicy` | `@internal` | Reads and validates `assignments.concurrency_attempts`; exposes the bounded attempt count. Knows nothing about drivers. |
| `CoordinationRaceClassifier` | `@internal` | Confirms that one specific exception is *the* coordination-row insert race, against the resolved lock table and its physical primary key. |
| `TransientFailureClassifier` | `@internal` | Classifies supported-driver deadlock and serialization failures from SQLSTATE and vendor code. Knows nothing about coordination rows or retry counting. |
| `CoordinationInsertRace` | `@internal` | The confirmed-race marker described below. |
| `ClassifiedConcurrencyFailure` | `@internal` | The non-`Throwable` classified value from section 6: four safe scalars, no driver exception. |
| `MutationName` | `@internal` | The single vocabulary of all fourteen canonical mutation names, and which six are coordinated. |
| retry orchestrator | `@internal` | Counts attempts, reruns the transaction, decides exhaustion. Knows nothing about SQLSTATE or vendor codes. |
| `ConcurrencyRetryExhausted` | **public** | The terminal exception when attempts are exhausted or unavailable. |

Only one of the eight is public API.

### The coordination-race marker

```php
/** @internal */
final class CoordinationInsertRace extends RuntimeException
{
    public readonly ClassifiedConcurrencyFailure $failure;
}
```

Rules:

- **only `AssignmentLockStore` may create it**, and only from the `catch`
  around the coordination-row `INSERT` itself. No other class constructs it,
  and no other statement can produce it;
- it is created **only after** `CoordinationRaceClassifier` has confirmed the
  exception against positional eligibility, SQLSTATE, vendor code and the
  expected identity token. An insert exception that is not confirmed
  propagates unchanged, with its own chain intact;
- it carries a `ClassifiedConcurrencyFailure` with
  `category: 'coordination_race'`. It does **not** chain, store or otherwise
  retain the raw `QueryException`, which falls out of scope once classified;
- the retry orchestrator treats it as retryable. Nothing else does;
- it is never a public domain outcome. It is caught by the orchestrator and
  either consumed by a retry or converted into `ConcurrencyRetryExhausted`. It
  never reaches a consumer, and it is never returned as a `MutationResult`;
- it cannot be confused with `DuplicateRole`, `DuplicatePermission` or
  `DuplicateActiveAssignment`. Those are terminal domain outcomes with
  different types, raised on different paths, and none of them is retryable.

Making the marker a distinct type is what keeps "retryable" out of the domain
exception hierarchy entirely: the orchestrator catches one internal class, and
every public exception is by construction non-retryable.

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
public function run(Closure $operation): MutationResult;

public function runCoordinated(
    string $mutation,
    Closure $operation,
): MutationResult;
```

The mutation name is an explicit parameter rather than something derived
later, and that is not cosmetic. `ConcurrencyRetryExhausted::$operation` must
be populated when **no** attempt succeeded, so there is no `PreparedMutation`
and no `PendingAuditRecord` to read it from — those exist only inside an
attempt that got far enough to build them. A first attempt can fail at
reference resolution, before any of that. The orchestrator must therefore
already hold the name when the loop starts.

Rules:

- each of the six assignment handlers passes its own canonical name from the
  shared vocabulary below — the same constant it uses for its
  `PendingAuditRecord` event;
- the name is captured **before the first attempt begins**, and the same value
  is used for every attempt and for the exhaustion exception;
- the orchestrator never derives it from a `PreparedMutation`, which may never
  exist;
- only the six coordinated names are valid here. A name outside that set — a
  typo, or a definition mutation name reaching this path — is rejected
  **before any transaction opens**;
- the value is a fixed vocabulary term. No subject, role, permission,
  assignment identifier, guard or reason is ever passed as the operation, so
  nothing consumer-supplied can reach `ConcurrencyRetryExhausted`.

### One internal mutation vocabulary

The names currently live in two places that can drift: handlers write string
literals into their `PendingAuditRecord`, and `MutationCommitted` keeps its
own private list to validate against. Adding a third copy for retry would make
that worse, so this ADR consolidates instead.

```php
/** @internal */
final class MutationName
{
    public const RoleAssigned = 'role.assigned';
    public const RoleRevoked = 'role.revoked';
    public const PermissionGrantedToSubject = 'permission.granted_to_subject';
    public const PermissionRevokedFromSubject = 'permission.revoked_from_subject';
    public const PermissionGrantedToRole = 'permission.granted_to_role';
    public const PermissionRevokedFromRole = 'permission.revoked_from_role';

    public const RoleCreated = 'role.created';
    public const RoleUpdated = 'role.updated';
    public const RoleDeactivated = 'role.deactivated';
    public const RoleRestored = 'role.restored';
    public const PermissionCreated = 'permission.created';
    public const PermissionUpdated = 'permission.updated';
    public const PermissionDeactivated = 'permission.deactivated';
    public const PermissionRestored = 'permission.restored';

    public static function isKnown(string $name): bool;
    public static function isAssignment(string $name): bool;
}
```

Constant naming may follow project style; the architectural behaviour is what
this ADR fixes:

- it is an **internal constants and vocabulary class**, not a value object and
  not consumer-facing API. Nothing is instantiated, and no public type appears
  in any signature;
- it is the **single source of truth** for all fourteen canonical names. No
  other list of six or fourteen names may exist anywhere in the package;
- the six assignment handlers use its assignment constants for **both** their
  `PendingAuditRecord` event and their `runCoordinated()` mutation argument,
  so one constant guarantees the audit row, the ADR 0010 event and the
  exhaustion exception all describe the same mutation;
- the eight definition handlers use its constants for their audit events;
- `MutationCommitted` validates through `MutationName::isKnown()` instead of
  keeping a private duplicate list. Its public behaviour is unchanged;
- `runCoordinated()` validates `MutationName::isAssignment()` before opening a
  transaction, so an unknown name, a typo or a definition name fails with no
  database work performed;
- `ConcurrencyRetryExhausted` receives an already-validated canonical name and
  never re-derives or re-validates it.

**`RetryPolicy` does not touch mutation names.** Its single responsibility is
validating `assignments.concurrency_attempts` and exposing the bounded attempt
count. Name validation belongs to `MutationName`, called directly by
`runCoordinated()`. Keeping the two apart means the retry configuration
resolver never needs to know what a mutation is.

A constants class rather than a value object is deliberate: there is nothing
to construct, nothing to normalize, and no invariant beyond membership. Two
static predicates over a constant list express that without adding a type to
six call sites or a second public-looking name to the package.

The six assignment handlers change one call site each. No handler gains a
loop, an attempt counter, a SQLSTATE, a vendor code, or a driver branch.
Definition handlers keep calling `run()` and are structurally incapable of
retrying. A named method is preferred over a boolean parameter because the
call site then states what it means.

## 9. Retry state machine

```text
        ┌──────────────────────────────────────────────┐
        │ BEFORE ANY TRANSACTION                       │
        │  • validate the mutation name (6 accepted)   │
        │  • validate concurrency_attempts (1..10)     │
        │  • validate pure inputs, capture the         │
        │    immutable command with one clock reading  │
        └───────────────────────┬──────────────────────┘
                                │  invalid name/config → throw, no transaction
                                ▼
                  caller-owned transaction? ───── yes ─────┐
                                │ no                       │
                                ▼                          ▼
                     ┌──────────────────┐      ┌──────────────────────┐
                ┌───▶│  attempt begins  │      │ single attempt only  │
                │    └────────┬─────────┘      └──────────┬───────────┘
                │             │                           │
                │  resolve refs → coordination row        │
                │  → lock rows → decide → persist         │
                │  → strict audit                         │
                │             │                           │
                │      ┌──────┴───────┐                   │
                │   commit         throws                 │
                │      │              │                   │
                │      ▼              ▼                   ▼
                │  ┌────────┐   what was thrown?    what was thrown?
                │  │ WINNER │        │                    │
                │  └───┬────┘   ┌────┴────────────┐  ┌────┴────────────┐
                │      │        │                 │  │                 │
                │      │  CoordinationInsertRace  │  retryable      not
                │      │  (confirmed by the       │  (race /        retryable
                │      │   store's INSERT catch)  │   deadlock /       │
                │      │        │                 │   serialization)   │
                │      │  TransientFailure-       │        │           ▼
                │      │  Classifier says         │        ▼        throw
                │      │  deadlock / serialization│  Concurrency-  unchanged
                │      │        │                 │  RetryExhausted
                │      │        ▼                 │  (attempts: 1,
                │      │   attempts remaining?    │   callerOwned: true,
                │      │    │           │         │   safe fields only)
                │      │   yes         no         │
                └──────┼────┘          │          ▼
                       │               │      domain exception
                       │               │      or unclassified
                       │               │      QueryException →
                       │               │      throw unchanged
                       │               ▼
                       │      ConcurrencyRetryExhausted
                       │      (attempts: configured,
                       │       safe classified fields,
                       │       previous: null)
                       ▼
           ┌───────────────────────────────────┐
           │ AFTER COMMIT — at most once,      │
           │ never inside the loop             │
           │   1. non-strict audit attempt     │
           │   2. cache invalidation (slot)    │
           │   3. MutationCommitted            │
           └───────────────────────────────────┘
```

Every discarded attempt leaves the database as it found it and reaches none of
the after-commit block. Exhaustion and unclassified failures throw before it.
An unconfirmed coordination-insert exception never becomes a
`CoordinationInsertRace` and therefore never enters the retry path at all.

## 10. Portability and testing

**Unit, no database required.** Classification is a pure function of
`QueryException` state, so the matrix is tested from recorded golden vectors —
one per driver per condition, carrying the real `errorInfo` triple and message
that engine produces. This is what lets every contributor run the full
classification suite on SQLite alone.

Required unit evidence — **classification and identity**:

- every row of the section 5 matrix classified correctly, per driver;
- coordination-race confirmation against the resolved lock table and physical
  primary key, including a configured table prefix and a configured schema
  qualifier;
- diagnostic-text matching is token-bounded on PostgreSQL, Oracle and SQL
  Server as well as MySQL, and is case-insensitive where the engine folds
  case;
- similar-prefix and similar-suffix constraint names are rejected:
  `acl_asgn_locks_pk_2`, `x_acl_asgn_locks_pk`, `acl_asgn_locks_pkey`;
- schema-qualified, table-qualified, double-quoted, backtick-quoted and
  bracket-quoted forms of the expected name are each accepted;
- classification is unaffected by localized or reworded driver prose: the same
  SQLSTATE, vendor code and identifier token classify identically whatever the
  surrounding sentence says, and no matcher depends on phrases such as
  "duplicate key" or "violates unique constraint";
- false-positive rejection: a role namespace violation, a permission namespace
  violation, a consumer-table unique violation, a primary-key collision on
  another GateTrail table, a foreign-key violation, a NOT NULL violation, a
  missing-table failure, and an arbitrary `QueryException` are all
  non-retryable;
- the MySQL fallback rejects a `1062` naming `PRIMARY` on a different table;
- lock wait timeouts and SQLite `BUSY`/`LOCKED` are classified as **not**
  retryable on every driver.

Required unit evidence — **mutation vocabulary**:

- all fourteen `MutationName` constants are unique, and there are exactly
  fourteen;
- `MutationName::isAssignment()` returns true for exactly six of them;
- `MutationCommitted` accepts all fourteen through the shared vocabulary and
  retains no private duplicate list;
- every assignment handler uses the same constant for its
  `PendingAuditRecord` event and its `runCoordinated()` argument, asserted per
  handler;
- every definition handler uses a shared vocabulary constant for its audit
  event;
- no second list of six or fourteen mutation names exists in the package.

Required unit evidence — **operation name and configuration**:

- `runCoordinated()` receives a canonical mutation name and validates it
  before any transaction opens;
- each of the six coordinated names is accepted;
- an unknown name, a definition mutation name, an empty string and a typo are
  each rejected before a transaction opens, with no database work performed;
- `RetryPolicy` validates only `assignments.concurrency_attempts` and has no
  mutation-name behaviour;
- `concurrency_attempts` validation across `int`, `bool`, `float`,
  numeric string, `null`, array, object, `0`, negative, `1`, `10`, `11`.

Required unit evidence — **exception safety**:

- `ConcurrencyRetryExhausted::$operation` is populated even when no
  `PreparedMutation` was ever created, because the first attempt failed during
  reference resolution;
- `ClassifiedConcurrencyFailure` is not a `Throwable`, and its object graph
  contains only the four safe scalars `driver`, `category`, `sqlState` and
  `vendorCode`;
- neither `ClassifiedConcurrencyFailure` nor `ConcurrencyRetryExhausted`
  retains a `QueryException` anywhere in its object graph;
- `ConcurrencyRetryExhausted::getPrevious()` is `null` for owned exhaustion and
  for a caller-owned failure alike;
- each supported driver maps a confirmed failure to the correct safe scalar
  classification, and the **last** classified attempt supplies the exhaustion
  diagnostic fields;
- planted SQL, bindings, a subject identifier, a reason, consumer metadata, a
  credential, a table or constraint name and driver prose none of them appear
  in `ConcurrencyRetryExhausted`'s message or any public property, asserted by
  inspecting the object graph rather than by trusting a single serialization
  format;
- an unclassified `QueryException` is never wrapped and propagates with its own
  chain intact.

Required integration evidence, SQLite by default:

- success on the first attempt makes exactly one attempt;
- an injected classified failure on attempt 1 then success on attempt 2 yields
  one committed mutation, one audit row, one event;
- exhaustion raises `ConcurrencyRetryExhausted` with the configured count and
  leaves no mutation, no audit row and no event;
- the same immutable command instance is reused by every attempt, and the
  captured timestamp does not change between attempts;
- `CoordinationInsertRace` is produced only by the coordination `INSERT` path:
  a unique violation injected anywhere else in the attempt never produces it
  and is never retried;
- the `CoordinationInsertRace` marker never escapes the orchestrator: no
  consumer-visible call ever throws it;
- an unconfirmed exception from the coordination insert propagates unchanged
  rather than becoming a marker;
- domain exceptions are never retried — one attempt only, for
  `DuplicateActiveAssignment`, `AssignmentNotActive`, `AssignmentScopeCollision`,
  `GuardMismatch` and `ReasonRequired`;
- an unknown infrastructure `QueryException` is never retried;
- a lock wait timeout and a SQLite `BUSY`/`LOCKED` condition are not retried
  and surface as their original exception;
- a definition deadlock is not retried and surfaces as its original exception;
- strict mode leaves neither mutation nor audit after a discarded attempt;
- non-strict audit failure never triggers a mutation retry;
- disabled auditing retries the mutation transaction only;
- a caller-owned transaction makes exactly one attempt and raises
  `ConcurrencyRetryExhausted` with `callerOwned: true`;
- zero `MutationCommitted` for failed attempts, exactly one for the winner,
  zero on exhaustion;
- no duplicate audit rows across retried attempts;
- GateTrail writes no operational log containing the raw classified exception
  during retry or exhaustion;
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
- The public exception chain leaks nothing: `ConcurrencyRetryExhausted` ends at
  four safe scalar fields and an empty `previous` chain, so no `Throwable`
  derived from the driver exception — and therefore no SQL, binding or trace
  argument — can reach a consumer's log through GateTrail.
- ADR 0010's exactly-once guarantee is preserved structurally, not by
  convention: after-commit work is unreachable from a failed attempt.
- Handlers stay free of driver codes and attempt counters.
- One internal mutation vocabulary removes the drift risk between an audit
  `event`, an ADR 0010 event name and a retry operation name: they are the
  same constant.
- Caller-owned behaviour is honest about what a savepoint cannot do.

### Negative

- Constraint identity depends on narrow diagnostic-text parsing on **every**
  supported driver, because no PDO driver exposes it as a field. Positional
  scoping, SQLSTATE and vendor-code agreement carry most of the safety; text
  matching only confirms.
- MySQL is the weakest row, because it cannot name a primary key at all and
  older servers report a bare `PRIMARY`.
- Lock timeouts and SQLite `BUSY`/`LOCKED` are not recovered in the initial
  implementation.
- Definition mutations gain no deadlock recovery.
- `AuditedMutationTransaction` gains a second entry point that also takes a
  mutation name, so "which method does this handler call, with which name"
  becomes a thing to get right — mitigated by validating the name before any
  transaction opens.
- Diagnostic depth is limited to driver, category, SQLSTATE and vendor code.
  An operator does not see the failing statement and must use database-side
  logs to investigate further. That is the accepted trade for an empty
  `previous` chain.
- Adding a fifteenth mutation now requires adding it once to `MutationName`
  and explicitly classifying whether it is coordinated. That is one more step,
  and it is deliberate: an unclassified name cannot silently acquire or lose
  retry behaviour.
- Golden vectors must be refreshed when a driver changes its error surface.

### Neutral and operational

- `ConcurrencyRetryExhausted` is a load signal; `callerOwned: true` is a usage
  signal. They warrant different operational responses and are distinguishable
  without parsing a message.
- Default behaviour changes for existing installations: three attempts instead
  of one. This is what the shipped configuration always advertised.
- Retry adds no new configuration keys, and no backoff configuration exists to
  tune or misconfigure.
- Retry emits no operational log of its own. Contention is observed through
  `ConcurrencyRetryExhausted` reaching the consumer, not through GateTrail
  logging driver exceptions.
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

**Driver-message-only classification.** Rejected, and the distinction matters
because diagnostic text is unavoidable here. No supported PDO driver exposes
the violated constraint as a field, so **every** driver may require narrow
diagnostic-text parsing to confirm identity. What this ADR rejects is treating
that text as *sufficient*. Classification requires all three of positional
eligibility, structured SQLSTATE and vendor-code agreement, and exact
token-bounded identity confirmation; any one of them failing means "not a
coordination race". MySQL remains the weakest case, because servers older than
8.0.19 expose only a bare `PRIMARY` with no table qualifier. No classification
rule depends on locale-specific prose such as "duplicate key" or "violates
unique constraint".

## Specification alignment

`PACKAGE_SPEC.md` was updated when this ADR was accepted to:

- add `ConcurrencyRetryExhausted` and `InvalidConcurrencyConfiguration` to the
  section 15 exception list, with their accepted contracts;
- document `assignments.concurrency_attempts` in section 17 as a real integer
  bounded to `1`–`10`, defaulting to `3`, applying only to the six
  `AssignmentManager` mutations, with no package-level backoff and no
  retry for lock timeouts or SQLite `BUSY`/`LOCKED`;
- record this ADR in the section 24 accepted-decision list.

Retry is not implemented. `PACKAGE_SPEC.md` and `ROADMAP.md` continue to
describe bounded concurrency retry as outstanding work, and
`StrictMutationTransaction` still runs with `attempts: 1`.

Only the internal component names belong to this ADR; `MutationName`,
`ClassifiedConcurrencyFailure` and `CoordinationInsertRace` are `@internal` and
are deliberately absent from the specification's public exception list.

No contradiction with the accepted specification was found. ADR 0001's adapter
table describes MySQL and SQL Server recovery after "a duplicate-key" failure;
this ADR narrows that to a duplicate key **on the coordination row's primary
key, confirmed positionally**, which is a refinement of ADR 0001's intent
rather than a departure from it, since only the coordination row's insert can
lose the race the algorithm describes.

## Resolved owner directions

Three questions were raised by the first draft of this ADR and have been
decided. They are integrated into the design above; they are recorded here so
the reasoning is not lost.

1. **Neither a raw nor a sanitized previous exception is used.** Both were
   rejected. `ConcurrencyRetryExhausted::getPrevious()` is always `null`, and
   its safe diagnostics — driver, category, SQLSTATE, vendor code — are plain
   scalar properties copied from an internal non-`Throwable`
   `ClassifiedConcurrencyFailure`. See section 6.
2. **Lock wait timeouts and SQLite `BUSY`/`LOCKED` are excluded** from the
   first implementation. They propagate as their original infrastructure
   exception, remain fail-closed, and cannot create duplicate authorization
   state. Retry plus bounded backoff for them is a paired future extension,
   to be revisited on pilot evidence. This defers automatic recovery only;
   SQLite's persisted-state guarantees are unchanged and remain supported.
3. **Definition mutations remain single-attempt.** `RoleManager` and
   `PermissionManager` keep translating their semantic unique races into
   `DuplicateRole` and `DuplicatePermission`. A definition deadlock is outside
   this increment and surfaces as its existing infrastructure exception.
   Definition deadlock recovery is future hardening, driven by evidence.

No unresolved decisions remain. The design was reviewed as a whole and
accepted on 2026-08-02.

## References

- [GateTrail ADR 0001: unique active assignments](0001-unique-active-assignments.md)
- [GateTrail ADR 0002: public mutation API](0002-public-mutation-api.md)
- [GateTrail ADR 0008: audit snapshots and failure modes](0008-audit-snapshot-and-failure-modes.md)
- [GateTrail ADR 0010: after-commit mutation events](0010-after-commit-mutation-events.md)
- [Laravel: database transactions](https://laravel.com/docs/13.x/database#database-transactions)

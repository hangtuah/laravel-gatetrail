# ADR 0009: Treat Direct Database Mutation as Unsupported and Diagnostic-Only

- Status: Accepted
- Date: 2026-07-28
- Decision owners: GateTrail maintainers

## Context

GateTrail's managed mutation services coordinate concurrency, validate guards
and periods, persist audit records, invalidate caches, and dispatch events.

A consumer or database operator can bypass those services with:

- manual SQL;
- low-level Eloquent relationship methods;
- an external import;
- an organization-owned trigger or administrative tool.

A schema-valid direct write can be indistinguishable from a managed write after
it is persisted. A writer can also populate actor, reason, timestamp, and
coordination fields. No portable query can prove the original write path or
actor.

GateTrail needs to state what it guarantees, which inconsistencies it can
diagnose, and how known direct intervention affects authorization and cache
behavior without requiring elevated database privileges.

## Decision

Direct mutation of GateTrail-owned data is unsupported.

Detection is classified as:

```text
best-effort
diagnostic-only
read-only
not proof of write origin
```

GateTrail guarantees mutation validation, audit persistence, coordination,
cache invalidation, and events only when consumers use managed package
services.

The resolver evaluates the persisted state visible through the configured
connection. It does not attempt to infer whether a row was written through a
manager, SQL client, trigger, import, or another application.

## Direct mutation boundary

Unsupported direct mutation includes:

```text
manual INSERT, UPDATE, or DELETE on acl_* data
Eloquent attach(), detach(), sync(), save(), or delete() used as mutation paths
raw query-builder writes
external jobs that write GateTrail tables without package managers
database triggers that perform authorization mutations
```

Package migrations, versioned DBA DDL, read-only schema inspection, and
organization-controlled backup or replication are not data mutation paths
covered by this decision.

Direct writes forfeit guarantees for:

- actor and reason validation;
- assignment-period validation;
- guard compatibility;
- equivalent-assignment serialization;
- audit completeness;
- cache invalidation;
- after-commit events;
- idempotent outcomes;
- public domain exceptions.

Database constraints still apply when present and enabled, but they do not
replace managed behavior.

## Runtime authorization

Authorization checks do not run provenance queries, audit reconciliation, or
`gatetrail:doctor`.

For persisted rows that satisfy the resolver's normal rules:

- an active direct permission may grant access;
- an active role path may grant access;
- revoked, expired, scheduled, or deactivated paths do not grant access.

This behavior is independent of write origin because origin cannot be proven.

GateTrail does not automatically deny all authorization when a data anomaly is
reported. A global runtime fail-closed switch based on a periodic diagnostic
would turn an operational inconsistency into an application-wide outage,
remain bypassable by a sufficiently complete direct write, and make resolver
behavior depend on stale diagnostic state.

Managed mutations remain fail closed when they encounter an inconsistency that
prevents safe execution, including a coordination hash mismatch or collision.
They do not silently repair the inconsistency.

## Schema drift is separate

Schema inspection compares physical database objects with the canonical
manifest. Missing or incompatible tables, columns, indexes, constraints,
sequences, or triggers fail schema inspection.

Schema inspection does not establish:

- that existing data was written through managed services;
- that audit history is complete;
- that no direct mutation occurred;
- that caches were invalidated.

Conversely, a data diagnostic finding does not by itself prove physical schema
drift. Schema conformance and data consistency are separate evidence.

## Diagnostic command

`gatetrail:doctor` performs read-only, best-effort consistency checks. It may
scan data in batches and may provide a scoped mode for large installations, but
scope and batching must not change finding semantics.

The command must not:

- insert, update, revoke, delete, or restore data;
- create or repair coordination rows;
- backfill audit records;
- clear caches unless the consumer invokes the separate explicit cache-clear
  command;
- claim that a clean result proves the absence of direct writes.

### Error findings

Where the required data is observable, the command reports:

1. overlapping equivalent non-revoked assignment periods;
2. an assignment scope without its expected persistent coordination row;
3. a coordination row whose `scope_key` does not produce its stored
   `scope_hash`;
4. more than one coordination row representing the same canonical scope;
5. invalid assignment-period ordering;
6. partially populated revocation tuples;
7. a referenced role or permission definition that is missing;
8. an assignment whose definition guard conflicts with the subject's currently
   resolved guard;
9. a role-permission assignment whose definitions use different guards.

A failed or disabled database constraint may permit some of these states. The
doctor reports the observable inconsistency rather than guessing how it was
created.

### Informational findings

An assignment referencing a deactivated role or permission is a valid,
history-preserving lifecycle state. It is not an error.

The command may report that state as informational context because the
assignment is currently ineffective and may become eligible again after a
definition restore.

Expired, scheduled, and normally revoked assignments are also lifecycle states,
not direct-mutation findings.

### Diagnostic limitations

The command cannot reliably detect:

- a schema-valid direct insert that also creates the expected coordination
  row;
- a valid direct update to an existing row;
- a valid definition change;
- a direct write that reproduces expected actor, reason, and timestamp fields;
- the human or process responsible for a write;
- whether a missing audit resulted from direct mutation, disabled auditing, or
  a recorded non-strict audit failure;
- whether every affected cache was invalidated;
- a direct write that leaves no currently observable inconsistency.

Timestamps, audit gaps, and a clean coordination table are not provenance
proofs.

## Diagnostic severity and exit behavior

The command distinguishes:

```text
error
warning
informational
```

Error findings indicate an observable violation of an accepted data invariant.
Warnings indicate incomplete inspection or an operational condition requiring
review. Informational findings explain valid lifecycle state.

A complete run exits unsuccessfully when one or more error findings exist. A
partial or incomplete run must identify its coverage and must not report an
unqualified clean result.

Exact machine-readable output and exit-code numbers may be finalized with the
command API. Their meanings must preserve this severity distinction.

## Cache behavior

Managed mutations trigger documented cache invalidation. Direct writes do not.

If cross-request caching is enabled, a direct grant, revoke, deactivation, or
restore may leave a stale allow or deny result until an explicit clear, cache
expiry, or another managed invalidation affects the entry.

Therefore:

- known direct intervention requires an explicit GateTrail cache clear;
- `gatetrail:doctor` does not clear caches implicitly;
- the package does not claim immediate cache consistency after direct writes;
- the future cache ADR must document its bounded-staleness and operational
  remediation contract;
- this ADR does not select a store, TTL, or role-change fan-out strategy.

Request-level memoization is also not guaranteed to observe a direct write made
mid-request.

Cross-request caching remains disabled by default until its separate ADR,
resolver measurements, and invalidation tests are accepted.

## Known direct intervention

When an emergency or DBA-controlled direct data intervention is unavoidable,
the operational procedure should:

1. identify the exact rows and reason outside GateTrail;
2. restrict concurrent managed mutations where practical;
3. apply the smallest reviewed change;
4. clear affected GateTrail caches explicitly;
5. run strict schema inspection when physical objects changed;
6. run `gatetrail:doctor` after data changes;
7. preserve an organization-owned incident or change record.

Passing inspection and diagnostics does not restore missing GateTrail audit
history. GateTrail does not fabricate a managed audit record for a mutation it
did not perform.

This procedure documents risk reduction, not supported normal operation.

## Database triggers and privileges

GateTrail does not ship or require cross-database audit-on-write triggers for
detecting direct data mutation.

It also does not require the runtime account to receive elevated schema or
catalog privileges for provenance monitoring. This preserves:

- DBA-managed ownership;
- read-only inspection;
- database portability;
- least-privilege runtime access.

An organization may deploy external triggers, database auditing, change-data
capture, or privileged monitoring as defense in depth. Those mechanisms:

- remain owned by the organization;
- must not alter GateTrail's accepted data semantics;
- are not required for package correctness;
- do not become part of GateTrail's portable audit guarantee;
- must not be represented as evidence that managed services were used unless
  the external system can prove that independently.

## Model and relationship API boundary

Public package documentation directs consumers to:

```text
RoleManager
PermissionManager
AssignmentManager
```

Assignment relationships and models may support read operations required for
queries and diagnostics. They must not advertise low-level relationship
mutation as a supported package API.

GateTrail should make unsafe mutation paths difficult where Laravel extension
constraints permit, but it cannot prevent a consumer with database write
access from issuing SQL.

## Alternatives considered

### Guarantee detection with a provenance column

Rejected because a direct writer can populate or copy the same marker. A
provenance column records a claim, not proof of write origin.

### Require database audit triggers

Rejected because trigger syntax, privileges, ownership, and behavior differ
across Tier 1 databases and conflict with DBA-managed least privilege.

### Infer direct writes from missing audit rows

Rejected because auditing may be disabled or may fail in accepted non-strict
mode. Correlation cannot establish provenance reliably.

### Fail every permission check when doctor reports an error

Rejected because diagnostics may be stale or partial, global denial creates an
availability failure, and the approach remains bypassable.

### Run doctor checks during every authorization query

Rejected because it adds unrelated scans and failure modes to the resolver,
harms performance, and still cannot prove origin.

### Automatically repair inconsistent rows

Rejected because a generic repair cannot safely infer business intent, actor,
reason, correct assignment period, or authoritative source.

### Treat deactivated references as corruption

Rejected because deactivation intentionally preserves assignments and a later
restore may make an otherwise active path effective again.

## Consequences

### Positive

- Package guarantees are limited to behavior GateTrail can actually enforce.
- Diagnostics remain portable and least-privileged.
- Authorization does not depend on expensive or stale provenance heuristics.
- Schema drift and data inconsistency have separate evidence.
- Deactivated assignment history is not misclassified as corruption.
- DBA-managed environments may retain organization-owned monitoring.
- Known direct intervention has explicit cache and diagnostic remediation.

### Negative

- GateTrail cannot prove that every persisted row used managed services.
- A valid direct grant may affect authorization.
- A valid direct revoke may leave a stale cached allow.
- A clean doctor result cannot certify audit completeness.
- Emergency direct intervention requires external change records.
- Some detectable inconsistencies may require application-specific manual
  remediation.

## Specification alignment

`PACKAGE_SPEC.md` was updated when this ADR was accepted to:

- classify direct mutation as unsupported;
- separate runtime authorization, schema inspection, and data diagnostics;
- define doctor checks and limitations;
- document cache consequences and explicit remediation;
- reject required triggers, elevated privileges, and automatic repair;
- remove direct database mutation behavior from open decisions.

ADR 0001 was updated to delegate diagnostic behavior to this decision.

## Verification

Before this decision is considered implemented, tests must prove:

- managed mutation guarantees remain unchanged;
- resolver results depend on accepted persisted-state rules, not provenance
  guesses;
- doctor detects each listed observable error;
- doctor reports deactivated references as informational rather than errors;
- expired, scheduled, and normally revoked assignments are not errors;
- doctor never writes data or clears caches;
- partial inspection cannot produce an unqualified clean result;
- error findings produce an unsuccessful command result;
- a clean result carries the direct-write detection limitation;
- known cache-clear operations remain explicit;
- schema inspection and data diagnostics report independently;
- managed mutations fail closed on coordination hash mismatch;
- no runtime authorization query invokes doctor scans.

## References

- [ADR 0001: Serialize Equivalent Assignment Mutations](0001-unique-active-assignments.md)
- [ADR 0008: Version Audit Snapshots and Separate Failure Modes](0008-audit-snapshot-and-failure-modes.md)

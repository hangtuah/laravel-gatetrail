# ADR 0008: Version Audit Snapshots and Separate Failure Modes

- Status: Accepted
- Date: 2026-07-28
- Decision owners: GateTrail maintainers

## Context

GateTrail preserves authorization mutation history in `acl_access_audits`.
The accepted schema provides `before`, `after`, and `context` portable
text/CLOB columns, but it did not define their minimum structure.

Without a stable snapshot contract, two implementations can record different
facts for the same event. Incident analysis would then depend on mutable
current rows or package-version-specific assumptions.

The package also exposes strict, non-strict, and disabled audit modes. A failed
audit insert can invalidate the current database transaction on a supported
engine. Catching that failure and attempting to commit the same transaction is
not a portable non-strict strategy.

Finally, public manager methods accept consumer metadata, but its destination,
shape, redaction, and limits were not defined.

## Decision

GateTrail uses versioned, event-specific audit documents with:

- a full security-relevant `before` snapshot or `null`;
- a full security-relevant `after` snapshot or `null`;
- a non-null `context` object for every managed audit event;
- consumer metadata nested only under `context.metadata`;
- an explicit audit mode that determines the write transaction boundary.

Snapshot and context structure version `1` is accepted for the initial public
contract.

## Serialization contract

`before`, `after`, and `context` are persisted as UTF-8 JSON text in portable
text/CLOB columns. Core behavior does not require a native JSON database type.

Rules:

- JSON objects use the exact keys defined by this ADR;
- keys defined for a snapshot are present even when their value is `null`;
- package-owned numeric identifiers are serialized as canonical decimal
  strings to avoid 64-bit precision loss in JSON consumers;
- subject and actor identifiers retain their canonical string representation;
- booleans remain JSON booleans;
- timestamps are normalized to UTC ISO 8601 strings with microseconds;
- associative object keys are serialized in the documented order;
- list order is preserved;
- invalid UTF-8 and non-finite floating-point values are rejected;
- snapshots are captured from normalized persisted values, not raw request
  input.

The canonical timestamp form is:

```text
YYYY-MM-DDTHH:MM:SS.ffffffZ
```

The JSON representation is stable public audit data. Additive context fields
may be introduced within a schema version. Removing a key, changing a key's
meaning or type, or changing snapshot semantics requires a new
`schema_version`.

## Context version 1

Every managed audit event stores:

```json
{
  "schema_version": 1,
  "guard_name": "web",
  "role_name": "reviewer",
  "permission_name": "documents.approve",
  "metadata": {}
}
```

Only keys applicable to the event are present, except:

- `schema_version` is always present;
- `guard_name` is always present;
- `metadata` is always present and is an object, including when empty.

Natural definition names are captured at event time:

| Event group | Natural-name context |
|---|---|
| Role definition events | `role_name` |
| Permission definition events | `permission_name` |
| Role assignment events | `role_name` |
| Direct permission assignment events | `permission_name` |
| Role-permission assignment events | `role_name`, `permission_name` |

The audit record's dedicated columns remain authoritative for:

```text
actor
subject
target
reason
ip address
user agent
request ID
event time
```

Those values are not copied into context merely for convenience.

## Definition snapshot version 1

Role and permission definition snapshots use:

```text
id
name
guard_name
description
deleted_at
```

The definition event mapping is:

| Event | `before` | `after` |
|---|---|---|
| `role.created` | `null` | Full role snapshot |
| `role.updated` | Full prior role snapshot | Full resulting role snapshot |
| `role.deactivated` | Full prior role snapshot | Full resulting role snapshot |
| `role.restored` | Full prior role snapshot | Full resulting role snapshot |
| `permission.created` | `null` | Full permission snapshot |
| `permission.updated` | Full prior permission snapshot | Full resulting permission snapshot |
| `permission.deactivated` | Full prior permission snapshot | Full resulting permission snapshot |
| `permission.restored` | Full prior permission snapshot | Full resulting permission snapshot |

Full snapshots are selected instead of changed-field-only patches so one audit
record explains both states without replaying earlier events or querying the
current definition.

## Assignment snapshot version 1

Every assignment snapshot contains:

```text
id
assigned_at
assigned_by_type
assigned_by_id
assignment_reason
starts_at
expires_at
revoked_at
revoked_by_type
revoked_by_id
revocation_reason
```

Role-assignment snapshots also contain:

```text
subject_type
subject_id
role_id
```

Direct-permission-assignment snapshots also contain:

```text
subject_type
subject_id
permission_id
```

Role-permission-assignment snapshots also contain:

```text
role_id
permission_id
```

Assignment and revocation actors remain in the snapshot because they are
properties of the history-preserving assignment. The audit row's actor columns
identify the actor responsible for the current audit event. These identities
may differ during revocation.

The assignment event mapping is:

| Event | `before` | `after` |
|---|---|---|
| `role.assigned` | `null` | Full role-assignment snapshot |
| `role.revoked` | Full prior role-assignment snapshot | Full revoked role-assignment snapshot |
| `permission.granted_to_subject` | `null` | Full direct-permission-assignment snapshot |
| `permission.revoked_from_subject` | Full prior direct-permission-assignment snapshot | Full revoked direct-permission-assignment snapshot |
| `permission.granted_to_role` | `null` | Full role-permission-assignment snapshot |
| `permission.revoked_from_role` | Full prior role-permission-assignment snapshot | Full revoked role-permission-assignment snapshot |

An idempotent `Unchanged` mutation does not create an audit record.

## Sensitive data and redaction

Snapshots contain package-owned fields only. GateTrail never copies subject,
actor, or application-model attributes into snapshots or context.

The following remain prohibited:

- passwords;
- access tokens;
- private keys;
- session identifiers;
- raw request bodies;
- configured sensitive values.

Configured sensitive paths may target consumer metadata, nullable descriptive
package fields, assignment or revocation reasons, and the audit record's
dedicated reason. A redacted value retains its key and uses this reserved
marker:

```text
__gatetrail_redacted__
```

GateTrail rejects consumer metadata containing the exact reserved marker as an
input value. An absent key means the value was not applicable or was not
supplied. A present marker means a value existed and was removed by policy.

Core identifiers, names, guard names, event names, lifecycle timestamps, and
assignment relationships cannot be redacted because they are required to
explain the authorization mutation.

## Consumer metadata

The public manager `metadata` argument is a string-keyed associative array
persisted only under:

```text
context.metadata
```

It accepts JSON-safe associative data composed of:

```text
null
boolean
integer
finite float
string
array
object-like associative array
```

PHP objects, resources, closures, recursive structures, invalid UTF-8, and
non-finite numbers are rejected.

The top level must represent a JSON object. An empty PHP array is normalized to
an empty JSON object. A non-empty top-level list is rejected; lists remain valid
as nested metadata values.

Secure defaults are:

```text
maximum normalized encoded size: 16 KiB
maximum nesting depth: 8
```

The limits are configurable but always finite. Size is checked on the
normalized input before redaction so a large sensitive value cannot bypass the
limit by becoming a short marker.

Invalid metadata throws `InvalidAuditMetadata` before the mutation transaction
begins. GateTrail does not truncate, stringify, or silently discard invalid
metadata.

## Audit modes

Configuration selects one of three explicit behaviors.

### Enabled and strict

This is the secure default:

```php
'audit' => [
    'enabled' => true,
    'strict' => true,
],
```

The sequence is:

```text
Validate metadata and mutation
-> Begin transaction
-> Persist mutation
-> Persist audit
-> Commit
-> Invalidate cache
-> Dispatch mutation event
```

An audit persistence failure rolls back the mutation and throws
`AuditWriteFailed`. No mutation event is dispatched.

### Enabled and non-strict

When the manager owns the outermost transaction, the mutation and audit use
separate transaction boundaries:

```text
Validate metadata and mutation
-> Begin mutation transaction
-> Persist mutation
-> Commit mutation
-> Attempt audit in a new transaction
-> Invalidate cache
-> Dispatch mutation event
```

If the audit succeeds, `MutationResult` contains its audit identifier.

If the audit fails:

- the committed mutation remains successful;
- `MutationResult` contains a null audit identifier;
- GateTrail writes an error-level operational log without sensitive snapshot
  content;
- GateTrail dispatches an `AuditPersistenceFailed` operational event;
- GateTrail does not throw an exception that could encourage an unsafe retry of
  the already committed mutation;
- normal cache invalidation and the successful mutation event still occur.

The operational failure event contains safe identifiers, the audit event name,
the request ID when available, and the exception class. It does not contain raw
metadata, snapshots, credentials, or the exception message by default.

When GateTrail joins a transaction already owned by the caller:

- it must not persist the non-strict audit before the outer transaction
  commits;
- it registers an outermost after-commit callback containing an immutable,
  normalized audit document;
- an outer rollback discards the callback and creates no audit record;
- `MutationResult` returns before the callback and therefore contains a null
  audit identifier;
- the callback attempts the audit in a new transaction and applies the same
  safe logging and `AuditPersistenceFailed` behavior on failure.

Supported connections must provide outermost after-commit callback semantics
for non-strict auditing inside a caller-owned transaction. If the configured
connection cannot provide them, GateTrail rejects that combination before
mutation with `UnsupportedAuditBoundary`.

Non-strict mode weakens the package's history guarantee and must be documented
as an explicit operational trade-off.

### Disabled

When `audit.enabled` is `false`:

- no audit write is attempted;
- the mutation uses its normal transaction;
- `MutationResult` contains a null audit identifier;
- cache invalidation and successful mutation events still occur.

Disabled auditing weakens GateTrail's history guarantee. It is an explicit
consumer choice and is never selected automatically after an audit failure.

## Audit identifiers and mutation results

`MutationResult` exposes a nullable audit identifier:

```text
strict success       -> audit identifier
non-strict success   -> audit identifier
non-strict failure   -> null
non-strict pending after outer commit -> null
audit disabled       -> null
idempotent unchanged -> null
```

A null audit identifier alone does not distinguish non-strict failure,
an audit pending after an outer commit, disabled auditing, or an unchanged
result. Callers use the mutation outcome, configuration, transaction context,
operational log, and `AuditPersistenceFailed` event for that distinction.

## Request metadata

IP address, user agent, and request ID are captured through explicit package
resolvers when available. GateTrail does not copy the request body or all
request headers into audit context.

CLI and queue mutations may have no request metadata. Its absence does not
invalidate an otherwise complete audit event.

The trust and generation policy for externally supplied request IDs remains an
operational configuration decision and does not alter the snapshot schema.

## Alternatives considered

### Changed fields only for update snapshots

Rejected because a reader would need earlier events or the current row to
reconstruct the complete before and after state.

### Full Eloquent model serialization

Rejected because models may expose application attributes, hidden casts,
relationships, accessors, or sensitive data outside the package contract.

### Native database JSON columns

Rejected because GateTrail's Tier 1 databases have different JSON storage and
query capabilities. Portable text/CLOB with application-level JSON
serialization is sufficient.

### Catch an audit insert failure and commit the same transaction

Rejected because an insert error may leave the transaction unusable on a
supported database. Non-strict auditing uses a separate post-commit
transaction.

### Persist non-strict audit before a caller-owned transaction commits

Rejected because the outer transaction may roll back, leaving an audit record
for a mutation that never committed.

### Silently discard invalid or oversized metadata

Rejected because silent alteration makes audit evidence unreliable and hides
consumer mistakes.

### Throw after a non-strict post-commit audit failure

Rejected because the mutation is already committed and a caller may retry it
under the false assumption that no state change occurred.

## Consequences

### Positive

- One audit event is sufficient to explain its security-relevant state change.
- Snapshot data is portable across all supported database engines.
- Identifiers remain precise for non-PHP JSON consumers.
- Consumer metadata has one stable, bounded, and redacted destination.
- Strict mode preserves atomic mutation and audit behavior.
- Non-strict mode does not depend on committing a failed transaction.
- Disabled and degraded audit behavior is explicit and observable.

### Negative

- Full snapshots consume more storage than changed-field-only patches.
- Snapshot structure becomes public compatibility surface.
- Non-strict mode requires a second transaction and operational failure event.
- A manager joining a caller-owned transaction cannot return the eventual
  non-strict audit identifier.
- Natural names are duplicated in context to preserve historical meaning.
- Metadata validation and canonical serialization add implementation work.
- A non-strict audit failure cannot be represented by the nullable audit
  identifier alone.

## Specification alignment

`PACKAGE_SPEC.md` was updated when this ADR was accepted to:

- define version 1 snapshot and context content;
- make full snapshots the event contract;
- define metadata destination, redaction, and limits;
- separate strict, non-strict, and disabled transaction behavior;
- add `InvalidAuditMetadata`, `UnsupportedAuditBoundary`, and
  `AuditPersistenceFailed`;
- remove minimum audit snapshot content from open decisions.

ADR 0002 was updated to bind manager metadata to this contract.

## Verification

Before this decision is considered implemented, tests must prove:

- every required event produces the exact version 1 keys and value types;
- create and assignment events have null `before` snapshots;
- update, lifecycle, and revocation events contain full before and after
  snapshots;
- numeric identifiers serialize without precision loss;
- timestamps use canonical UTC representation;
- subject and actor application attributes never enter audit documents;
- sensitive configured values use the reserved marker;
- absent and redacted values remain distinguishable;
- invalid, recursive, oversized, deeply nested, and non-UTF-8 metadata is
  rejected before mutation;
- strict audit failure rolls back the mutation;
- non-strict audit failure preserves the mutation, returns no audit identifier,
  logs safely, and dispatches `AuditPersistenceFailed`;
- non-strict audit inside a caller-owned transaction waits for the outermost
  commit and creates no audit after rollback;
- unsupported after-commit boundaries are rejected before mutation;
- disabled auditing skips writes without disabling cache invalidation or
  mutation events;
- idempotent unchanged operations do not create audit records;
- serialized documents behave identically on every supported database adapter.

## References

- [PHP: JSON functions](https://www.php.net/manual/en/ref.json.php)
- [Laravel: database transactions](https://laravel.com/docs/13.x/database#database-transactions)
- [Laravel: events](https://laravel.com/docs/13.x/events)

# ADR 0005: Store Actor Identities as Portable Strings

- Status: Accepted
- Date: 2026-07-28
- Decision owners: GateTrail maintainers

## Context

Every GateTrail mutation requires an explicit actor. A human actor is an
Eloquent model, while an automated process uses a named `SystemActor`.

The initial schema originally described subject and actor identifiers together
as numeric morph identifiers. That representation cannot store a stable system
actor such as:

```php
SystemActor::named('staff-sync')
```

GateTrail needs one representation that works for numeric model keys, UUIDs,
ULIDs, and named automated actors without creating fake application users or a
new actor-registry table.

Actor references are attribution data. They are logical references to
application-owned identities and do not have physical foreign keys.

## Decision

GateTrail will persist actor identifiers as portable strings.

Human actor example:

```text
actor_type = admin
actor_id   = 42
```

System actor example:

```text
actor_type = gatetrail.system
actor_id   = staff-sync
```

The same representation applies to:

```text
assigned_by_type
assigned_by_id
revoked_by_type
revoked_by_id
actor_type
actor_id
```

Actor type and identifier columns use portable string storage with a maximum
length of 191 characters.

### Human actors

- `actor_type` uses the application's stored morph alias where available.
- `actor_id` is the canonical string representation of the model key.
- Numeric keys are stored as decimal strings without changing their value.
- UUID and ULID keys retain their canonical string representation.
- GateTrail does not require a physical foreign key to the application model.

### System actors

- `gatetrail.system` is reserved as the package system-actor type.
- The actor ID is the normalized name supplied to `SystemActor::named()`.
- The name is trimmed, non-empty, case-preserving, and no longer than 191
  characters.
- Consumers must not use the reserved type as an application morph alias.

### Resolution and history

Persisted actor identity is sufficient for attribution even when the original
model can no longer be resolved. Audit readability must not depend on the actor
model still existing.

The later audit-snapshot decision will define the minimum human-readable actor
label stored with an audit event.

## Consequences

### Positive

1. Human and automated actors share one consistent storage shape.
2. GateTrail keeps its accepted seven-table design.
3. CLI, queue, scheduler, and integration processes do not need fake users.
4. Numeric, UUID, and ULID actor keys are representable.
5. Actor history remains attributable without a live database relationship.

### Negative

1. Actor columns cannot have physical foreign keys to application models.
2. Numeric human keys are stored as strings in GateTrail actor columns.
3. Actor resolution requires package logic rather than an assumed
   database-level relationship.
4. The reserved system-actor type becomes part of GateTrail's persisted data
   contract.

### Neutral

1. Subject identifiers remain numeric in the initial default migrations.
2. Actor attribution does not authorize a mutation.
3. Display labels and audit snapshots remain a separate architecture decision.

## Alternatives considered

### Add `acl_actors`

Rejected for the initial design. It adds an eighth table, introduces actor
synchronization and lifecycle rules, and duplicates application identity data.

### Configure numeric IDs for system actors

Rejected. Numeric registries are difficult to keep consistent across
environments and make named system processes less transparent.

### Store system identity only in audit context

Rejected. Actor queries become inconsistent and assignments lose structured
attribution.

### Allow a nullable actor ID

Rejected. A partial actor pair is ambiguous and makes attribution rules harder
to enforce.

## Implementation requirements

- Validate actor type and ID before opening the mutation transaction where
  practical.
- Store assigned actor pairs as non-null values.
- Store revoked actor pairs as null until revocation and non-null afterward.
- Store audit actor pairs as non-null values.
- Index actor type and ID together where actor lookup is required.
- Reject the reserved system type when it is configured as an application
  morph alias.
- Add golden tests for numeric, UUID, ULID, and system actor normalization.


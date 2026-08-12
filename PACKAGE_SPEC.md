# GateTrail Package Specification

## 1. Document status

| Item | Value |
|---|---|
| Package | `hangtuah/laravel-gatetrail` |
| Namespace | `HangTuah\GateTrail` |
| Specification status | Accepted for implementation |
| Baseline date | 2026-07-28 |
| Product status | Pre-alpha |
| Target release | `0.1.0` foundation, `1.0.0` after an internal pilot |

This specification is GateTrail's accepted implementation baseline. Acceptance
does not claim that every described feature is implemented or that every
compatibility target is verified. Where this document conflicts with
implementation code, the conflict must be resolved explicitly rather than
silently changing package behavior.

Freeze evidence is recorded in
[Specification Freeze Check 0001](docs/reviews/0001-specification-freeze-check.md).

## 2. Product definition

GateTrail is a Laravel-native package for managing roles, permissions, and
application authorization.

It provides:

- reusable role and permission models;
- assignments between subjects, roles, and permissions;
- effective-permission resolution;
- Laravel Gate integration;
- assignment and revocation history;
- audit records for authorization mutations;
- middleware and developer-facing APIs.

GateTrail does not provide authentication or application-specific business
policies.

### 2.1 Database table overview

GateTrail uses seven package-owned tables by default:

| Table | Purpose | Lifecycle |
|---|---|---|
| `acl_roles` | Stores role definitions | Active, deactivated, or restored; soft deletion is the storage mechanism |
| `acl_permissions` | Stores permission definitions | Active, deactivated, or restored; soft deletion is the storage mechanism |
| `acl_role_assignments` | Assigns roles to subjects and preserves assignment history | Scheduled, active, expired, or revoked |
| `acl_permission_assignments` | Assigns permissions directly to subjects and preserves assignment history | Scheduled, active, expired, or revoked |
| `acl_role_permissions` | Assigns permissions to roles and preserves assignment history | Scheduled, active, expired, or revoked |
| `acl_assignment_locks` | Serializes mutations for equivalent assignment scopes | Persistent coordination data |
| `acl_access_audits` | Stores append-only records of RBAC mutations | Append-only |

Relationship overview:

```text
Subject
├── acl_role_assignments
│   └── acl_roles
│       └── acl_role_permissions
│           └── acl_permissions
└── acl_permission_assignments
    └── acl_permissions

Managed assignment mutations
└── acl_assignment_locks

All managed mutations
└── acl_access_audits
```

The names above are the default table names. Consumers may configure table
names before publishing and running the package migrations.

## 3. Compatibility

### 3.1 Initial compatibility targets

| Dependency | Target versions |
|---|---|
| PHP | `^8.3` |
| Laravel | `^12.0 \| ^13.0` |
| Orchestra Testbench | `^10.0 \| ^11.0` |
| Oracle Database | 12c Release 2 or later |
| Laravel Oracle driver | `yajra/laravel-oci8:^12.0 \| ^13.0` matching the Laravel major |
| MySQL | 8.0.16 or later |
| PostgreSQL | 14 or later |
| SQL Server | 2017 or later |
| SQLite | Current supported version for automated tests |

Oracle, MySQL, PostgreSQL, and SQL Server are target Tier 1 production
databases. SQLite is a Tier 1 test database but is not the reference production
database. A target becomes verified only through the evidence required by
sections 3.2 and 20.

### 3.2 Compatibility policy

- GateTrail follows semantic versioning.
- A Laravel major version is supported only when it is included in CI.
- A database is supported only when migrations and behavioral tests run against
  that database.
- Package code should depend on individual `illuminate/*` components rather
  than the complete `laravel/framework` package where practical.
- Oracle support is tested through the matching major version of
  `yajra/laravel-oci8`.
- The Oracle driver is an integration dependency and must not force OCI8
  installation on consumers that use another database.
- SQL Server support requires the `sqlsrv` and `pdo_sqlsrv` PHP extensions and
  the Microsoft SQL Server ODBC driver in the consuming environment.
- MySQL 8.0.16 is the minimum because earlier MySQL 8.0 releases do not enforce
  the `CHECK` constraints required by the canonical schema manifest.
- The supported-version matrix may be narrowed before `1.0.0` if maintaining a
  combination compromises correctness or security.

### 3.3 Database portability rules

Core behavior must not depend on a single database dialect.

- Use Eloquent or Laravel Query Builder for normal package queries.
- Keep vendor-specific SQL inside an explicit database adapter.
- Do not use database functions such as `NOW()`, `SYSDATE`, or `GETDATE()` to
  make authorization decisions.
- Use the package clock and pass time values as bound parameters.
- Do not rely on a database's default case sensitivity for role or permission
  names.
- Do not require native JSON query operators for core behavior.
- Give every table, index, constraint, sequence, and trigger an explicit name of
  30 characters or fewer.
- Do not use quoted mixed-case database identifiers.
- Run the same behavioral authorization suite against every Tier 1 database.

### 3.4 Provisioning modes

Every Tier 1 production database supports two provisioning modes:

1. **Package-managed** — GateTrail migrations create and upgrade the required
   database objects.
2. **DBA-managed** — an external database owner creates and upgrades the
   required objects from GateTrail's versioned schema contract.

Package-managed provisioning is the default for general package use, local
development, and automated tests. DBA-managed provisioning is a first-class
option for environments that separate application and DDL privileges.

In DBA-managed mode:

- GateTrail migrations do not execute;
- the runtime account does not require create, alter, or drop privileges;
- the configured schema may be prepared by a DBA, platform team, or another
  deployment system;
- GateTrail performs read-only schema inspection before claiming compatibility;
- versioned install, upgrade, and rollback DDL must be available for Oracle,
  MySQL, PostgreSQL, and SQL Server;
- vendor DDL must conform to the same database-neutral schema manifest used to
  verify package migrations.

Business behavior, models, relationships, and mutation services must not depend
on which provisioning mode created the schema.

Each schema release has two synchronized delivery paths:

```text
Laravel package migration  → package-managed environments
Versioned vendor DDL       → DBA-managed environments
```

### 3.5 Oracle integration

Oracle support is a first-class requirement.

Package-managed Oracle uses Laravel-OCI8 support for Oracle-specific objects.
DBA-managed Oracle permits organization-owned tables, indexes, constraints,
sequences, and triggers that conform to the GateTrail schema manifest.

Configured sequence and trigger names must be supported. A future
`gatetrail:doctor` command validates expected objects and identifier
configuration without modifying the schema.

The Oracle adapter supports two explicit, schema-wide generated-key
strategies:

```text
identity
sequence_trigger
```

`sequence_trigger` is the initial default. The selected strategy affects only
Oracle physical DDL and inspection; it does not change the database-neutral
manifest or the public PHP API. A schema must not mix strategies across
generated-ID tables.

Distinct versioned DBA artifacts are published for each strategy. The
`identity` inspector validates identity-column metadata without depending on
Oracle-generated internal sequence names. The `sequence_trigger` inspector
validates the configured sequence and trigger objects. Full strategy,
ownership, inspection, and rollback rules are defined by
[ADR 0006](docs/decisions/0006-oracle-generated-key-strategy.md).

Oracle schema release `0001` publishes canonical artifacts at:

```text
database/ddl/oracle/0001/identity/install.sql
database/ddl/oracle/0001/identity/rollback.sql
database/ddl/oracle/0001/sequence-trigger/install.sql
database/ddl/oracle/0001/sequence-trigger/rollback.sql
```

Each artifact identifies its generated-key strategy in the header. Consumers
must apply the artifact matching their configured strategy.

Oracle schema inspection is read-only and uses catalog views describing objects
accessible to the connected account. It validates:

- canonical tables, columns, types, nullability, and character-length
  semantics;
- ordered indexes and their valid status;
- named, enabled, and validated constraints;
- restrictive foreign-key behavior and normalized check expressions;
- `BY DEFAULT ON NULL` identity metadata for the `identity` strategy;
- configured, non-cycling sequences and enabled, null-guarded
  `BEFORE EACH ROW` insert triggers for the `sequence_trigger` strategy;
- absence of mixed generated-key strategies.

The inspector does not query `DBA_*` views and never creates, alters, enables,
or repairs an Oracle object. Unquoted Oracle identifiers are expected to appear
in uppercase catalog form; quoted lowercase or mixed-case lookalikes do not
satisfy the portable identifier contract.

Oracle integration tests must cover:

- inserts where the selected strategy populates the primary key;
- Eloquent receiving the generated primary key after insert;
- package-managed identity and sequence/trigger behavior;
- DBA-managed pre-existing identity and sequence/trigger behavior;
- explicit-ID preservation in sequence/trigger mode;
- generated-key strategy mismatch detection;
- coordination-row creation and locking in package-managed and DBA-managed
  schemas;
- transactions and rollback;
- soft deletion;
- CLOB audit payload casts;
- date and timestamp comparisons;
- unique active-assignment concurrency;
- schema-qualified table names;
- identifier length and Oracle name normalization.

### 3.6 SQL Server integration

SQL Server support is a first-class requirement and must use Laravel's native
SQL Server connection through the `sqlsrv` driver.

SQL Server integration tests must cover:

- identity-generated primary keys and Eloquent receiving the generated ID;
- transactions and rollback;
- soft deletion;
- portable text audit payload casts;
- date and timestamp comparisons;
- assignment coordination-row creation and locking;
- duplicate active-assignment concurrency;
- schema-qualified table names;
- case-insensitive collation behavior;
- explicitly named identifiers of 30 characters or fewer.

### 3.7 Versioned DBA artifacts

Each Tier 1 database receives install and rollback artifacts grouped by schema
release. Release `0001` begins with MySQL:

```text
database/ddl/mysql/0001/install.sql
database/ddl/mysql/0001/rollback.sql
```

Committed artifacts use canonical table names without a schema qualifier or
custom prefix. They are generated from the same manifest as package migrations
and must remain byte-for-byte reproducible by the package's artifact generator.

Install artifacts create parents before children. Rollback artifacts remove
children before parents. They intentionally omit silent guards such as
`IF NOT EXISTS` and `IF EXISTS`, because an unexpected pre-existing or missing
object requires DBA investigation.

Vendor operational notes must state version requirements, execution order,
runtime privilege expectations, transaction limitations, backup responsibility,
and rollback risk. Generated SQL must not be edited manually.

### 3.8 Read-only schema inspection

DBA-managed environments must be inspectable without granting GateTrail schema
mutation privileges. A vendor inspector reads catalog metadata and compares it
with the canonical manifest.

The MySQL inspector queries `information_schema` using the connection's current
database, or the explicitly configured GateTrail schema when present. It
validates:

- required tables and canonical columns;
- column types, lengths, nullability, auto-increment behavior, and exact-name
  collation;
- required indexes and their ordered columns;
- primary, unique, foreign-key, and check constraints;
- referenced tables and columns;
- restrictive foreign-key update and delete rules;
- canonical check expressions.

Missing or mismatched required objects fail inspection. Unexpected columns also
fail strict conformance because a non-canonical column can change insert
behavior. Additional indexes are permitted because an organization may add
performance indexes without changing GateTrail's data contract.

Inspection must use read queries only. It must never create, alter, drop,
rename, disable, or repair an object. Catalog inspection passing is unit-level
evidence; a database adapter is not marked verified until the same inspection
runs successfully against its real supported database versions.

Schema inspection proves physical conformance only. It does not prove that
existing rows were created through managed services, that audit history is
complete, or that authorization caches were invalidated. Data diagnostics are
a separate read-only concern defined by
[ADR 0009](docs/decisions/0009-direct-database-mutation-and-diagnostics.md).

## 4. Terminology

### Subject

An Eloquent model that can receive roles or direct permissions. A typical
subject is an application's user model, but GateTrail must not require the model
to be named `User`.

### Actor

The person or process responsible for a mutation. An actor may be an Eloquent
model or an explicit system actor.

### Role

A named collection of permissions that may be assigned to a subject.

### Permission

A named ability checked by an application, such as `documents.approve`.

### Direct permission

A permission assigned directly to a subject without being inherited from a
role.

### Role assignment

A history-preserving relationship that grants a role to a subject.

### Permission assignment

A history-preserving relationship that grants a permission directly to a
subject.

### Role-permission assignment

A history-preserving relationship that includes a permission in a role.

### Active assignment

An assignment that:

- has not been revoked;
- has started, or has no start time;
- has not expired, or has no expiry time;
- points to active role and permission records where applicable.

### Effective permission

A permission currently available to a subject through at least one active
direct or role-based source.

### Mutation

An operation that creates, changes, assigns, grants, revokes, deactivates, or
restores RBAC data.

### Revoke and deactivate terminology

GateTrail uses these terms consistently:

- **revoke** applies only to an assignment;
- **deactivate** applies only to a role or permission definition;
- **restore** applies only to a deactivated role or permission definition.

Therefore, GateTrail revokes a subject-role, subject-permission, or
role-permission relationship. It deactivates the role or permission itself.
Public APIs and documentation must not use `deactivate` for assignments or
`revoke` for role and permission definitions.

## 5. Core authorization rules

### 5.1 Effective-permission formula

```text
Effective permissions
= active direct permission assignments
+ permissions from active role assignments
```

A permission is allowed when at least one valid source grants it.

### 5.2 Default deny

GateTrail denies a managed permission unless an active source grants it.

### 5.3 No explicit deny in the initial release

The initial release does not support deny assignments or deny precedence.

### 5.4 No implicit super administrator

No role bypasses permission checks automatically. A super-administrator bypass
is outside the initial scope and must not be inferred from a role name.

### 5.5 Multiple sources

The same permission may be available through multiple roles and a direct
assignment. Revoking one source must not deny access while another active source
still grants it.

### 5.6 Subject-specific evaluation

Authorization evaluates the subject passed to the resolver. It must never
silently replace that subject with the globally authenticated user.

The following must evaluate `$user`, even when another user is authenticated:

```php
$user->hasPermissionTo('documents.approve');
Gate::forUser($user)->allows('documents.approve');
```

### 5.7 Permission names

Permission names:

- are non-empty strings;
- are unique within a guard;
- are compared exactly;
- are normalized by trimming surrounding whitespace during creation;
- are not automatically converted to upper or lower case;
- should use dot-separated application conventions such as
  `documents.approve`.

Wildcard matching is not supported initially.

### 5.8 Role names

Role names:

- are non-empty strings;
- are unique within a guard;
- are compared exactly;
- are normalized by trimming surrounding whitespace during creation;
- are not authorization bypasses by themselves.

## 6. Guard behavior

Roles and permissions are scoped by `guard_name`. GateTrail treats this value as
a stable authorization namespace. It is not inferred from whichever Laravel
guard happens to authenticate the current request.

- A role may only receive permissions from the same guard.
- A subject may only receive roles and permissions compatible with its resolved
  guard.
- Cross-guard assignment throws a domain exception.
- Role and permission uniqueness is enforced by `(name, guard_name)`.
- One GateTrail guard is resolved for a subject during an operation.

Guard resolution uses this exact order:

1. public subject method `gateTrailGuardName()`;
2. declared subject property `$gateTrailGuardName`;
3. exact subject-class entry in `gatetrail.guards.subjects`;
4. package default `gatetrail.guards.default`;
5. Laravel configuration value `auth.defaults.guard`.

The first defined source is authoritative. An invalid authoritative value throws
`InvalidGuard` rather than silently falling through.

Resolved values are strings, trimmed of surrounding whitespace, rejected when
empty, and otherwise preserve case exactly. A guard name is not required to
exist in `auth.guards`, allowing several authentication mechanisms to share one
authorization namespace.

GateTrail does not inspect `Auth::user()`, route authentication middleware, or
mutable runtime authentication state during guard resolution. Per-call and
multi-guard subject resolution are outside the initial public API.

Exact override, configuration, mismatch, cache, and diagnostic behavior is
defined by
[ADR 0003](docs/decisions/0003-subject-guard-resolution.md).

## 7. Identifier strategy

### 7.1 Package-owned records

The initial schema uses 64-bit integer-compatible numeric primary keys for:

- roles;
- permissions;
- assignments;
- audit records.

The physical column type is selected by the database adapter. For example,
MySQL may use an unsigned big integer while Oracle may use a compatible
`NUMBER` definition.

### 7.2 Subject and actor identifiers

The initial default migrations support numeric subject morph identifiers
compatible with the selected database.

Applications using UUID or ULID identifiers may publish and adjust migrations
before running them. First-class UUID/ULID migration variants are outside
`0.1.0` and should be considered before `1.0.0` based on pilot requirements.

Actor identifiers use portable strings with a maximum length of 191
characters. Numeric human actor keys are stored using their canonical decimal
string representation. UUIDs, ULIDs, and named system actors retain their
canonical string representation.

The type `gatetrail.system` is reserved for named automated actors:

```text
actor_type = gatetrail.system
actor_id   = staff-sync
```

This decision applies to assigned, revoked, and audit actor columns. Full
normalization and persistence rules are defined by
[ADR 0005](docs/decisions/0005-actor-identity-representation.md).

### 7.3 Morph types

GateTrail uses Laravel polymorphic references for subjects and human actors.
Applications are encouraged to use Laravel morph maps so database values do not
depend permanently on full PHP class names. Named system actors use the
reserved `gatetrail.system` type and do not resolve to an Eloquent model.

### 7.4 Database identifier length

All package-owned database identifiers must be 30 characters or fewer,
including:

- tables;
- indexes;
- unique constraints;
- foreign-key constraints;
- sequences;
- triggers.

Migrations must provide explicit, concise names for indexes and constraints
instead of relying on framework-generated names. Primary-key names are logical
canonical names: engines that support physical naming use them, MySQL uses its
reserved `PRIMARY` name, and SQLite validates primary-key behavior without
requiring a physical constraint name.

Default identifier examples:

```text
acl_role_name_guard_uq
acl_perm_name_guard_uq
acl_ra_subject_ix
acl_ra_role_fk
acl_pa_subject_ix
acl_pa_perm_fk
acl_rp_role_fk
acl_rp_perm_fk
acl_audit_actor_ix
acl_audit_subject_ix
```

Default Oracle sequence and trigger names:

| Table | Sequence | Trigger |
|---|---|---|
| `acl_roles` | `acl_roles_seq` | `acl_roles_trg` |
| `acl_permissions` | `acl_perms_seq` | `acl_perms_trg` |
| `acl_role_assignments` | `acl_role_asgn_seq` | `acl_role_asgn_trg` |
| `acl_permission_assignments` | `acl_perm_asgn_seq` | `acl_perm_asgn_trg` |
| `acl_role_permissions` | `acl_role_perm_seq` | `acl_role_perm_trg` |
| `acl_access_audits` | `acl_audit_seq` | `acl_audit_trg` |

`acl_assignment_locks` uses a composite primary key and does not require an
Oracle sequence or trigger.

Oracle object names must be configurable so a DBA can supply organization-owned
sequence and trigger names. These names apply only when the
`sequence_trigger` generated-key strategy is selected. The `identity` strategy
uses Oracle-owned internal identity objects and GateTrail does not configure or
manage those internal names.

Package tooling should report configured identifiers that exceed the limit
before migrations are run where practical. Consumers that override table or
column names are responsible for preserving this compatibility rule.

## 8. Data model

### 8.1 Default tables

```text
acl_roles
acl_permissions
acl_role_assignments
acl_permission_assignments
acl_role_permissions
acl_assignment_locks
acl_access_audits
```

Table names are configurable before migrations are run.

The accepted relationship model is documented in the
[initial ERD](docs/architecture/initial-erd.md). The accepted migration-level
baseline is documented in the
[constraint and index plan](docs/architecture/constraints-and-indexes.md).

### 8.2 Roles

Minimum columns:

```text
id
name
guard_name
description
created_at
updated_at
deleted_at
```

Required constraints:

```text
acl_role_name_guard_uq: UNIQUE (name, guard_name)
```

### 8.3 Permissions

Minimum columns:

```text
id
name
guard_name
description
created_at
updated_at
deleted_at
```

Required constraints:

```text
acl_perm_name_guard_uq: UNIQUE (name, guard_name)
```

### 8.4 Role assignments

Minimum columns:

```text
id
subject_type
subject_id
role_id
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
created_at
updated_at
```

### 8.5 Direct permission assignments

Minimum columns:

```text
id
subject_type
subject_id
permission_id
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
created_at
updated_at
```

### 8.6 Role-permission assignments

Minimum columns:

```text
id
role_id
permission_id
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
created_at
updated_at
```

### 8.7 Audit records

Minimum columns:

```text
id
event
actor_type
actor_id
subject_type
subject_id
target_type
target_id
before
after
reason
context
ip_address
user_agent
request_id
created_at
```

The `before`, `after`, and `context` columns use portable text/CLOB storage with
Laravel array or JSON casts. Core behavior must not require native JSON column
types or database-specific JSON query operators.

Managed audit events serialize versioned UTF-8 JSON documents. Package-owned
numeric identifiers use canonical decimal strings, and timestamps use UTC ISO
8601 strings with microseconds. Exact version 1 snapshots, context, redaction,
metadata, and failure-mode rules are defined by
[ADR 0008](docs/decisions/0008-audit-snapshot-and-failure-modes.md).

### 8.8 Assignment coordination locks

Minimum columns:

```text
assignment_kind
scope_hash
scope_key
created_at
updated_at
```

Required constraints:

```text
acl_asgn_locks_pk: PRIMARY KEY (assignment_kind, scope_hash)
```

The coordination row serializes managed mutations for one equivalent assignment
scope. `scope_hash` is a lowercase hexadecimal SHA-256 digest of the canonical
scope key. `scope_key` uses portable text/CLOB storage and permits collision
detection after lookup.

Coordination rows are persistent package state and are not deleted during
normal operation. Their construction, locking, retry, and period-overlap rules
are defined by
[ADR 0001](docs/decisions/0001-unique-active-assignments.md).

## 9. Lifecycle rules

### 9.1 Role and permission lifecycle

```text
Active → Deactivated → Restored
```

Roles and permissions use Laravel soft deletion.

- Deactivated roles do not grant permissions.
- Deactivated permissions cannot be granted or allowed.
- Deactivation does not revoke or rewrite existing assignments.
- An assignment that remains otherwise active may become effective again when
  its role or permission is restored.
- Restoring a role or permission does not silently restore previously revoked
  assignments.
- Deactivation and restoration are mutations and must be audited.
- A deactivated definition retains its `(name, guard_name)` namespace.
- Creating a definition over a deactivated name throws `DuplicateRole` or
  `DuplicatePermission`; restore is the only initial path back to active.

Lifecycle comparison:

| Operation | Target | Scope of effect | Assignment history |
|---|---|---|---|
| Revoke | One assignment | Stops that subject-role, subject-permission, or role-permission relationship | Assignment remains permanently revoked |
| Deactivate | One role or permission definition | Suspends every effective permission path that depends on the definition | Assignments are preserved and not rewritten |
| Restore | One deactivated role or permission definition | Makes preserved, otherwise-active assignment paths eligible again | Previously revoked assignments remain revoked |

### 9.2 Assignment lifecycle

```text
Scheduled → Active → Expired
                   └→ Revoked
```

Assignments are not soft-deleted during normal package operation.

### 9.3 Active-time calculation

At time `T`, an assignment is active when:

```text
revoked_at IS NULL
AND COALESCE(starts_at, assigned_at) <= T
AND (expires_at IS NULL OR expires_at > T)
```

The effective half-open period is:

```text
[starts_at ?? assigned_at, expires_at ?? +infinity)
```

Expiry is exclusive. An assignment with `expires_at = T` is not active at `T`.

### 9.4 Assignment periods

- `expires_at` must be later than `starts_at ?? assigned_at`.
- An assignment starts at `assigned_at` when `starts_at` is omitted.
- An assignment may have no expiry.
- A revoked assignment remains revoked even when its original expiry is in the
  future.
- A persisted assignment period is immutable in the initial public API.
- Changing a period requires revoking the assignment and creating a new
  assignment with the replacement period.

### 9.5 Revocation

Revocation:

- sets `revoked_at`;
- records the revoking actor;
- requires a reason by default;
- does not delete the assignment;
- invalidates affected authorization caches;
- records an audit event.

### 9.6 Reassignment

When an equivalent revoked or expired assignment already exists, a new
assignment record is created. Old history is not overwritten or reactivated.

### 9.7 Duplicate active assignments

GateTrail must prevent multiple equivalent active assignments.

Prevention must use both:

- application-level transactional validation; and
- a database-enforced unique coordination key and pessimistic mutation lock.

Equivalent scopes, assignment-period overlap, database adapters, and retry
behavior are defined by
[ADR 0001](docs/decisions/0001-unique-active-assignments.md).

## 10. Mutation rules

### 10.1 Managed operations

GateTrail must provide managed operations for:

- creating, updating, deactivating, and restoring roles;
- creating, updating, deactivating, and restoring permissions;
- assigning and revoking roles;
- granting and revoking direct permissions;
- granting and revoking permissions on roles.

Initial role and permission updates may change `description` only. The
purpose-specific `RoleChanges` and `PermissionChanges` objects distinguish an
omitted description from explicitly setting the nullable description to
`null`.

Definition `name` and `guard_name` are immutable through the initial update
API. Rename and guard migration require future decisions covering assignments,
ability names, audit snapshots, and cache invalidation.

Assignment-period updates and rescheduling are not initial managed operations.
Consumers revoke the existing assignment and create a new history row.

### 10.2 Actor and reason

- Human-initiated mutations require an actor.
- Assignment and revocation require a non-empty reason by default.
- Automated processes use an explicit system actor/context.
- Actor identity must not be inferred only from `Auth::user()`.
- Configuration may relax reason requirements, but secure defaults remain
  enabled.

### 10.3 Transaction boundary

Metadata and mutation input are validated before persistence. The selected
audit mode then determines the transaction boundary.

Strict auditing uses one transaction:

```text
Validate
→ Persist mutation
→ Persist audit
→ Commit
→ Invalidate cache
→ Dispatch after-commit mutation event
```

When strict audit mode is enabled, failure to persist the audit record rolls
back the mutation and throws `AuditWriteFailed`.

Non-strict auditing commits the mutation before attempting the audit in a
separate transaction:

```text
Validate
→ Persist and commit mutation
→ Attempt audit in a new transaction
→ Invalidate cache
→ Dispatch after-commit mutation event
```

A non-strict audit failure leaves the mutation committed, returns a null audit
identifier, writes a safe operational error log, and dispatches an
`AuditPersistenceFailed` operational event. It does not throw after commit.

When GateTrail joins a caller-owned transaction in non-strict mode, it waits
for the outermost commit before attempting the audit in a new transaction. The
manager returns a null audit identifier because that audit is still pending.
An outer rollback creates no audit record. A connection without the required
after-commit semantics throws `UnsupportedAfterCommitBoundary` before mutation.
That requirement applies in every audit mode, because a caller-owned mutation
also defers cache invalidation and its successful mutation event.

When auditing is disabled, no audit write is attempted. Cache invalidation and
successful mutation events still occur.

### 10.4 Direct relationship mutation

Direct writes to GateTrail-owned data are unsupported. This includes manual
SQL, raw query-builder writes, external imports, and low-level Eloquent
mutation methods such as `attach()`, `detach()`, `sync()`, `save()`, or
`delete()`.

GateTrail guarantees validation, coordination, audit persistence, cache
invalidation, events, and domain exceptions only through managed package
services.

The resolver evaluates accepted persisted-state rules and does not attempt to
prove write origin. Read-only diagnostics can report observable
inconsistencies but cannot prove that no direct mutation occurred. GateTrail
does not automatically repair inconsistent rows.

Exact runtime, diagnostic, cache, privilege, and operational behavior is
defined by
[ADR 0009](docs/decisions/0009-direct-database-mutation-and-diagnostics.md).

### 10.5 Idempotency

- Repeating an active assignment request does not create a duplicate.
- Revoking a missing, expired, or already-revoked assignment throws
  `AssignmentNotActive` by default. An unrevoked scheduled assignment remains
  revocable before its start time so consumers can cancel future access.
- Repeating role or permission creation with an existing `(name, guard_name)`
  throws `DuplicateRole` or `DuplicatePermission`, including when the existing
  definition is deactivated.
- Mutation results must be deterministic and tested under concurrency.

### 10.6 Public mutation managers

GateTrail exposes three injectable public contracts:

```text
RoleManager
PermissionManager
AssignmentManager
```

The primary consumer API uses direct typed methods with named parameters.
Consumers do not construct public mutation commands or mutation-context objects
for ordinary operations:

```php
$assignments->assignRole(
    subject: $user,
    role: 'reviewer',
    actor: $admin,
    reason: 'Assigned by administrator',
);
```

Default manager implementations are thin adapters. They may translate public
arguments into immutable internal commands and delegate each operation to an
internal use-case handler. Internal commands and handlers are not public API and
must not contain a second consumer-facing mutation path.

Input resolution follows fixed rules:

- a role or permission model contract uses that model;
- a string role or permission is a name, including numeric-looking strings;
- an integer role or permission is a primary key;
- UUID, ULID, string, or otherwise ambiguous IDs use an explicit reference;
- assignment inputs are assignment models, explicit references, or IDs.

Scheduled operations use an optional immutable `AssignmentPeriod`. Update
operations may use purpose-specific change objects where nullable patch
semantics would otherwise be ambiguous. Automated processes use an explicit
`SystemActor`.

Exact public signatures and internal-boundary rules are defined by
[ADR 0002](docs/decisions/0002-public-mutation-api.md).

### 10.7 Mutation results

Every successful managed mutation returns a `MutationResult` containing:

```text
resource
outcome
nullable audit identifier
```

Initial outcomes are:

```text
Created
Updated
Deactivated
Restored
Revoked
Unchanged
```

An idempotent no-op returns `Unchanged` and does not write a duplicate audit
record, invalidate cache, or dispatch a duplicate mutation event.

The audit identifier is null when auditing is disabled, a non-strict audit
write fails, a non-strict audit is pending after a caller-owned transaction,
or the mutation outcome is `Unchanged`. Exact result and failure semantics are
defined by
[ADR 0008](docs/decisions/0008-audit-snapshot-and-failure-modes.md).

## 11. Authorization resolvers

### 11.1 Responsibility

One `PermissionResolver` is the source of truth for:

- direct permission checks;
- permissions inherited through roles;
- effective permission collections;
- Gate integration;
- middleware integration.

### 11.2 Resolution algorithm

For a subject and permission:

1. Normalize the permission reference.
2. Resolve the subject guard.
3. Return false when an explicit permission model, identifier, or reference
   resolves to another guard.
4. Find an active permission in the resolved guard.
5. Check for an active direct assignment.
6. Check active subject-role assignments.
7. Exclude deactivated roles.
8. Check active role-permission assignments.
9. Allow when any active source is found.
10. Deny otherwise.

### 11.3 Initial resolver contract

```php
interface PermissionResolver
{
    public function allows(
        mixed $subject,
        PermissionContract|PermissionReference|string|int $permission,
    ): bool;

    public function effectivePermissions(mixed $subject): Collection;
}
```

The implementation may use more specific contracts than the illustrative
signature above. Public input types must be finalized before `0.1.0`.

### 11.4 Time source

Time-based authorization uses an injectable clock abstraction. Tests must not
depend on the machine's current time.

Point-in-time historical permission queries are not part of the initial public
API.

### 11.5 Role resolver

One `RoleResolver` is the source of truth for:

- `hasRole`, `hasAnyRole`, and `hasAllRoles`;
- effective role collections;
- singular, any, and all role middleware;
- future role-specific adapters.

A subject has an effective role when:

- the subject-role assignment is active under section 9.3;
- the role definition is active;
- the role belongs to the subject's resolved guard.

A missing role denies without a not-found exception. An explicit role model,
identifier, or reference resolving outside the subject's guard also returns
false. Mutation operations remain different and throw `GuardMismatch` for
cross-guard input.

The initial illustrative contract is:

```php
interface RoleResolver
{
    public function has(
        mixed $subject,
        RoleContract|RoleReference|string|int $role,
    ): bool;

    public function effectiveRoles(mixed $subject): Collection;
}
```

Any and all checks derive from the same effective-role set. They must not
implement independent lifecycle or guard rules. The exact active-role behavior
aligns with
[ADR 0004](docs/decisions/0004-middleware-any-all-syntax.md).

## 12. Laravel integration

### 12.1 Subject concern

An application subject uses a package concern:

```php
class User extends Authenticatable
{
    use HasGateTrail;
}
```

The concern is a thin adapter. It delegates authorization to package services
and does not contain an independent permission algorithm.

Subjects may declare a stable guard through:

```php
protected string $gateTrailGuardName = 'admin';
```

or:

```php
public function gateTrailGuardName(): string
{
    return 'admin';
}
```

The method wins when both are present. The property is a declared PHP property,
not an Eloquent attribute or database column.

### 12.2 Subject API

Proposed methods:

```php
$user->hasRole('administrator');
$user->hasAnyRole(['administrator', 'editor']);
$user->hasAllRoles(['editor', 'reviewer']);
$user->hasPermissionTo('documents.approve');
$user->effectivePermissions();
$user->roles();
$user->directPermissions();
$user->roleAssignments();
$user->permissionAssignments();
```

Assignment mutations are performed through managed services rather than
relationship methods.

Role helper methods delegate to the shared `RoleResolver`. Permission helpers
delegate to the shared `PermissionResolver`. The subject concern does not
implement separate role, permission, lifecycle, or guard algorithms.

### 12.3 Gate integration

GateTrail registers one centralized Laravel `Gate::before` hook. It does not
register every database permission as an individual Gate ability.

Rules:

- when the subject cannot be resolved as a GateTrail subject, return `null`;
- when Laravel supplies one or more ability arguments, return `null` and allow
  its normal resource Gate or policy to continue;
- for an argument-free ability, look for an exact permission-name definition
  in the subject's resolved guard, including deactivated definitions;
- when no definition exists, return `null`;
- when an active definition exists, return the resolver's boolean decision;
- when a deactivated definition exists, return `false`;
- returning `null` allows normal Laravel gates and policies to continue;
- a non-null result is authoritative once GateTrail's callback is invoked;
- GateTrail must not hide database or configuration failures.

Laravel executes global `before` callbacks in registration order. A consumer
callback registered earlier may pre-empt GateTrail by returning a non-null
result. Such a callback is an application-owned global bypass; GateTrail does
not provide or infer one.

Laravel's inline `Gate::allowIf()` and `Gate::denyIf()` methods bypass global
Gate hooks and therefore do not evaluate GateTrail permissions.

Expected usage:

```php
$user->can('documents.approve');
Gate::forUser($user)->allows('documents.approve');
Gate::authorize('documents.approve');
```

Abilities with resource arguments remain application Gate or policy checks:

```php
$user->can('update', $document);
Gate::forUser($user)->allows('create', Document::class);
```

An application policy may perform a separate argument-free GateTrail
permission check as part of its resource decision. Definition matching,
callback ordering, failure behavior, and policy coexistence are defined by
[ADR 0007](docs/decisions/0007-laravel-gate-hook-boundary.md).

### 12.4 Policies

GateTrail handles named permissions. Consumer applications remain responsible
for resource and state rules:

```php
public function approve(User $user, Document $document): bool
{
    return $user->can('documents.approve')
        && $document->isPending();
}
```

### 12.5 Middleware

Initial permission middleware aliases:

```text
gatetrail.permission
gatetrail.permission.any
gatetrail.permission.all
```

Initial role middleware aliases:

```text
gatetrail.role
gatetrail.role.any
gatetrail.role.all
```

Expected usage:

```php
Route::post('/documents/{document}/approve', ...)
    ->middleware([
        'auth',
        'gatetrail.permission:documents.approve',
    ]);

Route::get('/documents', ...)
    ->middleware([
        'auth',
        'gatetrail.permission.any:documents.view,documents.review',
    ]);

Route::post('/documents/{document}/publish', ...)
    ->middleware([
        'auth',
        'gatetrail.permission.all:documents.review,documents.publish',
    ]);
```

Singular aliases require exactly one parameter. Any and all aliases require one
or more comma-separated parameters. Values are trimmed, preserve case, reject
empties, and are deduplicated before evaluation.

Permission middleware uses GateTrail's resolver directly rather than allowing
an unrelated Laravel Gate with the same name to satisfy the check. Missing role
or permission definitions deny access without throwing a not-found exception.

Multiple middleware entries compose as AND between groups. Boolean expression
parsing, negation, parentheses, and wildcards are not supported initially.

Unauthorized behavior:

- unauthenticated subjects receive Laravel's `AuthenticationException`;
- authenticated subjects without access receive Laravel's
  `AuthorizationException`;
- the default authorization-denial response is 403;
- authentication redirects or 401 responses follow Laravel and application
  configuration.

Invalid alias cardinality or empty parameters throw
`InvalidMiddlewareParameter` as a developer configuration error. Full syntax,
composition, alias stability, and disclosure rules are defined by
[ADR 0004](docs/decisions/0004-middleware-any-all-syntax.md).

### 12.6 Blade

Consumers should use Laravel's native directives:

```blade
@can('documents.approve')
    <!-- Authorized content -->
@endcan
```

Role-specific Blade directives may be provided for convenience, but Blade
visibility is never considered the server-side security boundary.

## 13. Audit requirements

### 13.1 Required events

Initial audit events:

```text
role.created
role.updated
role.deactivated
role.restored
permission.created
permission.updated
permission.deactivated
permission.restored
role.assigned
role.revoked
permission.granted_to_subject
permission.revoked_from_subject
permission.granted_to_role
permission.revoked_from_role
```

### 13.2 Append-only behavior

- Audit records are append-only through the public package API.
- The audit model does not expose update or delete operations as normal package
  behavior.
- Audit pruning is not enabled by default.
- Consumer retention policies must be explicit.

### 13.3 Snapshot contract

Every managed event uses audit document `schema_version` 1.

Definition snapshots contain:

```text
id
name
guard_name
description
deleted_at
```

Assignment snapshots contain all security-relevant assignment identity,
period, assignment-actor, assignment-reason, revocation-actor, and
revocation-reason fields defined in section 8.

Event shapes are:

- create, assign, and grant: null `before`, full `after`;
- update, deactivate, restore, and revoke: full `before`, full `after`;
- idempotent `Unchanged`: no audit record.

Every managed audit context contains:

```text
schema_version
guard_name
applicable role_name and/or permission_name
metadata
```

`metadata` is always an object and is empty when the consumer supplies none.
Snapshot identifiers use canonical strings, and timestamps use UTC ISO 8601
with microseconds. Exact event keys and serialization rules are defined by
[ADR 0008](docs/decisions/0008-audit-snapshot-and-failure-modes.md).

Managed audit `target_type` values are stable logical aliases:

| Event group | `target_type` |
|---|---|
| Role definition events | `role` |
| Permission definition events | `permission` |
| Role assignment events | `role_assignment` |
| Direct permission assignment events | `permission_assignment` |
| Role-permission assignment events | `role_permission` |

`target_id` identifies the persisted row represented by the event snapshots.

### 13.4 Sensitive data

Audit snapshots and context must exclude:

- passwords;
- access tokens;
- private keys;
- session identifiers;
- raw request bodies;
- fields configured as sensitive.

Snapshots contain package-owned fields only. Consumer metadata is a
string-keyed associative array stored only under `context.metadata`; nested
lists remain valid. An empty PHP array becomes an empty JSON object. A redacted
value uses the reserved `__gatetrail_redacted__` marker so it remains
distinguishable from an absent key. Configured sensitive paths may also redact
descriptions and mutation, assignment, or revocation reasons.

Metadata must contain JSON-safe values. The secure defaults are a maximum
normalized encoded size of 16 KiB and a maximum nesting depth of 8. Limits
remain configurable but finite. Invalid metadata throws
`InvalidAuditMetadata` before mutation persistence; it is never silently
truncated or discarded.

### 13.5 Request metadata

IP address, user agent, and request ID are captured when available. Their
absence in CLI or queue execution does not invalidate the audit event.

GateTrail captures request metadata through explicit resolvers. It does not copy
raw request bodies or all request headers into audit context.

### 13.6 Audit modes

Strict audit mode is enabled by default:

```php
'audit' => [
    'enabled' => true,
    'strict' => true,
],
```

Mode behavior:

| Enabled | Strict | Behavior |
|---:|---:|---|
| `true` | `true` | Mutation and audit share one transaction; audit failure rolls back and throws `AuditWriteFailed` |
| `true` | `false` | Mutation commits first; audit uses a separate transaction; failure is logged safely, dispatches `AuditPersistenceFailed`, and returns a null audit identifier |
| `false` | Either | Audit is skipped and the mutation returns a null audit identifier |

When non-strict auditing joins a caller-owned transaction, audit persistence
waits for its outermost commit and the immediate mutation result contains no
audit identifier.

Non-strict and disabled auditing weaken the package's history guarantee and
must be explicit consumer choices. GateTrail never changes modes automatically
after a failure.

## 14. Cache behavior

### 14.1 Correctness first

The resolver must be correct without cross-request caching.

### 14.2 Initial strategy

GateTrail initially provides:

1. request-level memoization;
2. optional Laravel cache for effective permissions;
3. automatic invalidation through managed mutations.

### 14.3 Cache keys

Cache keys include:

```text
guard
subject type
subject identifier
```

Changing a subject guard override or guard configuration is a deployment
change. It does not migrate assignments and requires GateTrail caches to be
cleared.

### 14.4 Invalidation

Affected subject caches are invalidated after:

- role assignment or revocation;
- direct permission grant or revocation;
- role-permission grant or revocation;
- role deactivation or restoration;
- permission deactivation or restoration.

### 14.5 Expiry safety

A cache entry must not remain valid beyond the nearest relevant assignment
expiry unless another mechanism guarantees timely invalidation.

### 14.6 Unmanaged writes

Direct writes do not trigger GateTrail cache invalidation. If cross-request
caching is enabled, an unmanaged change may leave a stale allow or deny until
an explicit cache clear, expiry, or later managed invalidation.

Known direct intervention requires an explicit GateTrail cache clear.
`gatetrail:doctor` never clears caches implicitly. The future cache ADR must
define bounded staleness without treating direct mutation as supported.

Cross-request caching remains disabled by default until its store, TTL,
role-change fan-out, and invalidation strategy are accepted and measured.

## 15. Exceptions

GateTrail uses specific domain exceptions. Initial exception categories:

```text
RoleNotFound
PermissionNotFound
DuplicateRole
DuplicatePermission
DuplicateActiveAssignment
AssignmentNotActive
InvalidAssignmentPeriod
InvalidGuard
InvalidMiddlewareParameter
GuardMismatch
InvalidSubject
InvalidActor
ReasonRequired
InvalidAuditMetadata
InvalidConcurrencyConfiguration
UnsupportedAfterCommitBoundary
AuditWriteFailed
ConcurrencyRetryExhausted
UnauthorizedMutation
```

Exceptions must provide safe diagnostic context without exposing sensitive
data.

`ConcurrencyRetryExhausted` is raised only when a positively classified
assignment concurrency failure cannot be retried, or exhausts its bounded
attempts. It exposes `operation` as a canonical assignment mutation name,
`attempts` as the attempts actually made, and `callerOwned` to distinguish an
unavailable retry inside a caller-owned transaction. Its safe diagnostics are
the driver name, a normalized failure category, a nullable SQLSTATE and a
nullable numeric vendor code. `getPrevious()` is always `null`, and it neither
exposes nor retains raw SQL, bindings, driver prose, identifiers, reasons,
metadata or credentials. Domain exceptions and unclassified infrastructure
exceptions are never converted into it. The complete contract is defined by
[ADR 0011](docs/decisions/0011-bounded-concurrency-retry.md).

`InvalidConcurrencyConfiguration` reports an invalid
`assignments.concurrency_attempts` value. It names the configuration key and
never echoes the rejected value.

## 16. Events

Package domain events mirror successful mutations.

Rules:

- events are dispatched after the transaction commits;
- an event is not dispatched for a rolled-back mutation;
- event payloads include identifiers and safe context;
- events do not replace persistent audit records;
- consumers may use events for notifications or integrations.

`AuditPersistenceFailed` is a separate operational event used only when a
non-strict post-commit audit write fails. It is not a successful mutation
domain event and contains safe identifiers rather than snapshots, raw
metadata, or exception messages.

The exact successful mutation event contract — its class and shape, stable
mutation names, payload fields, dispatch ordering in every audit mode, and
listener failure policy — is defined by
[ADR 0010](docs/decisions/0010-after-commit-mutation-events.md) and is
implemented. Every changed managed mutation dispatches one
`MutationCommitted` after its outermost successful commit, carrying safe
identifiers only. Event production is always on: consumers opt in by
registering listeners, and dispatching with none registered is normal.

## 17. Configuration

Proposed configuration sections:

```php
return [
    'database' => [],
    'models' => [],
    'tables' => [],
    'columns' => [],
    'oracle' => [
        'generated_keys' => 'sequence_trigger',
        'sequences' => [],
        'triggers' => [],
    ],
    'guards' => [
        'default' => null,
        'subjects' => [
            // App\Models\Admin::class => 'admin',
        ],
    ],
    'assignments' => [
        'require_reason' => true,
        'concurrency_attempts' => 3,
    ],
    'audit' => [
        'enabled' => true,
        'strict' => true,
        'metadata' => [
            'max_bytes' => 16384,
            'max_depth' => 8,
        ],
        'request' => [
            'resolvers' => [],
        ],
    ],
    'cache' => [],
    'middleware' => [],
    'sensitive_fields' => [],
];
```

The `assignments` section configures the reason requirement and
`concurrency_attempts`, the bounded retry limit for assignment coordination.

`concurrency_attempts` must be a real PHP integer between `1` and `10`
inclusive, defaulting to `3`. It is never coerced: booleans, floats, numeric
strings, `null`, arrays, objects, zero, negatives and values above `10` are
invalid, and invalid configuration fails before an assignment transaction
begins. A value of `1` means one attempt with no automatic retry, while
preserving the typed exhaustion contract for classified concurrency failures.

It applies only to the six `AssignmentManager` mutations and never enables
retry for role or permission definition mutations. The initial accepted design
has no package-level backoff, and lock wait timeouts and SQLite `BUSY`/`LOCKED`
conditions remain non-retryable. The complete contract is defined by
[ADR 0011](docs/decisions/0011-bounded-concurrency-retry.md).

The `audit` section configures:

```text
enabled
strict
metadata maximum bytes
metadata maximum depth
request metadata resolvers
```

The `sensitive_fields` section configures redaction paths. Metadata limits must
remain finite. Configuration cannot disable the required snapshot schema or
permit prohibited sensitive data.

Configuration is publishable as:

```text
config/gatetrail.php
```

Database configuration must support:

- a named Laravel connection;
- an optional schema or table prefix;
- package-managed or DBA-managed provisioning mode;
- a validated Oracle generated-key strategy;
- configurable Oracle sequence names;
- configurable Oracle trigger names;
- identifier-length validation.

Environment variables should be reserved for operational settings, not used as
a substitute for documented configuration.

## 18. Console commands

Commands planned across pre-`1.0.0` releases:

```text
gatetrail:install
gatetrail:create-role
gatetrail:create-permission
gatetrail:matrix
gatetrail:doctor
gatetrail:cache-clear
```

Mutation commands require an explicit actor or system context and a reason when
the equivalent service operation requires one.

`gatetrail:doctor` must validate the assignment coordination table and report
missing or malformed coordination rows and overlapping equivalent assignments
without modifying them.

Its read-only data checks include:

- overlapping equivalent non-revoked assignment periods;
- missing, duplicated, or malformed coordination rows;
- a `scope_key` that does not produce its stored `scope_hash`;
- invalid assignment periods or partially populated revocation tuples;
- missing referenced role or permission definitions;
- assignments whose referenced definition guard no longer matches the
  subject's currently resolved guard;
- role-permission assignments whose definitions use different guards.

An assignment referencing a deactivated definition is valid retained history.
Doctor may report it as informational but must not classify it as an error.
Expired, scheduled, and normally revoked assignments are also valid lifecycle
states.

Doctor must not migrate, repair, revoke, rewrite, backfill, or delete data. It
must not clear caches implicitly. A clean result must state that best-effort
diagnostics cannot prove the absence of direct writes or missing audit history.
Partial inspection must identify its coverage and cannot report an unqualified
clean result.

It should report when a default GateTrail middleware alias is already bound to
an unrelated middleware class. GateTrail must not silently replace that alias.

Diagnostic severity, limitations, cache remediation, and machine-readable
output requirements are defined by
[ADR 0009](docs/decisions/0009-direct-database-mutation-and-diagnostics.md).

## 19. Security requirements

GateTrail must:

- apply default-deny authorization;
- use bound query parameters or Eloquent;
- avoid SQL string concatenation;
- prevent cross-guard assignment;
- prevent duplicate active assignments under concurrency;
- prevent revoked, expired, future, or deactivated sources from granting access;
- avoid global authenticated-user substitution;
- resolve subject guards independently of global authentication state;
- keep authorization mutation and strict audit writes transactional;
- prevent consumer input from selecting an arbitrary audit actor;
- avoid silent super-administrator behavior;
- keep GateTrail middleware evaluation independent from unrelated Laravel Gates;
- reject invalid middleware parameter lists instead of guessing their intent;
- keep provenance heuristics and diagnostic scans outside runtime permission
  queries;
- avoid claiming audit, cache, or provenance guarantees for direct writes;
- keep diagnostics read-only and avoid requiring elevated runtime schema
  privileges;
- document all bypass or extension points;
- provide a security reporting process before public release.

## 20. Testing requirements

### 20.1 Unit tests

- input normalization;
- assignment time rules;
- null `starts_at` using `assigned_at` as the effective lower bound;
- direct permission resolution;
- role permission resolution;
- effective role resolution;
- singular, any, and all role checks sharing one effective set;
- multiple-source resolution;
- guard matching;
- cross-guard explicit role and permission references denying checks;
- subject guard precedence and normalization;
- exception behavior;
- cache-key generation;
- time abstraction;
- canonical assignment scope keys and hashes;
- half-open assignment-period overlap rules;
- public input resolution rules;
- description-only role and permission changes;
- immutable definition names, guards, and persisted assignment periods;
- assignment-period value objects;
- mutation outcomes.
- canonical audit snapshot serialization;
- metadata normalization, size, depth, and redaction rules.

### 20.2 Integration tests

- migrations;
- Eloquent relationships;
- manager contract container bindings;
- direct public mutation methods;
- public-argument to internal-command translation;
- subject role helpers and role middleware sharing `RoleResolver`;
- duplicate creation over deactivated definitions returning domain exceptions;
- description update omitted, explicit-null, unchanged, and changed behavior;
- mutation transactions;
- assignment coordination-row locking;
- assignment scheduling with overlapping and adjacent periods;
- audit persistence;
- exact event-by-event version 1 before, after, and context documents;
- strict, non-strict, and disabled audit modes;
- non-strict `AuditPersistenceFailed` logging and event behavior;
- Laravel Gate;
- `Gate::forUser`;
- subject method, property, class-config, package-default, and Laravel-default
  guard resolution;
- guard resolution in HTTP, queue, CLI, and test contexts;
- all six middleware aliases;
- singular, any, all, missing-definition, and grouped middleware behavior;
- middleware parameter normalization and route-cache compatibility;
- Blade through Gate;
- cache invalidation;
- domain events after commit.

### 20.3 Security regression tests

- default deny;
- revoked assignments;
- expired assignments;
- future assignments;
- deactivated roles;
- deactivated permissions;
- cross-subject evaluation;
- cross-guard isolation;
- cross-guard model, identifier, and explicit-reference checks denying without
  disclosing definitions;
- cross-guard mutations continuing to throw `GuardMismatch`;
- deactivated definition names remaining unavailable for duplicate creation;
- invalid authoritative guard without silent fallback;
- global authentication state not affecting subject guard resolution;
- middleware authentication and authorization exception behavior;
- argument-bearing Gate checks continuing to application policies;
- same-named application Gates not satisfying active but unassigned GateTrail
  permissions;
- deactivated GateTrail permissions denying without falling through to
  same-named application Gates;
- unsupported subjects and missing permission definitions returning `null`
  from the GateTrail hook;
- Gate integration surfacing invalid guard, database, and configuration
  failures;
- unrelated Laravel Gates not satisfying GateTrail middleware;
- middleware denial responses not disclosing missing definitions;
- duplicate concurrent assignment;
- coordination hash mismatch;
- bounded concurrency retry exhaustion;
- strict audit rollback;
- non-strict audit failure preserving the committed mutation;
- non-strict audit waiting for a caller-owned outer commit and skipping writes
  after an outer rollback;
- unsupported non-strict after-commit boundaries rejected before mutation;
- application-model attributes excluded from audit documents;
- malformed, oversized, deeply nested, recursive, non-UTF-8, and non-finite
  metadata rejected before mutation;
- sensitive audit values redacted without conflating absent values;
- unsafe input handling;
- resolver behavior remaining independent from write-origin guesses;
- direct writes not receiving managed audit, cache, coordination, or event
  guarantees;
- doctor reporting deactivated references as informational rather than errors;
- doctor never repairing data or clearing caches;
- clean and partial doctor results disclosing their diagnostic limitations.

### 20.4 CI matrix

CI must cover every advertised PHP and Laravel combination that Composer can
validly install.

Database-specific behavior must be tested against each advertised production
database before `1.0.0`.

Oracle and SQL Server are required in the pre-`1.0.0` CI or release-validation
matrix. Neither database may be marked supported based only on SQLite, MySQL,
or PostgreSQL test results.

Oracle validation must use the supported Laravel-OCI8 major for each Laravel
major:

```text
Laravel 12 → yajra/laravel-oci8 12.x
Laravel 13 → yajra/laravel-oci8 13.x
```

### 20.5 Live database evidence

MySQL 8.0.16 and 8.0.45 are the first live database endpoints. The isolated
integration suite runs each version against Laravel 12/Testbench 10 and Laravel
13/Testbench 11 and covers:

- package-managed install, strict catalog inspection, and rollback;
- DBA-managed release `0001` install, strict catalog inspection, and rollback;
- physical `LONGTEXT`, fixed `CHAR(64)` hash, collation, unsigned ID, index,
  foreign-key rule, and check-expression metadata;
- detection of missing indexes, changed exact-name collation, and cascading
  foreign keys without automatic repair.

This evidence verifies the minimum supported and current MySQL 8.0 reference
endpoints locally. The GitHub Actions workflow declares the same four-lane
matrix. Release-level CI evidence requires a successful remote workflow run;
workflow configuration alone is not treated as a passing run.

## 21. Performance requirements

- Permission checks must avoid an N+1 query per role.
- Repeated checks within one request should use memoized results.
- Cache invalidation must be deterministic.
- Performance optimizations must preserve authorization parity with the
  uncached resolver.
- Benchmarks should record subject role count, permission count, query count,
  cold-cache time, and warm-cache time.

No performance claim may be published without a reproducible benchmark.

## 22. Public API stability

- APIs are experimental before `1.0.0`.
- Public contracts, config keys, migration columns, events, and exception types
  are part of the compatibility surface.
- Internal classes should be marked and documented as internal where possible.
- Consumers should depend on contracts rather than concrete services when an
  extension point is intended.
- Public manager signatures, named parameters, reference interpretation,
  mutation results, and documented value objects are compatibility surface.
- Internal commands, handlers, repositories, adapters, and orchestration
  services are not public API.
- Breaking changes require changelog entries and an upgrade guide.

## 23. Explicit non-goals

The initial release does not include:

- authentication or session management;
- an administration UI;
- application-specific authorization policies;
- explicit deny rules;
- wildcard permissions;
- role hierarchy;
- team or tenant-scoped roles;
- approval workflows;
- separation-of-duties rules;
- a super-administrator bypass;
- point-in-time historical authorization queries;
- guaranteed UUID/ULID migration variants;
- automatic audit pruning;
- support for every database engine.

## 24. Open architecture decisions

The following decisions must be recorded before their implementation is
finalized:

1. cache store, TTL, and role-change fan-out strategy;
2. whether UUID/ULID subject migration variants are required before `1.0.0`;
3. whether role convenience directives belong in the core package.

Persisted actor representation is accepted in
[ADR 0005](docs/decisions/0005-actor-identity-representation.md).

Minimum audit snapshot content and audit failure modes are accepted in
[ADR 0008](docs/decisions/0008-audit-snapshot-and-failure-modes.md).

Direct database mutation and diagnostic behavior are accepted in
[ADR 0009](docs/decisions/0009-direct-database-mutation-and-diagnostics.md).

The successful mutation event contract is accepted in
[ADR 0010](docs/decisions/0010-after-commit-mutation-events.md) and
implemented.

Bounded concurrency retry for assignment coordination is accepted in
[ADR 0011](docs/decisions/0011-bounded-concurrency-retry.md) and implemented.

Architecture decisions will be stored under:

```text
docs/decisions/
```

## 25. Acceptance criteria for implementation start

Implementation may begin when:

- the owner approves this specification;
- compatibility targets are accepted;
- effective-permission rules are unambiguous;
- lifecycle and revocation behavior are accepted;
- initial public API direction is accepted;
- non-goals are accepted;
- open architecture decisions required by the first vertical slice are
  identified.

These criteria are satisfied for the accepted baseline recorded in
[Specification Freeze Check 0001](docs/reviews/0001-specification-freeze-check.md).

## 26. First vertical slice

The first implementation milestone must prove this flow:

```text
Create role
→ Create permission
→ Grant permission to role with actor and reason
→ Assign role to subject with actor and reason
→ Laravel Gate allows the permission
→ Revoke the role assignment
→ Laravel Gate denies the permission
→ Assignment history remains
→ Audit records contain actor and reason
```

The vertical slice is complete only when it is covered by automated integration
tests.

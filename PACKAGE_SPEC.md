# GateTrail Package Specification

## 1. Document status

| Item | Value |
|---|---|
| Package | `hangtuah/laravel-gatetrail` |
| Namespace | `HangTuah\GateTrail` |
| Specification status | Draft |
| Product status | Pre-alpha |
| Target release | `0.1.0` foundation, `1.0.0` after an internal pilot |

This specification defines the expected behavior of GateTrail before
implementation begins. Where this document conflicts with implementation code,
the conflict must be resolved explicitly rather than silently changing package
behavior.

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
| `acl_roles` | Stores role definitions | Active, soft-deleted, or restored |
| `acl_permissions` | Stores permission definitions | Active, soft-deleted, or restored |
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

### 3.1 Initial support matrix

| Dependency | Supported versions |
|---|---|
| PHP | `^8.3` |
| Laravel | `^12.0 \| ^13.0` |
| Orchestra Testbench | `^10.0 \| ^11.0` |
| Oracle Database | 12c Release 2 or later |
| Laravel Oracle driver | `yajra/laravel-oci8:^12.0 \| ^13.0` matching the Laravel major |
| MySQL | 8.0 or later |
| PostgreSQL | 14 or later |
| SQL Server | 2017 or later |
| SQLite | Current supported version for automated tests |

Oracle, MySQL, PostgreSQL, and SQL Server are Tier 1 production databases.
SQLite is a Tier 1 test database but is not the reference production database.

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

### 3.4 Oracle integration

Oracle support is a first-class requirement.

GateTrail supports two Oracle provisioning modes:

1. **Package-managed** — published GateTrail migrations create the required
   tables and use Laravel-OCI8 schema support for Oracle-specific objects.
2. **DBA-managed** — a DBA creates tables, indexes, constraints, sequences, and
   triggers from the GateTrail schema contract before the application is
   deployed.

In DBA-managed mode:

- GateTrail migrations for the existing objects are not executed;
- the package does not require runtime permission to create or alter schema
  objects;
- DBA-owned triggers may populate primary keys from DBA-owned sequences;
- configured sequence and trigger names must be supported;
- `gatetrail:doctor` must validate the expected tables, columns, indexes, and
  identifier configuration without modifying the schema;
- GateTrail must provide a versioned schema manifest or reference DDL describing
  the objects required by each package release.

Oracle integration tests must cover:

- inserts where a trigger populates the primary key;
- Eloquent receiving the generated primary key after insert;
- package-managed sequence/trigger behavior;
- DBA-managed pre-existing sequence/trigger behavior;
- coordination-row creation and locking in package-managed and DBA-managed
  schemas;
- transactions and rollback;
- soft deletion;
- CLOB audit payload casts;
- date and timestamp comparisons;
- unique active-assignment concurrency;
- schema-qualified table names;
- identifier length and Oracle name normalization.

### 3.5 SQL Server integration

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
instead of relying on framework-generated names.

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
sequence and trigger names.

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
[initial ERD](docs/architecture/initial-erd.md). The proposed migration-level
rules are documented in the
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
AND (starts_at IS NULL OR starts_at <= T)
AND (expires_at IS NULL OR expires_at > T)
```

Expiry is exclusive. An assignment with `expires_at = T` is not active at `T`.

### 9.4 Assignment periods

- `expires_at` must be later than `starts_at` when both are provided.
- An assignment may start immediately when `starts_at` is omitted.
- An assignment may have no expiry.
- A revoked assignment remains revoked even when its original expiry is in the
  future.

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

### 10.2 Actor and reason

- Human-initiated mutations require an actor.
- Assignment and revocation require a non-empty reason by default.
- Automated processes use an explicit system actor/context.
- Actor identity must not be inferred only from `Auth::user()`.
- Configuration may relax reason requirements, but secure defaults remain
  enabled.

### 10.3 Transaction boundary

Each mutation executes in one database transaction:

```text
Validate
→ Persist mutation
→ Persist audit
→ Commit
→ Invalidate cache
→ Dispatch after-commit event
```

When strict audit mode is enabled, failure to persist the audit record rolls
back the mutation.

### 10.4 Direct relationship mutation

GateTrail does not guarantee auditing or cache invalidation when a consumer
directly calls low-level Eloquent methods such as `attach()`, `detach()`, or
manual table updates.

Documentation must direct consumers to managed package services. The package
should make unsafe mutation paths difficult where practical.

### 10.5 Idempotency

- Repeating an active assignment request does not create a duplicate.
- Revoking a non-active assignment throws a domain exception by default.
- Repeating role or permission creation with an existing name throws a
  duplicate exception.
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
audit identifier, when an audit record was created
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

## 11. Permission resolver

### 11.1 Responsibility

One resolver is the source of truth for:

- direct permission checks;
- permissions inherited through roles;
- effective permission collections;
- Gate integration;
- middleware integration.

### 11.2 Resolution algorithm

For a subject and permission:

1. Normalize the permission reference.
2. Resolve the subject guard.
3. Find an active permission in that guard.
4. Check for an active direct assignment.
5. Check active subject-role assignments.
6. Exclude deactivated roles.
7. Check active role-permission assignments.
8. Allow when any active source is found.
9. Deny otherwise.

### 11.3 Initial resolver contract

```php
interface PermissionResolver
{
    public function allows(
        mixed $subject,
        Permission|string|int $permission,
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

### 12.3 Gate integration

GateTrail integrates through a centralized Laravel Gate hook.

Rules:

- when an ability matches an active GateTrail permission, return the resolver's
  boolean decision;
- when no GateTrail permission matches the ability, return `null`;
- returning `null` allows normal Laravel gates and policies to continue;
- GateTrail must not register every database permission during service-provider
  boot;
- GateTrail must not hide database or configuration failures.

Expected usage:

```php
$user->can('documents.approve');
Gate::forUser($user)->allows('documents.approve');
Gate::authorize('documents.approve');
```

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

### 13.3 Sensitive data

Audit snapshots and context must exclude:

- passwords;
- access tokens;
- private keys;
- session identifiers;
- raw request bodies;
- fields configured as sensitive.

### 13.4 Request metadata

IP address, user agent, and request ID are captured when available. Their
absence in CLI or queue execution does not invalidate the audit event.

### 13.5 Strict mode

Strict audit mode is enabled by default:

```php
'audit' => [
    'enabled' => true,
    'strict' => true,
],
```

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
AuditWriteFailed
UnauthorizedMutation
```

Exceptions must provide safe diagnostic context without exposing sensitive
data.

## 16. Events

Package domain events mirror successful mutations.

Rules:

- events are dispatched after the transaction commits;
- an event is not dispatched for a rolled-back mutation;
- event payloads include identifiers and safe context;
- events do not replace persistent audit records;
- consumers may use events for notifications or integrations.

## 17. Configuration

Proposed configuration sections:

```php
return [
    'database' => [],
    'models' => [],
    'tables' => [],
    'columns' => [],
    'oracle' => [],
    'guards' => [
        'default' => null,
        'subjects' => [
            // App\Models\Admin::class => 'admin',
        ],
    ],
    'assignments' => [],
    'audit' => [],
    'cache' => [],
    'middleware' => [],
    'sensitive_fields' => [],
];
```

Configuration is publishable as:

```text
config/gatetrail.php
```

Database configuration must support:

- a named Laravel connection;
- an optional schema or table prefix;
- package-managed or DBA-managed provisioning mode;
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

It must also report assignments whose referenced definition guard no longer
matches the subject's currently resolved guard. It must not migrate, revoke, or
rewrite those assignments.

It should report when a default GateTrail middleware alias is already bound to
an unrelated middleware class. GateTrail must not silently replace that alias.

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
- document all bypass or extension points;
- provide a security reporting process before public release.

## 20. Testing requirements

### 20.1 Unit tests

- input normalization;
- assignment time rules;
- direct permission resolution;
- role permission resolution;
- multiple-source resolution;
- guard matching;
- subject guard precedence and normalization;
- exception behavior;
- cache-key generation;
- time abstraction;
- canonical assignment scope keys and hashes;
- half-open assignment-period overlap rules;
- public input resolution rules;
- assignment-period value objects;
- mutation outcomes.

### 20.2 Integration tests

- migrations;
- Eloquent relationships;
- manager contract container bindings;
- direct public mutation methods;
- public-argument to internal-command translation;
- mutation transactions;
- assignment coordination-row locking;
- assignment scheduling with overlapping and adjacent periods;
- audit persistence;
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
- invalid authoritative guard without silent fallback;
- global authentication state not affecting subject guard resolution;
- middleware authentication and authorization exception behavior;
- unrelated Laravel Gates not satisfying GateTrail middleware;
- middleware denial responses not disclosing missing definitions;
- duplicate concurrent assignment;
- coordination hash mismatch;
- bounded concurrency retry exhaustion;
- strict audit rollback;
- unsafe input handling.

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
3. minimum audit snapshot content;
4. package behavior when direct database mutation is detected;
5. whether role convenience directives belong in the core package.

Persisted actor representation is accepted in
[ADR 0005](docs/decisions/0005-actor-identity-representation.md).

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

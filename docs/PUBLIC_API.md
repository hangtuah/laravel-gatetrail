# GateTrail Pre-v1 Public API

## 1. Status and purpose

This document is the authoritative consumer-facing inventory for GateTrail's
implemented pre-v1 API. It covers the Tier 1 supported API and Tier 2 extension
surface accepted in
[ADR 0014](decisions/0014-pre-v1-public-api-boundary.md).

This documented pre-v1 API is **frozen**. Before <code>1.0.0</code>, a deliberate
breaking change still requires an ADR, a changelog entry and migration guidance.
Future phases may add newly classified API. Accidental drift is a defect and is
rejected by <code>PublicApiSurfaceTest</code>.

PHP visibility does not define this boundary. A technically callable symbol is
supported only when it appears here. Constructors and factories marked
<code>@internal</code> remain package-controlled even when PHP requires them to
be public.

Namespace prefix <code>HangTuah\GateTrail\</code> is omitted from signature
examples only. The manifests below always use complete names.

## 2. Authoritative symbol manifests

### 2.1 Tier 1 — supported public API

Consumers call, receive, catch or listen to these 46 symbols.

<!-- gatetrail-tier-1:start -->
- <code>HangTuah\GateTrail\Contracts\AssignmentManager</code>
- <code>HangTuah\GateTrail\Contracts\RoleManager</code>
- <code>HangTuah\GateTrail\Contracts\PermissionManager</code>
- <code>HangTuah\GateTrail\Contracts\PermissionResolver</code>
- <code>HangTuah\GateTrail\Contracts\RoleResolver</code>
- <code>HangTuah\GateTrail\Contracts\GateTrailEvent</code>
- <code>HangTuah\GateTrail\AssignmentPeriod</code>
- <code>HangTuah\GateTrail\AssignmentReference</code>
- <code>HangTuah\GateTrail\RoleReference</code>
- <code>HangTuah\GateTrail\PermissionReference</code>
- <code>HangTuah\GateTrail\RoleChanges</code>
- <code>HangTuah\GateTrail\PermissionChanges</code>
- <code>HangTuah\GateTrail\MutationResult</code>
- <code>HangTuah\GateTrail\MutationOutcome</code>
- <code>HangTuah\GateTrail\SystemActor</code>
- <code>HangTuah\GateTrail\Access\EffectivePermission</code>
- <code>HangTuah\GateTrail\Access\EffectiveRole</code>
- <code>HangTuah\GateTrail\Events\MutationCommitted</code>
- <code>HangTuah\GateTrail\Events\AuditPersistenceFailed</code>
- <code>HangTuah\GateTrail\Exceptions\AssignmentNotActive</code>
- <code>HangTuah\GateTrail\Exceptions\AssignmentScopeCollision</code>
- <code>HangTuah\GateTrail\Exceptions\AuditWriteFailed</code>
- <code>HangTuah\GateTrail\Exceptions\ConcurrencyRetryExhausted</code>
- <code>HangTuah\GateTrail\Exceptions\DuplicateActiveAssignment</code>
- <code>HangTuah\GateTrail\Exceptions\DuplicatePermission</code>
- <code>HangTuah\GateTrail\Exceptions\DuplicateRole</code>
- <code>HangTuah\GateTrail\Exceptions\GuardMismatch</code>
- <code>HangTuah\GateTrail\Exceptions\InvalidActor</code>
- <code>HangTuah\GateTrail\Exceptions\InvalidAssignmentPeriod</code>
- <code>HangTuah\GateTrail\Exceptions\InvalidAuditConfiguration</code>
- <code>HangTuah\GateTrail\Exceptions\InvalidAuditMetadata</code>
- <code>HangTuah\GateTrail\Exceptions\InvalidConcurrencyConfiguration</code>
- <code>HangTuah\GateTrail\Exceptions\InvalidGuard</code>
- <code>HangTuah\GateTrail\Exceptions\InvalidModelConfiguration</code>
- <code>HangTuah\GateTrail\Exceptions\InvalidSubject</code>
- <code>HangTuah\GateTrail\Exceptions\InvalidMiddlewareParameter</code>
- <code>HangTuah\GateTrail\Exceptions\PermissionNotFound</code>
- <code>HangTuah\GateTrail\Exceptions\ReasonRequired</code>
- <code>HangTuah\GateTrail\Exceptions\RoleNotFound</code>
- <code>HangTuah\GateTrail\Exceptions\UnsupportedAfterCommitBoundary</code>
- <code>HangTuah\GateTrail\Laravel\Middleware\RequirePermission</code>
- <code>HangTuah\GateTrail\Laravel\Middleware\RequireAnyPermission</code>
- <code>HangTuah\GateTrail\Laravel\Middleware\RequireAllPermissions</code>
- <code>HangTuah\GateTrail\Laravel\Middleware\RequireRole</code>
- <code>HangTuah\GateTrail\Laravel\Middleware\RequireAnyRole</code>
- <code>HangTuah\GateTrail\Laravel\Middleware\RequireAllRoles</code>
<!-- gatetrail-tier-1:end -->

### 2.2 Tier 2 — consumer extension points

Consumers implement, extend, configure or inherit these 13 symbols. Their
documented requirements are compatibility surface.

<!-- gatetrail-tier-2:start -->
- <code>HangTuah\GateTrail\Contracts\RoleContract</code>
- <code>HangTuah\GateTrail\Contracts\PermissionContract</code>
- <code>HangTuah\GateTrail\Contracts\RoleAssignmentContract</code>
- <code>HangTuah\GateTrail\Contracts\PermissionAssignmentContract</code>
- <code>HangTuah\GateTrail\Contracts\RolePermissionContract</code>
- <code>HangTuah\GateTrail\Models\Role</code>
- <code>HangTuah\GateTrail\Models\Permission</code>
- <code>HangTuah\GateTrail\Models\RoleAssignment</code>
- <code>HangTuah\GateTrail\Models\PermissionAssignment</code>
- <code>HangTuah\GateTrail\Models\RolePermission</code>
- <code>HangTuah\GateTrail\Models\AccessAudit</code>
- <code>HangTuah\GateTrail\Concerns\HasGateTrail</code>
- <code>HangTuah\GateTrail\Models\Concerns\HasAssignmentPeriodScopes</code>
<!-- gatetrail-tier-2:end -->

## 3. Tier 1 service contracts

Parameter names are compatibility surface because GateTrail documents named
arguments. In the signatures below, <code>array $metadata</code> means
<code>array&lt;string, mixed&gt;</code>.

### 3.1 AssignmentManager

The contract is a singleton and exposes exactly six methods:

~~~php
assignRole(
    Model $subject,
    RoleContract|RoleReference|string|int $role,
    Model|SystemActor $actor,
    string $reason,
    ?AssignmentPeriod $period = null,
    array $metadata = [],
): MutationResult // MutationResult<Model&RoleAssignmentContract>

revokeRole(
    RoleAssignmentContract|AssignmentReference|string|int $assignment,
    Model|SystemActor $actor,
    string $reason,
    array $metadata = [],
): MutationResult // MutationResult<Models\RoleAssignment>

grantDirectPermission(
    Model $subject,
    PermissionContract|PermissionReference|string|int $permission,
    Model|SystemActor $actor,
    string $reason,
    ?AssignmentPeriod $period = null,
    array $metadata = [],
): MutationResult // MutationResult<Model&PermissionAssignmentContract>

revokeDirectPermission(
    PermissionAssignmentContract|AssignmentReference|string|int $assignment,
    Model|SystemActor $actor,
    string $reason,
    array $metadata = [],
): MutationResult // MutationResult<Models\PermissionAssignment>

grantPermissionToRole(
    RoleContract|RoleReference|string|int $role,
    PermissionContract|PermissionReference|string|int $permission,
    Model|SystemActor $actor,
    string $reason,
    ?AssignmentPeriod $period = null,
    array $metadata = [],
): MutationResult // MutationResult<Model&RolePermissionContract>

revokePermissionFromRole(
    RolePermissionContract|AssignmentReference|string|int $assignment,
    Model|SystemActor $actor,
    string $reason,
    array $metadata = [],
): MutationResult // MutationResult<Models\RolePermission>
~~~

Assignment reasons are required and non-nullable.

### 3.2 RoleManager

The contract is a singleton and exposes exactly four methods:

~~~php
create(
    string $name,
    string $guard,
    Model|SystemActor $actor,
    ?string $reason = null,
    ?string $description = null,
    array $metadata = [],
): MutationResult // MutationResult<Model&RoleContract>

update(
    RoleContract|RoleReference|string|int $role,
    RoleChanges $changes,
    Model|SystemActor $actor,
    ?string $reason = null,
    array $metadata = [],
): MutationResult // MutationResult<Model&RoleContract>

deactivate(
    RoleContract|RoleReference|string|int $role,
    Model|SystemActor $actor,
    ?string $reason = null,
    array $metadata = [],
): MutationResult // MutationResult<Model&RoleContract>

restore(
    RoleContract|RoleReference|string|int $role,
    Model|SystemActor $actor,
    ?string $reason = null,
    array $metadata = [],
): MutationResult // MutationResult<Model&RoleContract>
~~~

### 3.3 PermissionManager

The contract is a singleton and mirrors the role manager:

~~~php
create(
    string $name,
    string $guard,
    Model|SystemActor $actor,
    ?string $reason = null,
    ?string $description = null,
    array $metadata = [],
): MutationResult // MutationResult<Model&PermissionContract>

update(
    PermissionContract|PermissionReference|string|int $permission,
    PermissionChanges $changes,
    Model|SystemActor $actor,
    ?string $reason = null,
    array $metadata = [],
): MutationResult // MutationResult<Model&PermissionContract>

deactivate(
    PermissionContract|PermissionReference|string|int $permission,
    Model|SystemActor $actor,
    ?string $reason = null,
    array $metadata = [],
): MutationResult // MutationResult<Model&PermissionContract>

restore(
    PermissionContract|PermissionReference|string|int $permission,
    Model|SystemActor $actor,
    ?string $reason = null,
    array $metadata = [],
): MutationResult // MutationResult<Model&PermissionContract>
~~~

Definition reasons are nullable and controlled by
<code>gatetrail.definitions.require_reason</code>. Role and permission names and
guards are immutable; only descriptions can be updated.

### 3.4 Effective-access resolvers

Both resolver contracts are scoped. Repeated resolution inside one container
scope returns one adapter and one effective-access state; a new scope receives
new instances.

~~~php
PermissionResolver::allows(
    Model $subject,
    PermissionContract|PermissionReference|string|int $permission,
): bool

PermissionResolver::effectivePermissions(
    Model $subject,
): Collection // Collection<int, EffectivePermission>

RoleResolver::has(
    Model $subject,
    RoleContract|RoleReference|string|int $role,
): bool

RoleResolver::effectiveRoles(
    Model $subject,
): Collection // Collection<int, EffectiveRole>
~~~

The singular check and collection method are projections of the same evaluated
state. Output value objects are deliberately not accepted as check inputs.

### 3.5 Route middleware

GateTrail registers six stable aliases automatically. Their concrete classes
are also supported for consumers that configure middleware by class name:

| Alias | Class | Parameters |
|---|---|---|
| <code>gatetrail.permission</code> | <code>RequirePermission</code> | exactly one permission name |
| <code>gatetrail.permission.any</code> | <code>RequireAnyPermission</code> | one or more permission names |
| <code>gatetrail.permission.all</code> | <code>RequireAllPermissions</code> | one or more permission names |
| <code>gatetrail.role</code> | <code>RequireRole</code> | exactly one role name |
| <code>gatetrail.role.any</code> | <code>RequireAnyRole</code> | one or more role names |
| <code>gatetrail.role.all</code> | <code>RequireAllRoles</code> | one or more role names |

Each class exposes the standard Laravel middleware method:

~~~php
handle(Request $request, Closure $next, string ...$names): mixed
~~~

Names are trimmed, compared exactly and deduplicated. Invalid cardinality or
empty values raise <code>InvalidMiddlewareParameter</code>. An unauthenticated
request raises Laravel's <code>AuthenticationException</code>; a failed check
raises Laravel's <code>AuthorizationException</code>.

### 3.6 GateTrailEvent

<code>GateTrailEvent</code> is not container-bound. Register listeners for the
interface through Laravel's event dispatcher:

~~~php
public function context(): array; // array<string, mixed>
~~~

The keys are event-specific. A generic listener may always call
<code>context()</code>. It must narrow with <code>instanceof</code> before
reading fields such as <code>$mutation</code>.

## 4. Tier 1 value types

All value types below are final and readonly.

| Type | Supported construction and readers |
|---|---|
| <code>AssignmentPeriod</code> | Private constructor; <code>immediate(): self</code>, <code>until(DateTimeInterface $expiresAt): self</code>, <code>between(DateTimeInterface $startsAt, ?DateTimeInterface $expiresAt = null): self</code>; <code>startsAt(): ?DateTimeImmutable</code>, <code>expiresAt(): ?DateTimeImmutable</code>, <code>isImmediate(): bool</code> |
| <code>AssignmentReference</code> | Private constructor; <code>id(int|string $id): self</code>; readable <code>int|string $id</code> |
| <code>RoleReference</code> | Private constructor; <code>id(int|string $id): self</code>; readable <code>int|string $id</code> |
| <code>PermissionReference</code> | Private constructor; <code>id(int|string $id): self</code>; readable <code>int|string $id</code> |
| <code>RoleChanges</code> | Private constructor; <code>description(?string $description): self</code>; readable <code>?string $description</code>; null clears and an empty string preserves |
| <code>PermissionChanges</code> | Same surface and semantics as <code>RoleChanges</code> |
| <code>MutationResult&lt;TResource of Model&gt;</code> | Package-controlled public constructor; readable <code>Model $resource</code>, <code>MutationOutcome $outcome</code>, <code>int|string|null $auditId</code>; <code>changed(): bool</code> |
| <code>SystemActor</code> | Private constructor; <code>named(string $name): self</code>; readable <code>string $name</code>; <code>TYPE = 'gatetrail.system'</code> |

<code>MutationOutcome</code> is a string-backed enum. Case names and values are
both compatibility surface:

| Case | Value |
|---|---|
| <code>Created</code> | <code>created</code> |
| <code>Updated</code> | <code>updated</code> |
| <code>Deactivated</code> | <code>deactivated</code> |
| <code>Restored</code> | <code>restored</code> |
| <code>Revoked</code> | <code>revoked</code> |
| <code>Unchanged</code> | <code>unchanged</code> |

## 5. Effective-access output values

<code>EffectivePermission</code> and <code>EffectiveRole</code> have private
constructors and package-controlled factories. Consumers receive them from the
resolver collections and read exactly:

- <code>int|string $id</code>;
- <code>string $name</code>;
- <code>string $guardName</code>.

A consumer-created lookalike is not authorization evidence.

## 6. Events

Both events are final, readonly, package-constructed and implement
<code>GateTrailEvent</code>.

### 6.1 MutationCommitted

Readable fields:

~~~text
string $mutation
MutationOutcome $outcome
string $targetType
int|string $targetId
?string $subjectType
int|string|null $subjectId
string $actorType
string $actorId
int|string|null $auditId
DateTimeImmutable $occurredAt
?string $requestId
~~~

Its 14 mutation values are:

~~~text
role.assigned
role.revoked
role.created
role.updated
role.deactivated
role.restored
permission.granted_to_subject
permission.revoked_from_subject
permission.granted_to_role
permission.revoked_from_role
permission.created
permission.updated
permission.deactivated
permission.restored
~~~

<code>context()</code> returns mutation, outcome, target pair, subject pair,
actor pair, audit ID and UTC occurrence time, followed by request ID only when
captured.

### 6.2 AuditPersistenceFailed

Readable fields:

~~~text
string $event
string $targetType
int|string $targetId
?string $subjectType
int|string|null $subjectId
?string $requestId
string $exceptionClass
~~~

Its context contains the event and safe identity pairs plus the underlying
exception class. Neither event exposes models, snapshots, reasons, metadata,
credentials, exception messages or traces.

## 7. Tier 1 exceptions

Consumers catch these types; their named factories and constructors are
package-controlled and are not consumer construction API.

| Exception | Parent | Readable fields |
|---|---|---|
| <code>AssignmentNotActive</code> | <code>RuntimeException</code> | <code>string $assignmentKind</code>, <code>string $referenceHash</code> |
| <code>AssignmentScopeCollision</code> | <code>RuntimeException</code> | None |
| <code>AuditWriteFailed</code> | <code>RuntimeException</code> | None |
| <code>ConcurrencyRetryExhausted</code> | <code>RuntimeException</code> | <code>string $operation</code>, <code>int $attempts</code>, <code>bool $callerOwned</code>, <code>string $driver</code>, <code>string $category</code>, <code>?string $sqlState</code>, <code>?int $vendorCode</code>; <code>getPrevious()</code> is always null |
| <code>DuplicateActiveAssignment</code> | <code>RuntimeException</code> | <code>Assignments\AssignmentKind $assignmentKind</code>, <code>string $scopeHash</code>; the enum type itself remains internal |
| <code>DuplicatePermission</code> | <code>RuntimeException</code> | <code>string $namespaceHash</code> |
| <code>DuplicateRole</code> | <code>RuntimeException</code> | <code>string $namespaceHash</code> |
| <code>GuardMismatch</code> | <code>RuntimeException</code> | <code>string $subjectGuard</code>, <code>string $targetGuard</code>, <code>string $targetType</code>, <code>int|string|null $targetId</code> |
| <code>PermissionNotFound</code> | <code>RuntimeException</code> | <code>?string $guard</code>, <code>string $referenceType</code> |
| <code>RoleNotFound</code> | <code>RuntimeException</code> | <code>?string $guard</code>, <code>string $referenceType</code> |
| <code>UnsupportedAfterCommitBoundary</code> | <code>RuntimeException</code> | <code>?string $connection</code> |
| <code>InvalidActor</code> | <code>InvalidArgumentException</code> | None |
| <code>InvalidAssignmentPeriod</code> | <code>InvalidArgumentException</code> | None |
| <code>InvalidAuditConfiguration</code> | <code>InvalidArgumentException</code> | <code>string $key</code> |
| <code>InvalidAuditMetadata</code> | <code>InvalidArgumentException</code> | None |
| <code>InvalidConcurrencyConfiguration</code> | <code>InvalidArgumentException</code> | <code>string $key</code> |
| <code>InvalidGuard</code> | <code>InvalidArgumentException</code> | <code>string $contextType</code>, <code>string $source</code> |
| <code>InvalidModelConfiguration</code> | <code>InvalidArgumentException</code> | None |
| <code>InvalidSubject</code> | <code>InvalidArgumentException</code> | <code>string $subjectType</code>, <code>string $reason</code>; never the raw identifier |
| <code>InvalidMiddlewareParameter</code> | <code>InvalidArgumentException</code> | <code>string $alias</code>, <code>string $reason</code>; never permission or role names |
| <code>ReasonRequired</code> | <code>InvalidArgumentException</code> | None |

The five operational exceptions in <code>PACKAGE_SPEC.md</code> section 15.2
are intentionally outside Tier 1 and use a schema-release stability clock.

## 8. Tier 2 model and trait surface

### 8.1 Empty marker contracts

<code>RoleContract</code>, <code>PermissionContract</code>,
<code>RoleAssignmentContract</code>, <code>PermissionAssignmentContract</code>
and <code>RolePermissionContract</code> declare zero methods. Adding a method is
a breaking change for consumer models.

### 8.2 Configurable models

| Model | Required public relations | Fillable and casts |
|---|---|---|
| <code>Models\Role</code> | <code>subjectAssignments(): HasMany</code>, <code>permissionAssignments(): HasMany</code> | Fillable: name, guard_name, description. Immutable datetime casts: created_at, updated_at, deleted_at |
| <code>Models\Permission</code> | <code>subjectAssignments(): HasMany</code>, <code>roleAssignments(): HasMany</code> | Fillable: name, guard_name, description. Immutable datetime casts: created_at, updated_at, deleted_at |
| <code>Models\RoleAssignment</code> | <code>subject(): MorphTo</code>, <code>role(): BelongsTo</code> | Fillable: subject_type, subject_id, role_id, assigned_at, assigned_by_type, assigned_by_id, assignment_reason, starts_at, expires_at, revoked_at, revoked_by_type, revoked_by_id, revocation_reason. Immutable datetime casts: assigned_at, starts_at, expires_at, revoked_at, created_at, updated_at |
| <code>Models\PermissionAssignment</code> | <code>subject(): MorphTo</code>, <code>permission(): BelongsTo</code> | Fillable: subject_type, subject_id, permission_id, assigned_at, assigned_by_type, assigned_by_id, assignment_reason, starts_at, expires_at, revoked_at, revoked_by_type, revoked_by_id, revocation_reason. Immutable datetime casts: assigned_at, starts_at, expires_at, revoked_at, created_at, updated_at |
| <code>Models\RolePermission</code> | <code>role(): BelongsTo</code>, <code>permission(): BelongsTo</code> | Fillable: role_id, permission_id, assigned_at, assigned_by_type, assigned_by_id, assignment_reason, starts_at, expires_at, revoked_at, revoked_by_type, revoked_by_id, revocation_reason. Immutable datetime casts: assigned_at, starts_at, expires_at, revoked_at, created_at, updated_at |
| <code>Models\AccessAudit</code> | <code>subject(): MorphTo</code>; <code>UPDATED_AT = null</code> | Fillable: event, actor_type, actor_id, subject_type, subject_id, target_type, target_id, before, after, reason, context, ip_address, user_agent, request_id. Array casts: before, after, context. Immutable datetime cast: created_at. Persisted instances reject Eloquent update, delete, increment, decrement and quiet-operation variants |

Custom Role and Permission models may independently extend Eloquent
<code>Model</code> when they implement the corresponding marker and use
<code>SoftDeletes</code>. Custom assignment models must extend the matching
GateTrail assignment base. A custom access-audit model must extend
<code>Models\AccessAudit</code> and inherits its append-only instance behavior.
Direct database administration and query-builder writes are outside this model
boundary and remain the consumer's operational responsibility.

All six supported models inherit the configured GateTrail connection and table
resolution. The seven valid table keys are roles, permissions,
role_assignments, permission_assignments, role_permissions, assignment_locks
and access_audits.

### 8.3 Assignment lifecycle scopes

All three assignment models inherit exactly four Tier 2 scopes:

~~~php
scopeActiveAt(Builder $query, DateTimeInterface $time): Builder
scopeScheduledAt(Builder $query, DateTimeInterface $time): Builder
scopeExpiredAt(Builder $query, DateTimeInterface $time): Builder
scopeRevoked(Builder $query): Builder
~~~

<code>activeAt()</code> uses the half-open period:

~~~text
starts_at <= time, or starts_at is null and assigned_at <= time
expires_at is null or expires_at > time
revoked_at is null
~~~

The effective-access resolver calls this scope on configured models. Overriding
it changes authorization semantics and may widen access.

### 8.4 Subject guard trait

<code>Concerns\HasGateTrail</code> is a supported consumer trait that lets the
subject guard resolver inspect a protected
<code>string $gateTrailGuardName</code> property. It exposes these supported
methods:

~~~php
hasRole(RoleContract|RoleReference|string|int $role): bool
hasAnyRole(array $roles): bool
hasAllRoles(array $roles): bool
hasPermissionTo(PermissionContract|PermissionReference|string|int $permission): bool
effectivePermissions(): Collection
roles(): Collection
directPermissions(): MorphToMany
roleAssignments(): MorphMany
permissionAssignments(): MorphMany
~~~

The batch role arrays accept the same role-input union as `hasRole()`, evaluate
one snapshot and deny when empty. `roles()` and `effectivePermissions()` return
the effective resolver value objects. The three Eloquent relations are raw
stored-state views: assignment relations include history and
`directPermissions()` does not apply assignment lifecycle. They are not
authorization evidence and expose no mutation method.

The trait's guard-property inspection helper is final, public and
<code>@internal</code>; consumers do not call it.

A subject may instead expose <code>gateTrailGuardName(): string</code> directly
without using the trait.

## 9. ClockInterface testing seam

GateTrail binds <code>Psr\Clock\ClockInterface</code> to its internal system
clock. Rebinding the interface is a supported testing seam:

~~~php
$app->instance(ClockInterface::class, $testClock);
~~~

The binding controls the timestamp captured by all 14 mutation command
factories and the single timestamp used for one effective-access evaluation.
Production code must continue resolving this clock from the container. The
internal <code>SystemClock</code> class is not public API.

## 10. Standard InvalidArgumentException behavior

These six Tier 1 validation sites deliberately raise PHP's
<code>InvalidArgumentException</code>, not a GateTrail-specific subtype:

| Site | Exact rejected input |
|---|---|
| <code>AssignmentReference::id()</code> | Empty string or string longer than 191 characters |
| <code>RoleReference::id()</code> | Empty string or string longer than 191 characters |
| <code>PermissionReference::id()</code> | Empty string or string longer than 191 characters |
| <code>RoleChanges::description()</code> | Non-UTF-8 string |
| <code>PermissionChanges::description()</code> | Non-UTF-8 string |
| <code>MutationResult::__construct()</code> | <code>MutationOutcome::Unchanged</code> with a non-null audit ID |

This throw behavior is compatibility surface even though
<code>InvalidArgumentException</code> is not a GateTrail symbol.

## 11. Operational command surface

Four GateTrail console commands are available operational surface:

- <code>gatetrail:install</code> publishes configuration without overwriting an
  existing file and runs only GateTrail migrations in package-managed mode.
  <code>--no-migrate</code> suppresses schema changes, while <code>--force</code>
  permits the migration step in production. DBA-managed mode never runs package
  migrations.
- <code>gatetrail:ddl</code> writes <code>install.sql</code> and
  <code>rollback.sql</code> from resolved configuration. It requires
  <code>--output</code>, optionally accepts <code>--driver</code>, refuses
  overwrite without <code>--force</code>, and never connects to a database.
- <code>gatetrail:doctor</code> performs the documented read-only consistency
  diagnostics in human or <code>--json</code> form.
- <code>gatetrail:matrix</code> reports runtime, driver readiness and evidence
  boundaries in human or <code>--json</code> form without connecting to a
  database.

These command names, options and safety rules are operational surface. Their PHP
command classes are marked <code>@internal</code>; consumers invoke the Artisan
names rather than resolve, extend or instantiate those classes.

## 12. Excluded and unavailable surface

The following are not currently available consumer APIs:

- internal commands, handlers, query components, repositories, adapters,
  schema compilers, inspectors, diagnostics and <code>ModelRegistry</code>;
- default manager and resolver concrete classes—depend on their contracts;
- <code>UnauthorizedMutation</code>;
- GateTrail-supplied policies and custom Blade directives;
- the remaining proposed GateTrail console commands;
- cross-request cache and invalidation APIs.

Future phases may add these deliberately, but must classify the resulting
consumer-facing symbols before claiming completion.

## 13. Related decisions

- [ADR 0002](decisions/0002-public-mutation-api.md) — simple manager API over
  internal commands.
- [ADR 0010](decisions/0010-after-commit-mutation-events.md) — event ordering
  and safe payloads.
- [ADR 0011](decisions/0011-bounded-concurrency-retry.md) — concurrency failure
  contract.
- [ADR 0012](decisions/0012-effective-access-resolution.md) — resolver
  contracts and output values.
- [ADR 0014](decisions/0014-pre-v1-public-api-boundary.md) — tier definitions
  and pre-v1 change policy.

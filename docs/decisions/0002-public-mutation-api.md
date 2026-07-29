# ADR 0002: Expose Simple Manager Methods With Internal Commands

- Status: Accepted
- Date: 2026-07-28
- Decision owners: GateTrail maintainers

## Context

GateTrail must provide a safe API for creating and maintaining roles,
permissions, and history-preserving assignments.

Every managed mutation may involve validation, an explicit actor and reason, a
transaction, assignment coordination, audit persistence, cache invalidation,
and an after-commit event.

An earlier proposal exposed an immutable command object for every operation.
That design kept manager interfaces short, but required consumers to construct
several objects for a common assignment:

```php
$assignments->assignRole(
    new AssignRole(
        subject: $user,
        role: RoleReference::named('reviewer'),
        context: MutationContext::forActor(
            actor: $admin,
            reason: 'Assigned by administrator',
        ),
    ),
);
```

This placed GateTrail's orchestration structure in consumer code and made the
most common use case unnecessarily verbose.

## Decision

GateTrail will expose direct, typed manager methods with named parameters.
Internal implementations may translate those parameters into immutable command
objects and send them to single-use-case handlers.

The governing principle is:

```text
Simple public API
→ Thin manager adapter
→ Internal immutable command
→ Use-case handler
→ Domain and infrastructure services
```

Internal commands and handlers are not public API.

## Public manager contracts

GateTrail exposes three injectable contracts:

```text
HangTuah\GateTrail\Contracts\RoleManager
HangTuah\GateTrail\Contracts\PermissionManager
HangTuah\GateTrail\Contracts\AssignmentManager
```

The service provider binds each contract to its default implementation through
Laravel's service container.

### Role manager

```php
interface RoleManager
{
    public function create(
        string $name,
        string $guard,
        Model|SystemActor $actor,
        ?string $reason = null,
        ?string $description = null,
        array $metadata = [],
    ): MutationResult;

    public function update(
        RoleContract|RoleReference|string|int $role,
        RoleChanges $changes,
        Model|SystemActor $actor,
        ?string $reason = null,
        array $metadata = [],
    ): MutationResult;

    public function deactivate(
        RoleContract|RoleReference|string|int $role,
        Model|SystemActor $actor,
        ?string $reason = null,
        array $metadata = [],
    ): MutationResult;

    public function restore(
        RoleContract|RoleReference|string|int $role,
        Model|SystemActor $actor,
        ?string $reason = null,
        array $metadata = [],
    ): MutationResult;
}
```

`RoleChanges` is used only for update operations, where “not supplied” must
remain different from “set this nullable field to `null`”.

In the initial public API, `RoleChanges` carries `description` only. Role name
and guard are immutable. A changes object must explicitly carry a description
change; applying the currently persisted value returns `Unchanged`.

### Permission manager

```php
interface PermissionManager
{
    public function create(
        string $name,
        string $guard,
        Model|SystemActor $actor,
        ?string $reason = null,
        ?string $description = null,
        array $metadata = [],
    ): MutationResult;

    public function update(
        PermissionContract|PermissionReference|string|int $permission,
        PermissionChanges $changes,
        Model|SystemActor $actor,
        ?string $reason = null,
        array $metadata = [],
    ): MutationResult;

    public function deactivate(
        PermissionContract|PermissionReference|string|int $permission,
        Model|SystemActor $actor,
        ?string $reason = null,
        array $metadata = [],
    ): MutationResult;

    public function restore(
        PermissionContract|PermissionReference|string|int $permission,
        Model|SystemActor $actor,
        ?string $reason = null,
        array $metadata = [],
    ): MutationResult;
}
```

In the initial public API, `PermissionChanges` carries `description` only and
uses the same omitted-versus-explicit-null semantics as `RoleChanges`.
Permission name and guard are immutable. Applying the currently persisted
description returns `Unchanged`.

Creating a role or permission uses the full `(name, guard)` namespace,
including deactivated definitions. A collision throws the corresponding
duplicate exception; consumers use `restore()` rather than creating over a
deactivated definition.

### Assignment manager

```php
interface AssignmentManager
{
    public function assignRole(
        Model $subject,
        RoleContract|RoleReference|string|int $role,
        Model|SystemActor $actor,
        string $reason,
        ?AssignmentPeriod $period = null,
        array $metadata = [],
    ): MutationResult;

    public function revokeRole(
        RoleAssignmentContract|AssignmentReference|string|int $assignment,
        Model|SystemActor $actor,
        string $reason,
        array $metadata = [],
    ): MutationResult;

    public function grantDirectPermission(
        Model $subject,
        PermissionContract|PermissionReference|string|int $permission,
        Model|SystemActor $actor,
        string $reason,
        ?AssignmentPeriod $period = null,
        array $metadata = [],
    ): MutationResult;

    public function revokeDirectPermission(
        PermissionAssignmentContract|AssignmentReference|string|int $assignment,
        Model|SystemActor $actor,
        string $reason,
        array $metadata = [],
    ): MutationResult;

    public function grantPermissionToRole(
        RoleContract|RoleReference|string|int $role,
        PermissionContract|PermissionReference|string|int $permission,
        Model|SystemActor $actor,
        string $reason,
        ?AssignmentPeriod $period = null,
        array $metadata = [],
    ): MutationResult;

    public function revokePermissionFromRole(
        RolePermissionContract|AssignmentReference|string|int $assignment,
        Model|SystemActor $actor,
        string $reason,
        array $metadata = [],
    ): MutationResult;
}
```

The longer direct-permission method names distinguish a permission granted
directly to a subject from a permission included in a role.

## Common consumer usage

An ordinary role assignment requires no command, context, reference, or period
object:

```php
$result = $assignments->assignRole(
    subject: $user,
    role: 'reviewer',
    actor: $admin,
    reason: 'Assigned by administrator',
);
```

A scheduled assignment adds one purpose-specific value object:

```php
$result = $assignments->assignRole(
    subject: $user,
    role: 'temporary-reviewer',
    actor: $admin,
    reason: 'Covering staff leave',
    period: AssignmentPeriod::between(
        startsAt: now(),
        expiresAt: now()->addMonth(),
    ),
);
```

Revocation targets one unambiguous assignment:

```php
$result = $assignments->revokeRole(
    assignment: $assignment,
    actor: $admin,
    reason: 'Transferred to another unit',
);
```

## Input resolution rules

Role and permission inputs follow fixed rules:

| Input | Interpretation |
|---|---|
| Resource model contract | Use that model |
| `string` | Resolve by name |
| `int` | Resolve by primary key |
| Explicit reference | Resolve according to the reference type |

GateTrail must not guess whether a numeric-looking string is an ID. For example,
`'123'` is a name, while `123` is an integer ID.

Applications with UUID, ULID, string, or otherwise ambiguous identifiers use:

```php
RoleReference::id($uuid);
PermissionReference::id($uuid);
```

Assignment inputs are assignment models, explicit assignment references, or
their IDs. Assignment names do not exist, so a string assignment input is
treated as an ID.

A string definition input resolves by name in GateTrail's configured default
guard, falling back to Laravel's default guard when GateTrail has none. This
resolution never inspects runtime authentication state, the current
authenticated user, or the active request guard.

Model, integer-ID, and explicit-reference inputs resolve the identified
definition directly and retain its persisted guard. Consumers therefore use a
model, an integer ID, or `RoleReference::id()` to mutate a definition that
lives outside the default guard.

Numeric-looking strings remain names under these rules.

References are resolved inside the mutation transaction.

## Actor and metadata

A human actor is an Eloquent model. An automated process uses an explicit
`SystemActor`:

```php
$assignments->assignRole(
    subject: $user,
    role: 'reviewer',
    actor: SystemActor::named('staff-sync'),
    reason: 'Synchronized from staff system',
);
```

Rules:

- an actor is always explicit;
- GateTrail never silently substitutes `Auth::user()`;
- actor attribution does not authorize the mutation;
- assignment and revocation reasons are required by default;
- configuration may require reasons for definition mutations;
- metadata is an optional string-keyed associative array that persists only
  under `context.metadata`;
- metadata must be JSON-safe and pass finite size, depth, and sensitive-field
  rules before the mutation transaction begins;
- invalid metadata throws `InvalidAuditMetadata` rather than being truncated,
  stringified, or silently discarded;
- request metadata is not copied blindly into audit records.

Exact metadata, audit snapshot, redaction, and failure-mode rules are defined by
[ADR 0008](0008-audit-snapshot-and-failure-modes.md).

## Assignment period

`AssignmentPeriod` is an immutable public value object used only when a caller
needs scheduling:

```php
final readonly class AssignmentPeriod
{
    public static function immediate(): self;

    public static function until(DateTimeInterface $expiresAt): self;

    public static function between(
        DateTimeInterface $startsAt,
        ?DateTimeInterface $expiresAt = null,
    ): self;
}
```

An omitted period means an immediate assignment without expiry. Time values are
normalized to GateTrail's configured precision and timezone before persistence.

Once persisted, an assignment period is immutable in the initial public API.
Changing a period requires revoking the existing assignment and creating a new
assignment. The package exposes no reschedule or assignment-update method.

## Mutation naming

Public mutation names follow one semantic rule:

```text
Revoke     → assignment
Deactivate → role or permission definition
Restore    → role or permission definition
```

Consequently:

- `revokeRole()` revokes a subject-role assignment;
- `revokeDirectPermission()` revokes a subject-permission assignment;
- `revokePermissionFromRole()` revokes a role-permission assignment;
- `deactivate()` on a definition manager suspends the role or permission
  globally without rewriting assignments;
- restoring a definition may make preserved, otherwise-active assignments
  effective again, but never restores a revoked assignment.

GateTrail will not expose names such as `deactivateAssignment()` or
`revokeRoleDefinition()`.

## Mutation result

Every successful manager call returns a public `MutationResult`:

```php
enum MutationOutcome: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deactivated = 'deactivated';
    case Restored = 'restored';
    case Revoked = 'revoked';
    case Unchanged = 'unchanged';
}

/**
 * @template TResource of Model
 */
final readonly class MutationResult
{
    /**
     * @param TResource $resource
     */
    public function __construct(
        public Model $resource,
        public MutationOutcome $outcome,
        public int|string|null $auditId,
    ) {}

    public function changed(): bool;
}
```

An idempotent assignment replay returns the existing assignment with
`MutationOutcome::Unchanged`. It does not create a second audit record, clear
cache, or dispatch a second mutation event because authorization state did not
change.

Duplicate role and permission creation remains an exception rather than an
unchanged result.

## Internal implementation

Default managers are thin adapters. They do not contain queries, transaction
orchestration, assignment coordination, audit persistence, cache invalidation,
or event dispatch.

Conceptually:

```php
final class DefaultAssignmentManager implements AssignmentManager
{
    public function assignRole(
        Model $subject,
        RoleContract|RoleReference|string|int $role,
        Model|SystemActor $actor,
        string $reason,
        ?AssignmentPeriod $period = null,
        array $metadata = [],
    ): MutationResult {
        return $this->handler->handle(
            new AssignRoleCommand(
                subject: $subject,
                role: $role,
                actor: $actor,
                reason: $reason,
                period: $period ?? AssignmentPeriod::immediate(),
                metadata: $metadata,
            ),
        );
    }
}
```

`AssignRoleCommand` and `AssignRoleHandler` live under an internal namespace and
are marked `@internal`. Consumers must not resolve or call them directly.

An internal command:

- is immutable;
- carries normalized mutation input between internal collaborators;
- permits safe bounded transaction retries;
- prevents long repeated argument lists inside the package;
- may change without a public breaking change.

One handler orchestrates one mutation use case. Shared domain and
infrastructure services provide validation, coordination locks, persistence,
auditing, cache invalidation, and events. Business logic must not be duplicated
in managers, facades, console commands, or Eloquent relationships.

## Transaction and side-effect contract

Each manager method owns the mutation boundary on GateTrail's configured
database connection. With strict auditing, mutation and audit persistence are
atomic:

```text
Validate input
→ Begin or join transaction
→ Resolve references
→ Acquire required coordination lock
→ Persist mutation
→ Persist audit
→ Commit
→ Invalidate affected cache
→ Dispatch after-commit event
→ Return result
```

If the caller already has a transaction on the same connection, GateTrail
participates in it. Cache invalidation and events wait for the outermost
successful commit where the connection supports after-commit callbacks.

With non-strict auditing, GateTrail commits or waits for the successful
outermost mutation commit before attempting audit persistence in a separate
transaction. When a manager joins a caller-owned transaction, it returns a null
audit identifier and registers the audit attempt as an outermost after-commit
callback. An outer rollback creates no audit. Unsupported after-commit
boundaries are rejected before mutation.

When auditing is disabled, no audit write is attempted. The exact strict,
non-strict, disabled, metadata, and result rules are defined by
[ADR 0008](0008-audit-snapshot-and-failure-modes.md).

A transaction on another connection cannot make GateTrail atomic with the
caller's work. GateTrail does not provide distributed transactions.

Concurrency retries are bounded, use the immutable internal command, and apply
only to classified transient database failures. Domain validation and
authorization exceptions are not retried. Assignment coordination follows ADR
0001.

## Exceptions

Manager methods return `MutationResult` on success and throw typed GateTrail
exceptions on failure. They do not return `false`, `null`, database error codes,
or partially populated results for failure.

Known database failures are translated into their GateTrail domain exception.
Unknown infrastructure errors retain the original exception as their previous
cause.

## Authorization responsibility

The `actor` argument records who caused a mutation. It is not proof that the
actor is allowed to perform it.

Consumer applications authorize controllers, jobs, console commands, or
application services before calling a manager:

```php
Gate::authorize('roles.assign');

$assignments->assignRole(
    subject: $user,
    role: 'reviewer',
    actor: $admin,
    reason: 'Assigned by administrator',
);
```

GateTrail does not infer mutation authorization from the current authenticated
user.

## Container and facade policy

Consumer code should constructor-inject manager contracts:

```php
final class AssignReviewer
{
    public function __construct(
        private AssignmentManager $assignments,
    ) {}
}
```

A facade is not required for the first vertical slice. A future facade must be a
thin convenience layer over the same contracts and must not introduce a second
mutation implementation.

Console commands and future UI integrations call the managers.

## Public stability boundary

The following are public API:

- manager contracts and direct method signatures;
- model contracts;
- `SystemActor`;
- `AssignmentPeriod`;
- update change objects;
- explicit reference objects;
- `MutationResult` and `MutationOutcome`;
- documented domain exceptions.

The following remain internal:

- immutable mutation commands;
- use-case handlers;
- concrete managers;
- repositories and query objects;
- database adapters;
- coordination-lock implementation;
- audit writers;
- cache invalidators;
- event orchestration.

Named parameter names are part of the practical compatibility surface. Renaming
them after `1.0.0` is treated as a breaking change.

## Alternatives considered

### Public command objects

Rejected as the primary API because common consumers had to construct command,
context, reference, and schedule objects for a simple assignment. Internal
commands retain the organizational advantages without exposing that ceremony.

### One manager with every mutation

Rejected because it combines role definitions, permission definitions, and
assignment lifecycle responsibilities into a large interface.

### Static model and relationship methods

Rejected because they encourage mutations that bypass audit, cache
invalidation, coordination locks, and after-commit events.

### Generic operation and array payload

Rejected because it weakens type safety, editor discovery, static analysis, and
safe API evolution.

### Facade-only API

Rejected because dependency-injected contracts are easier to replace and test.
A facade may later be added as an optional adapter.

## Consequences

### Positive

- Common consumer calls are short and readable.
- Consumers do not need to understand GateTrail's orchestration architecture.
- The public class count and compatibility surface are smaller.
- Managers remain easy to inject and mock.
- Internal commands preserve immutability and retry safety.
- Reference objects remain available for genuinely ambiguous identifiers.
- Mutation results expose no-op behavior without relying on Eloquent internals.

### Negative

- Manager method signatures are longer than command-based signatures.
- Named parameter names must remain stable after `1.0.0`.
- Union input resolution rules must remain explicit and well tested.
- Advanced scheduling still requires one purpose-specific object.
- Manager implementations require a thin translation layer.
- Generic result precision relies partly on PHPDoc static-analysis support.

## Specification alignment

`PACKAGE_SPEC.md` was updated when this ADR was accepted to:

- define the three manager contracts as the managed mutation API;
- make direct typed methods the primary consumer interface;
- keep mutation commands and handlers internal;
- define input resolution, period, actor, and result rules;
- document assignment-specific revocation and naming;
- align public stability and testing requirements;
- remove the mutation API item from open architecture decisions.

## Verification

Before this decision is considered implemented, tests must prove:

- all manager contracts resolve from Laravel's service container;
- common mutations require no public command or context object;
- manager adapters contain no independent domain logic;
- direct parameters produce the correct internal command;
- string, integer, model, and explicit-reference inputs resolve consistently;
- numeric-looking strings resolve as names, not IDs;
- assignment period normalization and validation are deterministic;
- persisted assignment periods expose no update or reschedule path;
- role and permission changes accept description only and preserve
  omitted-versus-null semantics;
- definition names and guards cannot be changed through `update()`;
- creation over a deactivated `(name, guard)` throws the corresponding
  duplicate exception;
- human and system actors are always explicit;
- reason rules are enforced;
- assignment replays return `Unchanged` without duplicate side effects;
- successful mutations return the correct resource and outcome;
- failures throw typed exceptions and never return partial success;
- nested same-connection transactions defer side effects until final commit;
- rollback suppresses cache invalidation and events;
- concurrency retries are bounded and safe;
- behavior is equivalent across all supported databases.

## References

- [Laravel: binding interfaces to implementations](https://laravel.com/docs/13.x/container#binding-interfaces-to-implementations)
- [Laravel: database transactions](https://laravel.com/docs/13.x/database#database-transactions)
- [Laravel: events and database transactions](https://laravel.com/docs/13.x/events#queued-event-listeners-and-database-transactions)
- [GateTrail ADR 0001: assignment mutation serialization](0001-unique-active-assignments.md)

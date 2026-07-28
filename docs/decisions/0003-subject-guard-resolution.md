# ADR 0003: Resolve One Stable Guard for Each Subject

- Status: Accepted
- Date: 2026-07-28
- Decision owners: GateTrail maintainers

## Context

GateTrail scopes role and permission definitions by `guard_name`. A subject may
receive only roles and direct permissions compatible with its resolved guard.

Laravel guards describe how users are authenticated for a request. GateTrail
uses a guard name as an authorization namespace. These concepts are related,
but GateTrail must not infer a subject's authorization namespace from whichever
user or authentication driver happens to be active globally.

Guard resolution must remain deterministic in:

- ordinary HTTP requests;
- `Gate::forUser($subject)`;
- queued jobs;
- console commands;
- tests;
- processes without an authenticated user.

The package specification already establishes an override and fallback order,
but the public method, property, configuration keys, validation behavior, and
multi-guard limits require an explicit decision.

## Decision

GateTrail will resolve exactly one stable guard name for a subject during an
authorization or mutation operation.

The optional subject override names are:

```php
public function gateTrailGuardName(): string;

protected string $gateTrailGuardName = 'admin';
```

If both are present, the method wins.

### Resolution order

The `SubjectGuardResolver` uses this exact order:

1. public subject method `gateTrailGuardName()`;
2. declared subject property `$gateTrailGuardName`;
3. exact subject-class entry in `gatetrail.guards.subjects`;
4. package default `gatetrail.guards.default`;
5. Laravel configuration value `auth.defaults.guard`.

The first defined source is authoritative. If that source returns an invalid
value, GateTrail throws `InvalidGuard` rather than silently continuing to the
next source.

### Method override

Example:

```php
use HangTuah\GateTrail\Concerns\HasGateTrail;

final class Admin extends Authenticatable
{
    use HasGateTrail;

    public function gateTrailGuardName(): string
    {
        return 'admin';
    }
}
```

The method:

- must be public;
- must accept no arguments;
- must return a non-empty string;
- must not depend on `Auth::user()`, the current request, or mutable global
  authentication state;
- should return the same value for the same subject model type throughout one
  process.

The method is suitable when guard choice follows application logic that cannot
be expressed through a fixed property or configuration entry.

### Property override

Example:

```php
use HangTuah\GateTrail\Concerns\HasGateTrail;

final class Admin extends Authenticatable
{
    use HasGateTrail;

    protected string $gateTrailGuardName = 'admin';
}
```

The property:

- must be declared on the model or an inherited class;
- may be `protected` or `public`;
- must contain a non-empty string;
- is read through the `HasGateTrail` concern rather than Eloquent's magic
  attribute access;
- is not a database column and is not mass assignable.

A private property is not supported because inheritance and trait access can
make its behavior surprising. A dynamic Eloquent attribute named
`gateTrailGuardName` is not treated as the override.

### Package configuration

The initial configuration shape is:

```php
'guards' => [
    'default' => null,

    'subjects' => [
        App\Models\Admin::class => 'admin',
        App\Models\Customer::class => 'customer',
    ],
],
```

Rules:

- subject-class matching is exact in the initial release;
- configuration order does not create inheritance precedence;
- subclasses requiring a different or inherited choice declare their own entry,
  property, or method;
- `default => null` means continue to Laravel's configured default guard;
- an explicitly configured empty string is invalid and does not trigger
  fallback.

Exact matching avoids ambiguous results when a model implements several
interfaces or inherits from a mapped base class.

### Laravel fallback

The final fallback reads:

```php
config('auth.defaults.guard')
```

GateTrail does not inspect:

- `Auth::user()`;
- the guard that authenticated another user;
- route middleware such as `auth:admin`;
- a runtime default changed through authentication-manager state;
- session or request state.

An application using an `Admin` model behind `auth:admin` must still declare the
GateTrail subject guard through its model or package configuration when the
Laravel configuration default is different.

### Guard normalization

Every resolved value is:

1. verified as a string;
2. trimmed of surrounding whitespace;
3. rejected when empty after trimming;
4. preserved exactly without lowercasing or uppercasing.

Guard comparison is exact after this normalization.

GateTrail does not require the value to be a key in `auth.guards`. This permits
an application to use a stable authorization namespace that is shared by
several authentication mechanisms. Applications should normally align names
with Laravel guards when that mapping is meaningful.

## Single-guard subject model

The initial release resolves one GateTrail guard per subject model instance and
operation.

If the same user model authenticates through both `web` and `api` but should
share one permission universe, it selects one stable GateTrail guard:

```php
protected string $gateTrailGuardName = 'web';
```

The result does not change merely because the current request used an API token.

Separate authorization universes for the same subject record are outside the
initial public API. GateTrail does not initially accept a per-call guard
override such as:

```php
// Not supported initially.
$user->hasPermissionTo('documents.approve', guard: 'api');
```

Applications that truly require separate universes should use distinct subject
model types or wait for an explicit multi-guard design. A future multi-guard
feature requires its own ADR because it affects assignments, cache keys,
resolution methods, and mutation signatures.

## Assignment behavior

Before assigning a role or direct permission, GateTrail resolves the subject
guard and compares it with the target definition:

```text
subject guard == role or permission guard
```

A mismatch throws `GuardMismatch`. GateTrail must not:

- clone the role or permission into another guard;
- fall back to another same-named definition;
- change the subject guard;
- change the target definition's guard;
- create the assignment and rely on the resolver to ignore it.

Role-permission assignment compares the role and permission guards directly and
does not involve a subject.

## Authorization behavior

The permission resolver:

1. resolves the subject guard;
2. resolves the requested permission only within that guard;
3. evaluates direct and role-based sources within that guard;
4. returns default deny when no matching permission exists.

An explicit role or permission reference from another guard produces
`GuardMismatch` for mutation operations.

Authorization checks do not disclose cross-guard definitions:

- name checks do not search other guards and deny when no definition exists in
  the resolved guard;
- model, identifier, and explicit-reference checks return `false` when the
  resolved definition belongs to another guard;
- role and permission checks use the same deny behavior.

Infrastructure and invalid-guard failures are not converted to ordinary
authorization denial. They remain visible exceptions so configuration errors
are not hidden.

## Cache behavior

The resolved guard remains part of every request memoization and shared-cache
key:

```text
guard
subject morph type
subject identifier
```

Changing a subject method, property, or guard configuration is a deployment
change. It does not migrate existing assignments. Applications must clear
GateTrail caches after such a change.

The `gatetrail:doctor` command should report assignments whose referenced role
or permission guard no longer matches the subject's currently resolved guard.
It must not rewrite those assignments automatically.

## Subject concern boundary

`HasGateTrail` provides an internal accessor used by `SubjectGuardResolver` to
read a declared protected property safely. It does not implement an independent
guard algorithm.

The centralized resolver remains the source of truth for:

- authorization checks;
- assignment mutations;
- cache keys;
- middleware;
- Blade through Laravel Gate;
- diagnostic commands.

Consumers must not be required to implement an additional interface merely to
use the method or property override. A future optional interface may improve
static analysis but must preserve the same method signature.

## Exceptions

GateTrail uses:

```text
InvalidSubject
InvalidGuard
GuardMismatch
```

`InvalidGuard` includes safe context such as the subject type and resolution
source, but must not serialize the entire subject model.

`GuardMismatch` includes the normalized subject and target guard names and safe
target identifiers.

## Alternatives considered

### Infer the currently authenticated guard

Rejected because authorization may evaluate a subject different from the
authenticated user and may run without an HTTP request. Runtime authentication
state would make the same subject resolve differently across environments.

### Use only Laravel's default guard

Rejected because applications commonly have separate subject models or
authorization namespaces while retaining a different global authentication
default.

### Use only a model property

Rejected because some applications cannot modify the subject model directly,
while others require calculated but deterministic guard selection.

### Match configuration by parent classes and interfaces

Rejected initially because multiple matching parents or interfaces require an
additional precedence algorithm and can make guard choice difficult to see.

### Accept a per-call guard parameter

Rejected initially because it would allow one subject to acquire parallel
assignment universes and would affect cache, database, resolver, and mutation
semantics beyond a naming decision.

### Require every guard to exist in `auth.guards`

Rejected because GateTrail's guard is an authorization namespace and several
authentication mechanisms may intentionally share it.

## Consequences

### Positive

- Common single-guard applications require no model customization.
- Applications can choose an explicit model or configuration override.
- Resolution is deterministic in HTTP, queue, CLI, and tests.
- `Gate::forUser()` evaluates the supplied subject rather than global auth
  state.
- Exact precedence and validation prevent silent fallback after a typo.
- The same resolver drives authorization, mutation, cache, and diagnostics.

### Negative

- Applications with several subject classes must configure each class or add a
  model override.
- The property requires the `HasGateTrail` concern for protected access.
- Changing a resolved guard does not migrate existing assignments.
- Dynamic per-request guard selection is intentionally unsupported.
- Truly multi-guard subject records require a future design.
- Guard names may not correspond one-to-one with Laravel authentication guards.

## Specification alignment

`PACKAGE_SPEC.md` was updated when this ADR was accepted to:

- replace the provisional guard order with the accepted names and precedence;
- define guard normalization and failure behavior;
- document the configuration keys and exact class matching;
- define the single-guard subject limitation;
- align mutation, authorization, cache, doctor, and test requirements;
- add `InvalidGuard` to the exception categories;
- remove the subject guard naming item from open architecture decisions.

## Verification

Before this decision is considered implemented, tests must prove:

- the method override wins over every other source;
- the property wins over package and Laravel configuration;
- exact subject-class configuration wins over package default;
- package default wins over Laravel configuration;
- Laravel configuration is the final fallback;
- invalid authoritative values throw without continuing fallback;
- whitespace is trimmed and case is preserved;
- numeric-looking guard names remain strings;
- Eloquent attributes are not treated as PHP property overrides;
- global authenticated users and runtime guards do not affect resolution;
- `Gate::forUser()` resolves the supplied subject;
- assignment guard mismatches fail before persistence and audit;
- name-based authorization never searches another guard;
- cross-guard role and permission model, identifier, and explicit-reference
  checks return `false` without exposing the other guard;
- cache keys include the resolved guard;
- behavior is equivalent across HTTP, queue, CLI, and tests.

## References

- [Laravel: authentication guards](https://laravel.com/docs/13.x/authentication)
- [Laravel: specifying an authentication guard](https://laravel.com/docs/13.x/authentication#specifying-a-guard)
- [Laravel: accessing configuration values](https://laravel.com/docs/13.x/configuration#accessing-configuration-values)

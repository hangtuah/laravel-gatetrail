# ADR 0007: Bound GateTrail's Laravel Gate Hook to Argument-Free Abilities

- Status: Accepted
- Date: 2026-07-28
- Decision owners: GateTrail maintainers

## Context

GateTrail integrates named permissions with Laravel Gate without registering
every database permission as an individual Gate ability.

Laravel applications also use Gates and policies for resource-specific
authorization:

```php
$user->can('update', $document);
```

Laravel passes the document as an ability argument. If GateTrail matched only
the ability name, a GateTrail permission named `update` could pre-empt the
consumer's policy and grant access without evaluating the document.

The package specification also did not distinguish a missing permission
definition from a deactivated definition. Returning `null` for both would let
an unrelated application Gate with the same name regain control when a
GateTrail permission is deactivated.

GateTrail needs one precise boundary that permits named-permission checks,
preserves application policies, and makes deactivation fail closed.

## Decision

GateTrail registers one Laravel `Gate::before` callback. It does not register
one Laravel Gate definition per permission.

The callback receives:

```text
subject
ability
arguments
```

It evaluates in this order:

1. If the subject cannot be resolved as a GateTrail subject, return `null`.
2. If the arguments array is not empty, return `null`.
3. Resolve the subject's GateTrail guard.
4. Find an exact permission-name definition in that guard, including
   deactivated definitions.
5. If no definition exists, return `null`.
6. If the definition is deactivated, return `false`.
7. If the definition is active, return the permission resolver's boolean
   decision.

Conceptually:

```php
Gate::before(function (
    mixed $subject,
    string $ability,
    array $arguments,
): ?bool {
    if (! $gateTrail->supports($subject)) {
        return null;
    }

    if ($arguments !== []) {
        return null;
    }

    $definition = $permissions->findDefinitionIncludingDeactivated(
        subject: $subject,
        name: $ability,
    );

    if ($definition === null) {
        return null;
    }

    if ($definition->isDeactivated()) {
        return false;
    }

    return $resolver->allows($subject, $definition);
});
```

The example is illustrative internal orchestration, not a public package API.

## Matching boundary

The matching matrix is authoritative:

| Subject | Ability arguments | Definition in resolved guard | GateTrail result |
|---|---:|---|---|
| Not managed by GateTrail | Any | Not evaluated | `null` |
| GateTrail subject | One or more | Not evaluated | `null` |
| GateTrail subject | None | Missing | `null` |
| GateTrail subject | None | Active | Resolver `true` or `false` |
| GateTrail subject | None | Deactivated | `false` |

`null` means GateTrail does not own that Gate check. Laravel may continue to a
normal Gate or policy.

A non-null result means GateTrail owns that argument-free ability check. The
result is authoritative and Laravel does not continue to the normal ability or
policy callback.

Permission names use the exact case-preserving rules from the package
specification. The hook does not search other guards.

## Resource policies

Any ability invoked with one or more arguments is outside the global GateTrail
hook:

```php
$user->can('update', $document);
Gate::forUser($user)->allows('create', Document::class);
Gate::authorize('publish', [$document, $releaseChannel]);
```

Those checks continue through Laravel's normal Gate and policy resolution.

A consumer policy combines a GateTrail permission with application-specific
resource or state rules by performing a separate argument-free permission
check:

```php
public function approve(User $user, Document $document): bool
{
    return $user->can('documents.approve')
        && $document->isPending();
}
```

GateTrail does not inspect, discard, or reinterpret resource arguments.

## Missing and deactivated definitions

Definition existence and permission effectiveness are separate questions.

- A missing definition returns `null` so GateTrail does not claim an unrelated
  application ability.
- An active definition claims the ability, even when the resolver denies it
  because the subject has no effective assignment.
- A deactivated definition still claims the ability and returns `false`.

Deactivation therefore suspends the GateTrail ability. It must not expose an
unrelated Gate or policy with the same argument-free name as a fallback.

The definition lookup must include soft-deleted permission rows. A normal
soft-delete-scoped query is insufficient for the matching decision.

## Failures

Only an explicit non-match returns `null`.

Invalid guard configuration, invalid subject state after GateTrail accepts the
subject, database failures, and other infrastructure failures remain visible.
The callback must not convert those failures into `null` or an authorization
denial.

This distinction prevents an operational failure from silently handing a
GateTrail-owned ability to an unrelated application Gate.

## Callback ordering and application bypasses

Laravel executes global `before` callbacks in registration order and stops at
the first non-null result.

Once GateTrail's callback is invoked, its non-null result is authoritative.
However, a consumer callback registered earlier can return a non-null result
before GateTrail runs. GateTrail cannot override that application-owned global
bypass.

Consumers are responsible for reviewing global Gate callbacks and service
provider ordering. GateTrail does not provide or infer a super-administrator
bypass. A future diagnostic command may report known callback-order risks where
Laravel exposes enough information to do so safely.

## Inline authorization

Laravel's inline `Gate::allowIf()` and `Gate::denyIf()` checks do not execute
global `before` or `after` callbacks. They therefore do not evaluate GateTrail
permissions and must not be documented as GateTrail authorization APIs.

Consumers use normal Gate methods, subject helpers, GateTrail middleware, or
the permission resolver when GateTrail authorization is required.

## Middleware boundary

GateTrail permission middleware continues to call the permission resolver
directly, as accepted in ADR 0004. It does not call Laravel Gate and is not
affected by:

- ability arguments;
- unrelated Gate definitions;
- global Gate callback ordering.

## Alternatives considered

### Match every ability name regardless of arguments

Rejected because a broad permission name could pre-empt a resource policy and
grant access without evaluating the resource.

### Register one Laravel Gate definition per permission

Rejected because definitions are database state, may change at runtime, and
must not be loaded and registered individually during service-provider boot.

### Use a Laravel Gate after callback

Rejected because an existing Gate or policy result would normally win before
GateTrail could establish authoritative behavior for a matching permission.

### Return null for deactivated definitions

Rejected because deactivation would expose an unrelated ability with the same
name as a fallback and could restore access unexpectedly.

### Deny every unknown argument-free ability

Rejected because GateTrail must coexist with ordinary application Gates and
policies. It owns only names represented by a definition in the resolved
GateTrail guard.

### Route GateTrail middleware through Laravel Gate

Rejected because an unrelated Gate or earlier global callback could satisfy
middleware that explicitly requests GateTrail authorization.

## Consequences

### Positive

- Resource policies cannot be bypassed by a same-named GateTrail permission.
- Active and deactivated GateTrail definitions have deterministic ownership of
  their argument-free ability names.
- Deactivation fails closed instead of falling through.
- Ordinary application Gates and policies continue when GateTrail does not
  match.
- `Gate::forUser()` evaluates the supplied subject through the same hook.
- Middleware remains independent from unrelated Laravel Gate behavior.

### Negative

- GateTrail permissions cannot receive resource arguments through the global
  hook.
- Consumers must compose named permissions and resource rules inside policies.
- Matching requires a definition lookup that includes deactivated rows.
- Earlier consumer `before` callbacks can pre-empt GateTrail and must be
  governed by the consuming application.
- Inline Laravel authorization methods bypass GateTrail's hook.

## Specification alignment

`PACKAGE_SPEC.md` was updated when this ADR was accepted to:

- select `Gate::before` as the centralized hook;
- define argument-free matching and resource-policy coexistence;
- distinguish missing, active, and deactivated definitions;
- keep infrastructure failures visible;
- document callback-order and inline-authorization boundaries;
- require collision, deactivation, policy, and failure regression tests.

## Verification

Before this decision is considered implemented, tests must prove:

- an argument-free active definition returns the resolver decision;
- an argument-free missing definition returns `null`;
- an argument-free deactivated definition returns `false`;
- a same-named unrelated Gate cannot satisfy an active but unassigned
  GateTrail permission;
- a same-named unrelated Gate cannot satisfy a deactivated GateTrail
  permission;
- an ability with a model, class, or additional-context argument continues to
  its application Gate or policy;
- a policy may call a separate argument-free GateTrail permission;
- `Gate::forUser()` evaluates the supplied subject;
- an unsupported subject returns `null`;
- invalid guard, database, and configuration failures remain visible;
- permission middleware behavior is unaffected by Gate callback ordering;
- no individual Gate definitions are registered from permission rows.

## References

- [Laravel 12 authorization: intercepting Gate checks](https://laravel.com/docs/12.x/authorization#intercepting-gate-checks)
- [Laravel 13 authorization: intercepting Gate checks](https://laravel.com/docs/13.x/authorization#intercepting-gate-checks)
- [Laravel 13 authorization: supplying additional context](https://laravel.com/docs/13.x/authorization#supplying-additional-context)

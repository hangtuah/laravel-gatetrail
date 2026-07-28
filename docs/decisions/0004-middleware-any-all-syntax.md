# ADR 0004: Use Explicit Middleware Aliases for One, Any, and All

- Status: Accepted
- Date: 2026-07-28
- Decision owners: GateTrail maintainers

## Context

GateTrail provides route middleware for permission and role checks. A route may
require:

- one permission or role;
- at least one item from a list;
- every item in a list.

Laravel passes middleware parameters as comma-separated values after an alias.
GateTrail needs a syntax that is readable in route files, does not treat a
permission named `any` or `all` as an operator, and does not make an omitted
mode silently less restrictive.

The middleware must also define authentication failure, authorization failure,
missing definitions, invalid route configuration, guard resolution, and
composition behavior.

## Decision

GateTrail will register explicit aliases for singular, any, and all checks.

Permission aliases:

```text
gatetrail.permission
gatetrail.permission.any
gatetrail.permission.all
```

Role aliases:

```text
gatetrail.role
gatetrail.role.any
gatetrail.role.all
```

The alias itself carries the boolean mode. Permission and role names remain
plain Laravel middleware parameters.

## Permission syntax

### One permission

```php
Route::post('/documents/{document}/approve', ...)
    ->middleware([
        'auth',
        'gatetrail.permission:documents.approve',
    ]);
```

Exactly one permission parameter is required.

### Any permission

```php
Route::get('/documents', ...)
    ->middleware([
        'auth',
        'gatetrail.permission.any:documents.view,documents.review',
    ]);
```

The subject passes when at least one listed permission is effective.

### All permissions

```php
Route::post('/documents/{document}/publish', ...)
    ->middleware([
        'auth',
        'gatetrail.permission.all:documents.review,documents.publish',
    ]);
```

The subject passes only when every listed permission is effective.

## Role syntax

Role middleware follows the same pattern:

```php
Route::get('/administration', ...)
    ->middleware([
        'auth',
        'gatetrail.role:administrator',
    ]);

Route::get('/editorial', ...)
    ->middleware([
        'auth',
        'gatetrail.role.any:editor,reviewer',
    ]);

Route::post('/controlled-release', ...)
    ->middleware([
        'auth',
        'gatetrail.role.all:reviewer,publisher',
    ]);
```

An active role means an active, non-revoked, non-expired subject-role
assignment pointing to an active role definition in the subject's resolved
guard.

## Parameter rules

Middleware parameters are normalized by:

1. accepting Laravel's positional string parameters;
2. trimming surrounding whitespace;
3. rejecting an empty value;
4. preserving case exactly;
5. removing exact duplicates while preserving first-seen order.

Cardinality rules:

| Alias mode | Required parameter count |
|---|---|
| Singular | Exactly one |
| Any | One or more |
| All | One or more |

Using one item with an any or all alias is valid but redundant.

Invalid examples:

```php
->middleware('gatetrail.permission');
->middleware('gatetrail.permission:documents.view,documents.edit');
->middleware('gatetrail.permission.any:');
->middleware('gatetrail.permission.all:documents.view,');
```

Invalid middleware configuration throws `InvalidMiddlewareParameter`. It is a
developer configuration error and is not converted to an authorization denial.

### Commas in names

Laravel uses commas to separate middleware parameters. A role or permission name
containing a comma cannot be represented by these route aliases.

GateTrail does not initially prohibit commas in definition names because those
names remain usable through Gate, policies, and resolver APIs. Documentation
must state that route-middleware-addressable names cannot contain commas.

GateTrail will not introduce custom escaping or URL decoding into middleware
parameters in the initial release.

## Evaluation semantics

Permission middleware calls GateTrail's permission resolver directly. It does
not call Laravel Gate for each name.

This prevents an application Gate or policy with the same ability name from
satisfying middleware that explicitly requests a GateTrail permission.

For a normalized list `P`:

```text
singular(P) = allows(subject, P[0])
any(P)      = at least one permission in P is effective
all(P)      = every permission in P is effective
```

Role middleware calls GateTrail's role resolver using the equivalent semantics.

The subject guard is resolved once per middleware evaluation. Batch checks must
load the effective permission or role set once where practical rather than
issuing one complete resolver query per parameter.

Evaluation may short-circuit only after the required result is known:

- any may stop after the first allow;
- all may stop after the first deny;
- singular evaluates its one item.

## Missing definitions

A permission or role name that does not exist in the subject's resolved guard
is treated as not granted:

- singular denies;
- any continues evaluating other names;
- all denies.

Middleware does not throw `PermissionNotFound` or `RoleNotFound` for a missing
name. This avoids exposing definition existence through different HTTP
behavior.

Invalid guard or infrastructure failures remain visible exceptions and are not
converted to denial.

## Authentication and authorization failures

GateTrail middleware should normally run after Laravel authentication
middleware:

```php
->middleware([
    'auth',
    'gatetrail.permission:documents.view',
]);
```

If no subject is available, GateTrail throws Laravel's
`AuthenticationException`. It does not implement a custom login redirect.

If an authenticated subject fails the role or permission check, GateTrail
throws Laravel's `AuthorizationException`. Laravel therefore produces its normal
403 response unless the consumer customizes exception rendering.

Failure messages are generic and must not list which item was missing. Safe
diagnostic information may be logged according to package configuration but is
not included in the default HTTP response.

## Composition

Laravel applies multiple route middleware entries as successive requirements.
This creates an AND between groups:

```php
Route::post('/documents/{document}/publish', ...)
    ->middleware([
        'auth',
        'gatetrail.permission.any:documents.review,documents.override',
        'gatetrail.permission:documents.publish',
    ]);
```

The logical expression is:

```text
(documents.review OR documents.override)
AND documents.publish
```

GateTrail does not initially parse:

- parentheses;
- negation;
- nested boolean expressions;
- wildcard permission names;
- mode tokens embedded inside the parameter list.

Complex resource or state authorization remains an application policy concern.

## Middleware classes

The public aliases map to thin middleware classes:

```text
RequirePermission
RequireAnyPermission
RequireAllPermissions
RequireRole
RequireAnyRole
RequireAllRoles
```

The classes delegate normalization and evaluation to shared internal services.
They must not implement independent permission or role resolution algorithms.

Conceptual singular middleware:

```php
final class RequirePermission
{
    public function __construct(
        private PermissionMiddlewareEvaluator $evaluator,
    ) {}

    public function handle(
        Request $request,
        Closure $next,
        string ...$permissions,
    ): Response {
        $this->evaluator->authorizeOne(
            subject: $request->user(),
            permissions: $permissions,
        );

        return $next($request);
    }
}
```

The middleware and evaluator are resolved through Laravel's service container
and remain route-cache compatible.

## Alias stability

The six default alias strings are public compatibility surface.

The service provider registers them automatically. `gatetrail:doctor` should
report when a default alias is already bound to an unrelated middleware class.
GateTrail must not silently replace an unrelated consumer alias.

Consumers may use middleware classes directly, but documentation and examples
prefer aliases.

## Alternatives considered

### Mode as the first parameter

Example:

```php
->middleware(
    'gatetrail.permission:any,documents.view,documents.review',
);
```

Rejected because `any` and `all` become reserved parameter values, single-name
syntax becomes ambiguous, and omitting or misspelling the mode requires another
fallback rule.

### Boolean delimiters

Examples:

```text
documents.view|documents.review
documents.review&documents.publish
```

Rejected because the syntax is less obvious, requires a custom parser, and
reserves characters inside permission names.

### Multiple parameters default to any

Rejected because adding a second permission could silently weaken a route from
requiring one known item to accepting either item.

### Multiple parameters default to all

Rejected because the mode remains implicit and a route review cannot distinguish
intent from an accidental extra parameter.

### Always use Laravel Gate

Rejected because an application Gate with the same name could satisfy
GateTrail-specific middleware and bypass GateTrail's role, assignment, guard,
and lifecycle semantics.

### One alias with configuration-selected mode

Rejected because changing configuration could alter the authorization meaning
of existing route definitions.

## Consequences

### Positive

- Route definitions state one, any, or all intent explicitly.
- Permission names `any` and `all` remain usable.
- Permission and role middleware share one predictable syntax.
- No custom boolean-expression parser is required.
- Multiple middleware entries naturally support AND between groups.
- GateTrail-specific middleware cannot be satisfied by an unrelated Laravel
  Gate.
- Invalid route configuration fails visibly instead of silently changing
  authorization.

### Negative

- Six middleware aliases and thin classes must be maintained.
- Any and all aliases are longer than a compact operator syntax.
- Comma-containing names cannot be addressed through route aliases.
- Complex boolean expressions require multiple middleware entries or policies.
- Alias collisions require installation diagnostics.

## Specification alignment

`PACKAGE_SPEC.md` was updated when this ADR was accepted to:

- replace provisional middleware aliases with the six accepted aliases;
- define singular, any, all, parameter, and composition semantics;
- define authentication, authorization, and missing-definition behavior;
- add `InvalidMiddlewareParameter` to exception categories;
- require direct GateTrail resolver evaluation and batch-query safety;
- align middleware, security, doctor, and route tests;
- remove the middleware syntax item from open architecture decisions.

## Verification

Before this decision is considered implemented, tests must prove:

- all six aliases resolve to their intended middleware;
- singular accepts exactly one parameter;
- any accepts one or more and allows at least one effective item;
- all accepts one or more and requires every effective item;
- whitespace is trimmed, case is preserved, and duplicates are removed;
- empty and invalid parameter lists throw `InvalidMiddlewareParameter`;
- missing definitions deny without throwing not-found exceptions;
- unauthenticated requests throw `AuthenticationException`;
- authenticated denials throw `AuthorizationException`;
- generic 403 responses do not disclose missing names;
- subject guards resolve once per evaluation;
- batch checks do not perform a full query per item;
- unrelated Laravel Gates cannot satisfy GateTrail permission middleware;
- multiple middleware entries compose as AND;
- middleware remains route-cache compatible;
- behavior is equivalent for `web`, `api`, and custom request contexts.

## References

- [Laravel: middleware aliases](https://laravel.com/docs/13.x/middleware#middleware-aliases)
- [Laravel: middleware parameters](https://laravel.com/docs/13.x/middleware#middleware-parameters)
- [Laravel: authorizing actions using gates](https://laravel.com/docs/13.x/authorization#authorizing-actions-using-gates)

# GateTrail Project Charter

## Project identity

| Item | Value |
|---|---|
| Product name | GateTrail |
| Composer package | `hangtuah/laravel-gatetrail` |
| PHP namespace | `HangTuah\GateTrail` |
| Repository | `hangtuah/laravel-gatetrail` |
| Owner | Mohd Fairus bin Ahmad (`hangtuah`) |
| Maintainer | Mohd Fairus bin Ahmad (`hangtuah`) |
| License | MIT |
| Current visibility | Private during early development |
| Intended visibility | Public after the package is stable |
| Current status | Pre-alpha |

## Description

GateTrail is a Laravel-native package for managing roles, permissions, and
application authorization.

## Problem statement

Laravel applications frequently need role and permission management, but teams
can find it difficult to understand, customize, audit, and reuse authorization
implementations across multiple projects.

GateTrail aims to provide a clear and reusable RBAC foundation whose
authorization flow can be understood from its public API down to its database
relationships.

## Vision

Provide a dependable Laravel RBAC package that is easy to use, straightforward
to understand, and suitable for reuse across applications without imposing
application-specific business rules.

## Goals

GateTrail should:

- provide roles and permissions through a consistent public API;
- support permissions inherited through roles;
- support permissions assigned directly to an authorized subject;
- integrate with Laravel Gate, policies, middleware, and Blade authorization;
- preserve assignment and revocation history;
- record the actor and reason behind access changes;
- use predictable, documented authorization rules;
- remain independent from any one application's domain or database schema;
- be testable as a standalone Laravel package;
- provide extension points without requiring consumers to modify package code;
- make security-sensitive behavior visible and understandable to maintainers.

## Intended users

The primary users are:

- Laravel teams maintaining more than one application;
- internal and enterprise application developers;
- internal and operational systems that require controlled access;
- applications that need roles, permissions, and access history;
- developers who prefer an explicit and well-documented RBAC implementation.

## Guiding principles

### Laravel-native

Consumers should be able to use standard Laravel authorization:

```php
$user->can('documents.approve');
Gate::authorize('documents.approve');
```

```blade
@can('documents.approve')
    <!-- Authorized content -->
@endcan
```

### Default deny

Access is denied unless an active assignment grants the requested permission.

### One authorization source of truth

All permission checks must resolve through one documented permission resolver.
Traits, middleware, Gate integration, and other adapters must not implement
different authorization rules.

### History-preserving

Revoking access must not silently remove evidence that an assignment previously
existed.

### Explicit mutations

Role and permission changes should pass through package services that apply
validation, transactions, audit recording, and cache invalidation consistently.

### Domain-independent

The package must not contain roles, permissions, workflows, or terminology from
a specific consumer application.

### Understandable before clever

Clear relationships and predictable behavior take priority over hidden
abstractions or premature optimization.

### Secure by default

The package should prefer safe defaults and require consumers to opt into
security-sensitive shortcuts or bypasses.

## Initial product scope

The first stable release is expected to cover:

- role management;
- permission management;
- role assignments;
- permissions assigned to roles;
- permissions assigned directly to subjects;
- assignment and revocation lifecycle;
- actor and reason metadata;
- effective permission resolution;
- Laravel Gate integration;
- authorization middleware;
- configuration and publishable migrations;
- cache invalidation;
- access-change history;
- automated unit, integration, and security tests;
- installation, usage, and troubleshooting documentation.

The exact behavior and public API will be defined in `PACKAGE_SPEC.md` before
implementation begins.

## Non-goals for the initial release

The initial release will not attempt to provide:

- authentication, login, password, or session management;
- a complete identity and access management platform;
- application-specific business policies;
- a bundled administration UI;
- approval or maker-checker workflows;
- role hierarchy;
- wildcard permissions;
- explicit deny rules;
- multi-tenant or team-scoped assignments;
- separation-of-duties rules;
- a super-admin bypass;
- support for every Laravel, PHP, or database version.

These capabilities may be considered later when supported by real consumer
requirements.

## Repository and release policy

- Development starts in a private GitHub repository.
- The package will use semantic versioning.
- Pre-`1.0.0` releases may change while the public API is being validated.
- At least one internal pilot must be completed before `1.0.0`.
- The repository may become public after its API, migrations, tests, security
  behavior, and documentation are considered stable.
- Security-sensitive changes require regression tests.
- Breaking public API or schema changes require an upgrade guide.

## Governance

The owner and maintainer are responsible for:

- approving the package specification;
- reviewing architectural decisions;
- reviewing security-sensitive changes;
- maintaining compatibility and release documentation;
- publishing releases;
- deciding when the repository is ready to become public.

Major decisions should be recorded as architecture decision records under
`docs/decisions/`.

## Success criteria

GateTrail is successful when:

- multiple Laravel applications can use the same package implementation;
- authorization behavior is consistent across Gate, middleware, and direct
  checks;
- role and permission changes can be explained from retained history;
- revoking access takes effect predictably;
- developers can install and use the package from its documentation;
- package upgrades replace copying RBAC code between applications;
- consumer applications remain responsible for their own policies and business
  rules.

## Next milestone

Implement the first vertical slice from the accepted package specification:

1. create a role and permission;
2. grant the permission to the role with actor and reason;
3. assign the role to a test subject with actor and reason;
4. allow the permission through Laravel Gate;
5. revoke the role assignment;
6. deny the permission through Laravel Gate;
7. preserve assignment history and versioned audit evidence;
8. prove the flow with automated integration tests.

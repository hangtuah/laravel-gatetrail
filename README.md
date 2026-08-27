# GateTrail

Roles, permissions, and authorization management for Laravel.

GateTrail records who holds which role, which permissions a role carries,
which permissions a subject holds directly, and what a subject may do at a
given moment. Every change goes through audited mutation services that require
an actor and a reason, rather than through direct relationship writes.

## Status

**Pre-alpha, pre-`1.0.0`. The public API is not frozen.**

No release has been tagged. Implementation is still in progress: mutation
services and the effective-access resolvers are complete, and Laravel
integration, caching, hardening and release validation are not.

The supported surface is defined by
[ADR 0014](docs/decisions/0014-pre-v1-public-api-boundary.md) and listed in
[`docs/PUBLIC_API.md`](docs/PUBLIC_API.md). Before `1.0.0`, a deliberate
breaking change to that surface still ships with an ADR, a changelog entry and
migration guidance. Track [`CHANGELOG.md`](CHANGELOG.md) when upgrading.

## Requirements

| Dependency | Supported |
|---|---|
| PHP | `^8.3` |
| Laravel | `^12.0 \| ^13.0` |
| MySQL | 8.0.16 or later — verified against live 8.0.16 and 8.0.45 |
| Oracle Database | 12c Release 2 or later — verified against live Oracle Free 23.26.1 |
| SQLite | Used by the package's own test suite |
| PostgreSQL, SQL Server | Query compilation and schema inspection only; no live endpoint has been executed |

The behavioural suite runs on SQLite and live MySQL for both Laravel majors.
Oracle runs a targeted live matrix for both generated-key strategies and both
provisioning modes. PostgreSQL and SQL Server are **not** verified runtimes
yet.

## Installation

```bash
composer require hangtuah/laravel-gatetrail
```

`composer.json` declares `extra.laravel.providers`, so Laravel package
auto-discovery registers `GateTrailServiceProvider` automatically. No manual
provider or alias registration is needed.

## Configuration

Publishing the configuration file is optional; the package merges its own
defaults. Publish it to change table names, models, guards, auditing or
provisioning:

```bash
php artisan vendor:publish --tag=gatetrail-config
```

That writes `config/gatetrail.php`. `gatetrail-config` is the only publish tag
GateTrail registers.

## Database provisioning

`gatetrail.database.provisioning` selects one of two modes.

**Package-managed** (`'package'`, the default) loads the seven GateTrail
migrations from the package and creates the schema through `php artisan
migrate`:

```php
// config/gatetrail.php
return [
    'database' => [
        'provisioning' => 'package',
    ],
    // ...
];
```

**DBA-managed** (`'dba'`) leaves DDL to a database owner outside the
application deployment account. GateTrail's migrations do not execute, and the
runtime account needs no create, alter or drop privilege. Versioned SQL
artifacts are committed for the engines that have them:

```text
database/ddl/mysql/0001/{install,rollback}.sql
database/ddl/oracle/0001/identity/{install,rollback}.sql
database/ddl/oracle/0001/sequence-trigger/{install,rollback}.sql
```

Both modes produce the same schema from the same canonical manifest, and
package behaviour does not depend on which one created it. See
[`database/ddl/mysql/README.md`](database/ddl/mysql/README.md) and
[`database/ddl/oracle/README.md`](database/ddl/oracle/README.md) for the
installation and rollback procedures, and the consumer guide for the full
comparison.

## Declaring a subject

A GateTrail subject is a persisted Eloquent model that declares a guard:

```php
use HangTuah\GateTrail\Concerns\HasGateTrail;
use Illuminate\Foundation\Auth\User as Authenticatable;

final class User extends Authenticatable
{
    use HasGateTrail;

    protected string $gateTrailGuardName = 'web';
}
```

A subject that exposes a public `gateTrailGuardName(): string` method does not
need the trait. Roles and permissions are namespaced by guard, so a subject can
only be assigned definitions from its own guard.

## A first mutation

Every mutation is performed through an injected contract, takes an actor and a
reason, and returns a `MutationResult`. Names alone do not create definitions —
create the role and the permission first, then connect them.

```php
use HangTuah\GateTrail\Contracts\AssignmentManager;
use HangTuah\GateTrail\Contracts\PermissionManager;
use HangTuah\GateTrail\Contracts\RoleManager;
use HangTuah\GateTrail\SystemActor;

final readonly class GrantReviewerAccess
{
    public function __construct(
        private RoleManager $roles,
        private PermissionManager $permissions,
        private AssignmentManager $assignments,
    ) {}

    public function __invoke(User $user): void
    {
        $actor = SystemActor::named('access-bootstrap');

        $this->roles->create(
            name: 'content-reviewer',
            guard: 'web',
            actor: $actor,
            reason: 'Create the document review role',
        );

        $this->permissions->create(
            name: 'documents.approve',
            guard: 'web',
            actor: $actor,
            reason: 'Create the document approval permission',
        );

        $this->assignments->grantPermissionToRole(
            role: 'content-reviewer',
            permission: 'documents.approve',
            actor: $actor,
            reason: 'Reviewers approve submitted documents',
        );

        $this->assignments->assignRole(
            subject: $user,
            role: 'content-reviewer',
            actor: $actor,
            reason: 'Joined the document review rota',
        );
    }
}
```

## A first permission check

`PermissionResolver` is the only supported way to ask what a subject may do.
It combines direct grants and role-derived grants, applies assignment
lifecycle, and denies by default.

```php
use HangTuah\GateTrail\Contracts\PermissionResolver;

final readonly class DocumentApprovalCheck
{
    public function __construct(
        private PermissionResolver $permissions,
    ) {}

    public function allowed(User $user): bool
    {
        return $this->permissions->allows(
            subject: $user,
            permission: 'documents.approve',
        );
    }
}
```

`RoleResolver` answers the equivalent question for roles.

## Public versus internal API

Depend on the contracts, value types, events and exceptions listed in
[`docs/PUBLIC_API.md`](docs/PUBLIC_API.md), and nothing else.

> **A class marked `@internal` is not consumer API.** PSR-4 makes all 218
> package classes loadable and the container can build most of them, but 157 of
> them carry a class-level `@internal` annotation and may be renamed, recomposed
> or deleted in any release, including a patch. That includes the `Default*`
> manager and resolver implementations behind the contracts — inject
> `AssignmentManager`, never `DefaultAssignmentManager`.

## Not yet implemented

These are designed or planned, and are **not** available today. Do not build
against them:

- the Laravel `Gate::before` hook and GateTrail-supplied policies;
- subject convenience methods such as `hasRole()` or `hasPermissionTo()`;
- route middleware and middleware aliases;
- Blade directives;
- GateTrail console commands, including `gatetrail:doctor` and
  `gatetrail:cache-clear`;
- the `InvalidMiddlewareParameter` and `UnauthorizedMutation` exception types;
- cross-request cache and cross-request invalidation APIs;
- an administration UI;
- wildcard permissions, role hierarchy, and tenant- or team-scoped roles.

## Documentation

- [`docs/CONSUMER_GUIDE.md`](docs/CONSUMER_GUIDE.md) — installation,
  provisioning, mutations, checks, auditing, events and error handling.
- [`docs/PUBLIC_API.md`](docs/PUBLIC_API.md) — the authoritative supported
  symbol manifest and exact signatures.
- [`PACKAGE_SPEC.md`](PACKAGE_SPEC.md) — the full package specification.
- [`ROADMAP.md`](ROADMAP.md) — phase progress and verification evidence.
- [`CHANGELOG.md`](CHANGELOG.md) — released and unreleased changes.

## License

GateTrail is released under the MIT License. See [`LICENSE`](LICENSE).

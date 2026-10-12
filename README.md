# GateTrail

Roles, permissions, and authorization management for Laravel.

GateTrail records who holds which role, which permissions a role carries,
which permissions a subject holds directly, and what a subject may do at a
given moment. Every change goes through audited mutation services that require
an actor and a reason, rather than through direct relationship writes.

## Status

**Pre-alpha, pre-`1.0.0`. The documented pre-v1 public API is frozen.**

No release has been tagged. Implementation is still in progress: mutation
services and the effective-access resolvers are complete, and Laravel
integration, caching, hardening and release validation are not.

The supported surface is defined by
[ADR 0014](docs/decisions/0014-pre-v1-public-api-boundary.md) and listed in
[`docs/PUBLIC_API.md`](docs/PUBLIC_API.md). The freeze prevents accidental
drift; before `1.0.0`, a deliberate breaking change to that surface still
requires an ADR, a changelog entry and migration guidance. Track
[`CHANGELOG.md`](CHANGELOG.md) when upgrading.

## Requirements

| Dependency | Supported |
|---|---|
| PHP | `^8.3` |
| Laravel | `^12.0 \| ^13.0` |
| MySQL | 8.0.16 or later — verified against live 8.0.16 and 8.0.45 |
| Oracle Database | 12c Release 2 or later — verified against live Oracle Free 23.26.1 |
| SQLite | Used by the package's own test suite |
| PostgreSQL | 14 or later — release `0001` DDL and read-only schema inspection verified on live 14 for both provisioning modes; application runtime remains unverified |
| SQL Server | 2017 or later — query compilation and schema-adapter support, plus release `0001` DBA DDL smoke-tested on live SQL Server 2022; no schema inspector and no application runtime evidence |

The behavioural suite runs on SQLite and live MySQL for both Laravel majors.
Oracle runs a targeted live matrix for both generated-key strategies and both
provisioning modes. PostgreSQL release `0001` install and rollback DDL and the
read-only schema inspector have been exercised on a live local PostgreSQL 14
endpoint for both package-managed and DBA-managed provisioning, but no
application behavioral suite has run there: no mutation, resolver, audit or
concurrency path has executed against PostgreSQL. SQL Server release `0001`
install and rollback DDL has been executed against a live disposable SQL Server
2022 database, which is schema-level artifact evidence only: SQL Server has no
schema inspector yet, and no mutation, resolver, audit or concurrency path has
run there either. PostgreSQL and SQL Server are **not** verified runtimes yet.

## Installation

```bash
composer require hangtuah/laravel-gatetrail
php artisan gatetrail:install
```

`composer.json` declares `extra.laravel.providers`, so Laravel package
auto-discovery registers `GateTrailServiceProvider` automatically. No manual
provider or alias registration is needed.

The install command publishes `config/gatetrail.php` if it does not already
exist and, in the default package-managed mode, runs only GateTrail's seven
migrations. It never overwrites an existing config file. Use `--force` to
permit migrations in production, or `--no-migrate` to publish config without
changing the database.

For DBA-managed provisioning, run `gatetrail:install --no-migrate`, set
`database.provisioning` to `dba`, and have the database owner apply the matching
versioned artifact. Running the command after `dba` is configured never runs
package migrations.

## Configuration

Publishing the configuration manually is optional; the package merges its own
defaults, and `gatetrail:install` already publishes it safely. Without that
command, publish it to change table names, models, guards, auditing or
provisioning:

```bash
php artisan vendor:publish --tag=gatetrail-config
```

See the exhaustive [`docs/CONFIGURATION.md`](docs/CONFIGURATION.md) reference
for every key, default, valid value, validation boundary and deployment
consideration.

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
database/ddl/postgresql/0001/{install,rollback}.sql
database/ddl/sqlserver/0001/{install,rollback}.sql
```

Both modes produce the same schema from the same canonical manifest, and
package behaviour does not depend on which one created it. See
[`database/ddl/mysql/README.md`](database/ddl/mysql/README.md),
[`database/ddl/oracle/README.md`](database/ddl/oracle/README.md),
[`database/ddl/postgresql/README.md`](database/ddl/postgresql/README.md) and
[`database/ddl/sqlserver/README.md`](database/ddl/sqlserver/README.md) for
installation and rollback procedures, and the consumer guide for the full
comparison.

## Diagnostics

Run the read-only consistency check after installation, an upgrade, or a known
DBA intervention:

```bash
php artisan gatetrail:doctor
php artisan gatetrail:doctor --json
```

Doctor inspects the configured physical schema where an inspector exists and
checks assignment periods, revocation tuples, definition references, guards
and persistent coordination rows. It never migrates, repairs, revokes, deletes,
backfills, locks or clears caches. An error finding or incomplete coverage
returns a non-zero exit code. SQLite and SQL Server currently receive complete
data checks but incomplete physical-schema coverage because no inspector exists
for those drivers.

A clean result is only a best-effort consistency result. It cannot prove that
no direct database write occurred or that no audit history is missing.

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

Laravel's normal authorization API uses the same resolver automatically for
argument-free permission abilities:

```php
$user->can('documents.approve');
Gate::forUser($user)->allows('documents.approve');
Gate::authorize('documents.approve');
```

Native Blade `@can` / `@cannot` directives use that same decision. Checks with
resource arguments, such as `$user->can('update', $document)`, continue to the
application's normal gates and policies.

Routes can enforce the same permission or role set through the six automatic
aliases:

```php
Route::post('/documents/{document}/approve', DocumentApprovalController::class)
    ->middleware([
        'auth',
        'gatetrail.permission:documents.approve',
    ]);

Route::get('/review', ReviewQueueController::class)
    ->middleware('gatetrail.role.any:reviewer,publisher');
```

Use the `.any` alias when one listed name is sufficient and `.all` when every
listed name is required. The same forms exist for `gatetrail.permission` and
`gatetrail.role`.

Models using `HasGateTrail` also receive concise helpers backed by the same
resolvers:

```php
$user->hasRole('reviewer');
$user->hasAnyRole(['reviewer', 'publisher']);
$user->hasPermissionTo('documents.approve');
$user->effectivePermissions();
$user->roles();
```

## Public versus internal API

Depend on the contracts, value types, events and exceptions listed in
[`docs/PUBLIC_API.md`](docs/PUBLIC_API.md), and nothing else.

> **A class marked `@internal` is not consumer API.** PSR-4 makes all 251
> package classes loadable and the container can build most of them, but 183 of
> them carry a class-level `@internal` annotation and may be renamed, recomposed
> or deleted in any release, including a patch. That includes the `Default*`
> manager and resolver implementations behind the contracts — inject
> `AssignmentManager`, never `DefaultAssignmentManager`.

## Not yet implemented

These are designed or planned, and are **not** available today. Do not build
against them:

- GateTrail-supplied resource policies;
- custom GateTrail Blade directives—use Laravel's native `@can` / `@cannot`;
- other planned GateTrail console commands, including `gatetrail:matrix` and
  `gatetrail:cache-clear`;
- the `UnauthorizedMutation` exception type;
- cross-request cache and cross-request invalidation APIs;
- an administration UI;
- wildcard permissions, role hierarchy, and tenant- or team-scoped roles.

## Security

GateTrail records the supplied mutation `actor` for attribution; it does not
authorize that actor. Consumer controllers, jobs and services must authorize
the operation with Laravel policies or Gates before calling a GateTrail
manager.

The security model and its tested boundaries are documented in
[`docs/security/threat-model.md`](docs/security/threat-model.md). Report a
suspected vulnerability privately by following [`SECURITY.md`](SECURITY.md),
not through a public issue.

## Documentation

- [`docs/CONSUMER_GUIDE.md`](docs/CONSUMER_GUIDE.md) — installation,
  provisioning, mutations, checks, auditing, events and error handling.
- [`docs/CONFIGURATION.md`](docs/CONFIGURATION.md) — every supported key,
  default, validation rule and deployment consideration.
- [`docs/UPGRADING.md`](docs/UPGRADING.md) — safe package-managed and
  DBA-managed upgrades, configuration reconciliation and rollback planning.
- [`docs/PUBLIC_API.md`](docs/PUBLIC_API.md) — the authoritative supported
  symbol manifest and exact signatures.
- [`PACKAGE_SPEC.md`](PACKAGE_SPEC.md) — the full package specification.
- [`ROADMAP.md`](ROADMAP.md) — phase progress and verification evidence.
- [`CHANGELOG.md`](CHANGELOG.md) — released and unreleased changes.

## License

GateTrail is released under the MIT License. See [`LICENSE`](LICENSE).

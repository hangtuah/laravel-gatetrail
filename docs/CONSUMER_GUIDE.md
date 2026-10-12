# GateTrail Consumer Guide

How to install GateTrail, provision its schema, change authorization state and
ask what a subject may do — using only the supported pre-v1 API.

Every symbol used in this guide appears in
[`docs/PUBLIC_API.md`](PUBLIC_API.md). Where this guide and that document
disagree, `PUBLIC_API.md` wins: it is the authoritative manifest and is
verified against source by an automated reflection test.

## 1. Package status and compatibility promise

GateTrail is **pre-alpha and pre-`1.0.0`**, and its documented **pre-v1 public
API is frozen**. No release has been tagged.

What that does and does not mean:

| Question | Answer |
|---|---|
| Can the supported surface still change? | Yes, deliberately before `1.0.0`, under the change policy below |
| Will it change silently? | No |
| What ships with a deliberate breaking change? | An ADR, a `CHANGELOG.md` entry, and migration guidance when consumer code or configuration must change |
| What happens to accidental drift? | It is a defect. `PublicApiSurfaceTest` rejects it in CI |
| What may change in any release, including a patch? | Anything marked `@internal` — currently 179 of the package's 247 classes |

The boundary itself is decided in
[ADR 0014](decisions/0014-pre-v1-public-api-boundary.md), which classifies
every package symbol into one of five tiers. Only Tier 1 (39 symbols you call,
receive, catch or listen to) and Tier 2 (13 symbols you implement, extend or
configure) carry a promise. Their freeze rejects accidental drift; a deliberate
breaking change before `1.0.0` requires an ADR, a changelog entry and migration
guidance whenever consumer code or configuration must change. Future phases may
add newly classified API without breaking this boundary.

### Supported runtimes

| Dependency | Supported |
|---|---|
| PHP | `^8.3` |
| Laravel | `^12.0 \| ^13.0` |
| Orchestra Testbench | `^10.0 \| ^11.0` |
| PSR Clock | `psr/clock:^1.0` |

### Database evidence, stated exactly

| Engine | What has actually been executed |
|---|---|
| SQLite | The full behavioural suite, on both Laravel majors. Test database, not a reference production database |
| MySQL 8.0.16 and 8.0.45 | Live four-lane CI matrix across both Laravel majors, in both provisioning modes, including the resolver acceptance matrix |
| Oracle Free 23.26.1 | Live targeted matrix on both dependency lanes, covering both generated-key strategies in both provisioning modes. The declared 12c Release 2 minimum has **not** been exercised live |
| PostgreSQL 14+ | Release `0001` install and rollback DDL, and read-only schema inspection, exercised on a live local PostgreSQL 14 endpoint in both provisioning modes. **No application behavioral suite has run**; runtime compatibility is not claimed |
| SQL Server 2017+ | Query compilation and schema-adapter support, plus release `0001` install and rollback DDL executed on a live disposable SQL Server 2022 database. **Schema-level evidence only**: no schema inspector exists, no application behavioral suite has run, and no runtime compatibility is claimed |

Compiling a statement is not evidence that it runs, and neither is inspecting a
schema. PostgreSQL now has live schema evidence, but no mutation, resolver,
audit or concurrency path has executed against it. Treat PostgreSQL and SQL
Server as unverified runtimes until a live behavioural suite exists for them.

## 2. Installation

```bash
composer require hangtuah/laravel-gatetrail
php artisan gatetrail:install
```

`composer.json` declares:

```json
"extra": {
    "laravel": {
        "providers": [
            "HangTuah\\GateTrail\\GateTrailServiceProvider"
        ]
    }
}
```

Laravel package auto-discovery therefore registers the provider for you. Do not
add it to `config/app.php`, and do not register an alias — GateTrail publishes
no facade.

In the default package-managed mode, `gatetrail:install` publishes the config
file if absent and runs only GateTrail's seven migrations. It never overwrites
an existing config file. Its supported options are:

| Option | Effect |
|---|---|
| `--no-migrate` | Publish configuration but make no schema change |
| `--force` | Permit the package migrations to run when Laravel is in production |

For DBA-managed provisioning, begin with
`php artisan gatetrail:install --no-migrate`, change the published provisioning
mode to `dba`, then follow section 4.2. Once `dba` is active, the command always
leaves DDL to the database owner even when `--force` is supplied.

## 3. Configuration publishing

Publishing is optional when the install command is not used. The provider
calls `mergeConfigFrom()`, so every default in the packaged
`config/gatetrail.php` is active without publishing anything. The install
command publishes through the same `gatetrail-config` tag and does not
overwrite an existing file.

Publish when you need to change table names, models, guards, auditing,
provisioning or Oracle object names:

```bash
php artisan vendor:publish --tag=gatetrail-config
```

`gatetrail-config` is the **only** tag GateTrail registers, and it writes
exactly one file, `config/gatetrail.php`.

The keys you are most likely to touch:

| Key | Default | Purpose |
|---|---|---|
| `database.connection` | `null` | Named connection GateTrail models and migrations use; `null` means the application default |
| `database.schema` | `null` | Schema qualifier for engines that need one |
| `database.prefix` | `null` | Prefix applied to every GateTrail table name |
| `database.provisioning` | `'package'` | `'package'` or `'dba'` — see section 4 |
| `tables.*` | `acl_*` | The seven GateTrail table names |
| `models.*` | package models | Your replacement models — see section 5 |
| `guards.default` | `null` | GateTrail's own default guard; falls back to `auth.defaults.guard` |
| `guards.subjects` | `[]` | Exact subject class to guard-name map |
| `assignments.require_reason` | `true` | Whether assignment mutations require a reason |
| `definitions.require_reason` | `false` | Whether role and permission mutations require a reason |
| `audit.enabled` / `audit.strict` | `true` / `true` | Audit mode — see section 15 |
| `sensitive_fields` | seven names | Attribute names redacted from audit snapshots |

This table is intentionally the quick-start subset. The exhaustive
[`CONFIGURATION.md`](CONFIGURATION.md) reference documents every supported key,
type, default, valid value, failure mode and deployment consideration.

## 4. Database provisioning

GateTrail owns seven tables. Two supported modes create them, and package
behaviour is identical afterwards.

### 4.1 Package-managed mode

The default. The provider loads the package's migrations when
`gatetrail.database.provisioning` is `'package'` **and** the application is
running in console, so `php artisan migrate` creates the schema:

```php
// config/gatetrail.php
return [
    'database' => [
        'connection' => null,
        'schema' => null,
        'prefix' => null,
        'provisioning' => 'package',
    ],
    // ...
];
```

```bash
php artisan migrate
```

This mode requires the migration account to hold create, alter and drop
privileges on the target schema. It is the right choice for local development,
automated tests and general package use.

### 4.2 DBA-managed mode

Set `provisioning` to `'dba'` when a database owner outside the application
deployment account creates and upgrades the objects:

```php
// config/gatetrail.php
return [
    'database' => [
        'provisioning' => 'dba',
    ],
    // ...
];
```

In this mode:

- GateTrail's migrations do not execute. The provider does not register the
  package migration path at all, and `GateTrailMigration::shouldRun()` returns
  `false`, so a migration reached by any other path is skipped rather than
  partially applied;
- the runtime account needs no create, alter or drop privilege on GateTrail
  objects, only data access;
- the DBA installs the committed, versioned SQL artifacts for release `0001`;
- models, relationships, mutation services and resolvers behave exactly as
  they do under package-managed provisioning.

Only the value strings `'package'` and `'dba'` are accepted. Any other value
raises PHP's `InvalidArgumentException` the moment GateTrail resolves its
schema context.

### 4.3 Migration dependency and order safety

The seven migrations are named so that lexical order is dependency order:

```text
2026_07_28_000001_create_gatetrail_roles_table.php
2026_07_28_000002_create_gatetrail_permissions_table.php
2026_07_28_000003_create_gatetrail_assignment_locks_table.php
2026_07_28_000004_create_gatetrail_role_assignments_table.php
2026_07_28_000005_create_gatetrail_permission_assignments_table.php
2026_07_28_000006_create_gatetrail_role_permissions_table.php
2026_07_28_000007_create_gatetrail_access_audits_table.php
```

Order is enforced rather than assumed. A child-table migration verifies its
parents exist before creating itself, and a parent-table rollback verifies its
children are already gone. A violation throws `MigrationDependencyError`
instead of leaving a half-built schema:

- `acl_role_assignments` requires `acl_roles`;
- `acl_permission_assignments` requires `acl_permissions`;
- `acl_role_permissions` requires both `acl_roles` and `acl_permissions`;
- rolling back `acl_roles` or `acl_permissions` first requires their dependent
  tables to be absent.

If you run GateTrail's migrations alongside your own, do not renumber or
interleave them.

### 4.4 Generated artifact locations

The DBA-managed artifacts are committed in the repository and are compiled from
the same canonical schema manifest that package migrations use:

```text
database/ddl/mysql/0001/install.sql
database/ddl/mysql/0001/rollback.sql
database/ddl/oracle/0001/identity/install.sql
database/ddl/oracle/0001/identity/rollback.sql
database/ddl/oracle/0001/sequence-trigger/install.sql
database/ddl/oracle/0001/sequence-trigger/rollback.sql
database/ddl/postgresql/0001/install.sql
database/ddl/postgresql/0001/rollback.sql
database/ddl/sqlserver/0001/install.sql
database/ddl/sqlserver/0001/rollback.sql
```

Each engine directory carries its own README with the installation, rollback
and privilege procedure — read it before running anything. The committed files
use GateTrail's canonical names with no schema qualifier and no prefix; an
environment that configures a different schema, prefix or table name must
generate and review matching DDL from the same manifest rather than editing a
committed artifact.

Artifacts are reproducible byte-for-byte, and the package's own tests fail when
a committed file drifts from compiler output.

PostgreSQL release `0001` artifacts are available and reproducible. Their
install and rollback shape has passed a local live PostgreSQL 14 smoke test,
including generated and explicit IDs, and read-only schema inspection now runs
live against both the artifacts and the package migrations on PostgreSQL 14. The
application behavioral harness remains future work.

SQL Server release `0001` artifacts are also available and reproducible. Their
install and rollback shape has passed a live SQL Server 2022 smoke test covering
the seven tables, fifteen explicit indexes, every constraint, identity-generated
keys and case-sensitive comparison on a case-insensitive database. That is
**schema-level evidence only**: SQL Server has no read-only schema inspector
yet, and no application behavioral harness has run against it.

### 4.5 Oracle is an example, not GateTrail's identity

GateTrail is a general Laravel authorization package. Oracle appears throughout
its documentation because Oracle environments commonly separate application and
DDL privileges, which makes them the sharpest test of DBA-managed provisioning
and of portable, explicitly named schema objects. That is all it is — a
demanding example engine.

Nothing in the core design is Oracle-specific: authorization decisions use
Eloquent and the query builder, never vendor SQL, and the package clock rather
than a database time function. MySQL is equally first-class and carries the
broader live matrix.

Oracle does add one choice of its own. `gatetrail.oracle.generated_keys`
selects `identity` or `sequence_trigger`, and one schema uses exactly one of
them. Pick the strategy your DBA permits, configure GateTrail to match, and
install the matching artifact variant.

## 5. Model setup

### 5.1 The subject must be an Eloquent model

A GateTrail subject is a **persisted** `Illuminate\Database\Eloquent\Model`.
The resolvers and managers accept `Model`, not `mixed`, and an unsaved model
resolves to no access rather than raising an error.

### 5.2 Declaring the guard

Roles and permissions are namespaced by `guard_name`. GateTrail resolves one
guard per subject, in this exact order, and the first defined source wins:

1. a public `gateTrailGuardName(): string` method on the subject;
2. a declared `$gateTrailGuardName` property, read through `HasGateTrail`;
3. an exact subject-class entry in `gatetrail.guards.subjects`;
4. `gatetrail.guards.default`;
5. `auth.defaults.guard`.

An authoritative source holding an invalid value throws `InvalidGuard` rather
than falling through to the next one.

**The property route** uses the supported trait:

```php
use HangTuah\GateTrail\Concerns\HasGateTrail;
use Illuminate\Foundation\Auth\User as Authenticatable;

final class User extends Authenticatable
{
    use HasGateTrail;

    protected string $gateTrailGuardName = 'web';
}
```

`$gateTrailGuardName` is a declared PHP property, not an Eloquent attribute or
a database column.

**The method route** needs no trait at all. `HasGateTrail` exists so the
resolver can read a declared property; a subject that answers the question
directly is already complete:

```php
use Illuminate\Foundation\Auth\User as Authenticatable;

final class ApiClient extends Authenticatable
{
    public function gateTrailGuardName(): string
    {
        return 'api';
    }
}
```

**The configuration route** keeps the declaration out of the model entirely,
which is useful for a class you do not own:

```php
// config/gatetrail.php
return [
    'guards' => [
        'default' => null,
        'subjects' => [
            'App\Models\ServiceAccount' => 'api',
        ],
    ],
    // ...
];
```

The map is matched on the **exact** class name; a subclass does not inherit its
parent's entry.

### 5.3 Guard consistency

One guard name is one authorization namespace. Consequences you will meet
immediately:

- a role may only receive permissions from its own guard;
- a subject may only be assigned roles and permissions from its resolved
  guard;
- crossing a guard boundary throws `GuardMismatch`;
- role and permission uniqueness is `(name, guard_name)`, so `web` and `api`
  may each own a role named `content-reviewer`, and they are different roles;
- a guard name need not exist in `auth.guards`, so several authentication
  mechanisms can share one authorization namespace.

When you grant a permission to a role and pass **two bare names**, there is no
subject to resolve a guard from, so GateTrail uses `gatetrail.guards.default`
and falls back to `auth.defaults.guard`. If your application uses more than one
guard, pass a model or a reference for at least one side, or set
`gatetrail.guards.default` deliberately.

### 5.4 Replacing a package model

Every model is configurable through `gatetrail.models.*`. The **requirements**
are the compatibility promise:

| Config key | Must be an Eloquent model | Must implement | Must extend the package model | Must use `SoftDeletes` |
|---|:-:|---|:-:|:-:|
| `role` | yes | `RoleContract` | no | yes |
| `permission` | yes | `PermissionContract` | no | yes |
| `role_assignment` | yes | `RoleAssignmentContract` | yes | no |
| `permission_assignment` | yes | `PermissionAssignmentContract` | yes | no |
| `role_permission` | yes | `RolePermissionContract` | yes | no |
| `access_audit` | yes | — | yes | no |

The five marker contracts declare **zero methods**, so implementing one costs
nothing. Role and permission models may be independent Eloquent models;
assignment and audit models must extend their package base, because GateTrail
relies on the connection resolution, table resolution and — for the audit model
— the absent `updated_at` write that the base provides.

A misconfigured model throws `InvalidModelConfiguration`.

## 6. Creating a role

Definitions are created through `RoleManager`. Inject the contract; never the
concrete implementation behind it.

```php
use HangTuah\GateTrail\Contracts\AssignmentManager;
use HangTuah\GateTrail\Contracts\PermissionManager;
use HangTuah\GateTrail\Contracts\RoleManager;
use HangTuah\GateTrail\SystemActor;

final readonly class ReviewAccessProvisioner
{
    public function __construct(
        private RoleManager $roles,
        private PermissionManager $permissions,
        private AssignmentManager $assignments,
    ) {}

    public function createReviewerRole(): void
    {
        $this->roles->create(
            name: 'content-reviewer',
            guard: 'web',
            actor: SystemActor::named('access-bootstrap'),
            reason: 'Create the document review role',
            description: 'Reviews and approves submitted documents',
        );
    }
}
```

The remaining examples in sections 7 to 11 are methods on this class, so
`$this->roles`, `$this->permissions` and `$this->assignments` are the
constructor-injected contracts above.

Points worth internalising now:

- **A name is not a definition.** Passing `'content-reviewer'` to an assignment
  looks up an existing role with that name and guard; it never creates one.
  Create the definition first.
- `name` and `guard` are immutable once created. `RoleChanges` can only change
  the description.
- Creating a role whose `(name, guard_name)` already exists throws
  `DuplicateRole`, including when the existing definition is deactivated.
- `reason` is nullable for definition mutations and is governed by
  `gatetrail.definitions.require_reason`, which defaults to `false`. Supplying
  one anyway is good practice — it lands in the audit record.

## 7. Creating a permission

`PermissionManager` mirrors `RoleManager` exactly:

```php
$this->permissions->create(
    name: 'documents.approve',
    guard: 'web',
    actor: SystemActor::named('access-bootstrap'),
    reason: 'Create the document approval permission',
    description: 'Approve a submitted document',
);
```

Permission names are compared exactly: `documents.approve` and
`Documents.Approve` are different permissions, and surrounding whitespace is not
trimmed away for you. A duplicate `(name, guard_name)` throws
`DuplicatePermission`.

## 8. Assigning a role

```php
use HangTuah\GateTrail\AssignmentPeriod;
use Illuminate\Database\Eloquent\Model;

public function assignReviewer(Model $user): void
{
    $this->assignments->assignRole(
        subject: $user,
        role: 'content-reviewer',
        actor: SystemActor::named('access-bootstrap'),
        reason: 'Joined the document review rota',
        period: AssignmentPeriod::immediate(),
        metadata: ['ticket' => 'OPS-4821'],
    );
}
```

- `subject` must be a persisted Eloquent model whose resolved guard matches the
  role's guard.
- `role` accepts a `RoleContract` model, a `RoleReference`, a bare `string`
  **name**, or an `int` **identifier**.
- `reason` is required and non-nullable for every assignment mutation.
- `period` defaults to `null`, which behaves as `AssignmentPeriod::immediate()`.
- `metadata` is optional consumer context stored on the audit record, subject
  to the size, depth and redaction rules of section 15.
- Repeating an equivalent active assignment is idempotent: it returns
  `MutationOutcome::Unchanged` with a `null` audit identifier and writes
  nothing.

## 9. Granting a direct permission

A direct grant attaches a permission to a subject without a role:

```php
public function grantOneOffApproval(Model $user): void
{
    $this->assignments->grantDirectPermission(
        subject: $user,
        permission: 'documents.approve',
        actor: SystemActor::named('access-bootstrap'),
        reason: 'Temporary cover while the primary reviewer is away',
    );
}
```

Direct grants and role-derived grants combine. A subject holding a permission
from both sources holds it once, and revoking one source does not revoke the
other.

## 10. Granting a permission to a role

```php
public function letReviewersApprove(): void
{
    $this->assignments->grantPermissionToRole(
        role: 'content-reviewer',
        permission: 'documents.approve',
        actor: SystemActor::named('access-bootstrap'),
        reason: 'Reviewers approve submitted documents',
    );
}
```

Both sides must belong to the same guard. With two bare names the guard comes
from configuration, as section 5.3 describes.

Every subject holding the role gains the permission from the moment the grant
becomes active; nothing needs to be re-assigned per subject.

## 11. Revoking assignments

Revocation is not deletion. It stamps `revoked_at`, records the revoking actor
and reason, keeps the row, and writes an audit record.

Each of the three assignment kinds has its own revoke method, and each accepts
the assignment — not the subject and definition pair:

```php
use HangTuah\GateTrail\AssignmentReference;

public function removeReviewer(Model $user): void
{
    $assignment = $this->assignments->assignRole(
        subject: $user,
        role: 'content-reviewer',
        actor: SystemActor::named('access-bootstrap'),
        reason: 'Joined the document review rota',
    );

    $this->assignments->revokeRole(
        assignment: AssignmentReference::id(
            $assignment->resource->getKey(),
        ),
        actor: SystemActor::named('access-bootstrap'),
        reason: 'Left the document review rota',
    );
}
```

`revokeDirectPermission()` and `revokePermissionFromRole()` take the same
shape.

> **For assignments, a bare string or int is always an identifier.** Assignments
> have no names, so unlike `role` and `permission` parameters there is no
> name lookup here. Prefer `AssignmentReference::id()` to make that explicit.

Further rules:

- revoking a missing, expired or already-revoked assignment throws
  `AssignmentNotActive`;
- a scheduled assignment that has not started yet **is** revocable, so future
  access can be cancelled;
- a revoked assignment stays revoked even if its original expiry is still in
  the future;
- re-granting later creates a **new** assignment row; history is never
  overwritten or reactivated.

Deactivating a definition is a different operation. `RoleManager::deactivate()`
and `PermissionManager::deactivate()` suspend a definition globally while
preserving every stored assignment, and `restore()` reverses that. Preserved
assignments become eligible again on their own merits; revoked ones stay
revoked.

## 12. Effective permission and role checks

`PermissionResolver` and `RoleResolver` are the only supported way to ask what
a subject may do. Both contracts are **scoped**: one container scope evaluates
one state, so a singular check and its collection cannot disagree.

```php
use HangTuah\GateTrail\Contracts\PermissionResolver;
use HangTuah\GateTrail\Contracts\RoleResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final readonly class ReviewAccessQuery
{
    public function __construct(
        private PermissionResolver $permissions,
        private RoleResolver $roles,
    ) {}

    public function mayApproveDocuments(Model $user): bool
    {
        return $this->permissions->allows(
            subject: $user,
            permission: 'documents.approve',
        );
    }

    public function isReviewer(Model $user): bool
    {
        return $this->roles->has(
            subject: $user,
            role: 'content-reviewer',
        );
    }

    /** @return Collection<int, \HangTuah\GateTrail\Access\EffectivePermission> */
    public function allPermissions(Model $user): Collection
    {
        return $this->permissions->effectivePermissions(
            subject: $user,
        );
    }
}
```

`effectivePermissions()` returns a `Collection` of `EffectivePermission`, and
`effectiveRoles()` returns a `Collection` of `EffectiveRole`. Each value
exposes exactly three readable fields — `$id`, `$name` and `$guardName` — and
carries no model, connection or lazy relation:

```php
use HangTuah\GateTrail\Access\EffectivePermission;

$names = $this->permissions->effectivePermissions(
    subject: $user,
)->map(
    static fn (EffectivePermission $permission): string => $permission->name,
)->all();
```

Behaviour to rely on:

- **Default deny.** A subject with no matching assignment is denied. There is
  no implicit super administrator and no explicit deny rule.
- **Bare strings are names.** `allows($user, '7')` asks about a permission
  *named* `7`; `allows($user, PermissionReference::id(7))` asks about
  *identifier* `7`.
- **Names match exactly.** Case and surrounding whitespace are significant.
- **Roles are resolved independently of permissions**, so a role granting no
  permissions is still reported by `RoleResolver`.
- **Output values are not inputs.** The resolvers do not accept
  `EffectivePermission` or `EffectiveRole`; a consumer-built lookalike is not
  authorization evidence.
- **Deactivated definitions are excluded** even when an assignment row still
  exists.
- Evaluation is memoized for the life of one container scope, and a mutation
  committed in the same process clears that memo.

### Subject convenience API

An Eloquent subject using `HasGateTrail` can call the same resolvers through
concise methods:

```php
$user->hasRole('reviewer');
$user->hasAnyRole(['reviewer', 'publisher']);
$user->hasAllRoles(['reviewer', RoleReference::id(7)]);
$user->hasPermissionTo('documents.approve');
$user->effectivePermissions();
$user->roles();
```

`hasAnyRole()` and `hasAllRoles()` evaluate one snapshot. An empty list denies
for both methods. `effectivePermissions()` returns `EffectivePermission`
values, while `roles()` returns effective `EffectiveRole` values.

Three read-only Eloquent relations expose stored state for administration and
history views:

```php
$user->directPermissions();
$user->roleAssignments();
$user->permissionAssignments();
```

The two assignment relations contain full history. `directPermissions()` is a
raw definition relation through all direct assignment rows, including rows
that are scheduled, expired or revoked. These relations are **not authorization
evidence**; use `hasPermissionTo()`, `effectivePermissions()` or Laravel Gate
when deciding access. Assignment changes still go through `AssignmentManager`.

### Laravel Gate and Blade

GateTrail registers one central Laravel Gate hook. An argument-free ability
whose exact name exists as a permission in the subject's guard is answered by
`PermissionResolver`:

```php
$user->can('documents.approve');
Gate::forUser($user)->allows('documents.approve');
Gate::authorize('documents.approve');
```

Laravel's native Blade directives use the same result:

```blade
@can('documents.approve')
    <button>Approve</button>
@endcan
```

If no matching GateTrail definition exists, Laravel continues to the
application's own Gate. Checks with one or more resource arguments bypass
GateTrail and continue to the application's gate or policy. A matching active
definition is authoritative, a deactivated matching definition denies, and
database or configuration failures remain visible.

### Route middleware

GateTrail automatically registers six aliases:

```text
gatetrail.permission
gatetrail.permission.any
gatetrail.permission.all
gatetrail.role
gatetrail.role.any
gatetrail.role.all
```

The singular alias requires exactly one name. `.any` permits the request when
at least one listed name is effective; `.all` requires every listed name:

```php
Route::post('/documents/{document}/publish', DocumentPublishController::class)
    ->middleware([
        'auth',
        'gatetrail.permission.all:documents.review,documents.publish',
        'gatetrail.role.any:publisher,administrator',
    ]);
```

Put Laravel's authentication middleware first. An unauthenticated request uses
Laravel's `AuthenticationException`; an authenticated denial uses Laravel's
`AuthorizationException` and normally renders as HTTP 403. Invalid or empty
route parameters raise `InvalidMiddlewareParameter`, which is a developer
configuration error rather than an authorization denial.

Names are trimmed, remain case-sensitive, and exact duplicates are removed.
Names containing commas cannot be expressed through route middleware; use a
resolver or Gate check for those definitions. Multiple middleware entries
compose as AND. GateTrail does not parse parentheses, negation, pattern expansion or
nested boolean expressions.

## 13. Assignment periods and lifecycle

An assignment moves through a small, explicit lifecycle:

```text
Scheduled → Active → Expired
                   └→ Revoked
```

`AssignmentPeriod` describes when an assignment applies. All three factories
normalise to UTC whole seconds:

```php
use DateTimeImmutable;
use HangTuah\GateTrail\AssignmentPeriod;

$now = AssignmentPeriod::immediate();

$untilYearEnd = AssignmentPeriod::until(
    expiresAt: new DateTimeImmutable('2026-12-31 17:00:00 UTC'),
);

$nextQuarter = AssignmentPeriod::between(
    startsAt: new DateTimeImmutable('2026-10-01 00:00:00 UTC'),
    expiresAt: new DateTimeImmutable('2027-01-01 00:00:00 UTC'),
);
```

**The period is half-open**, and that single sentence explains most of the
lifecycle behaviour. In plain language: *an assignment is live from the instant
it starts, right up to but not including the instant it expires.*

At an evaluation time `T`, an assignment is active when all three hold:

```text
it has not been revoked
it has already started:  starts_at ?? assigned_at  <=  T
it has not yet expired:  expires_at is null, or expires_at > T
```

Read that as the interval `[start, expiry)`:

| Situation | Active at `T`? | Why |
|---|:-:|---|
| `starts_at` equals `T` | yes | The start instant is included |
| `expires_at` equals `T` | **no** | The expiry instant is excluded |
| `expires_at` is one second after `T` | yes | Still inside the interval |
| `starts_at` is in the future | no | Scheduled, not yet active |
| `expires_at` is `null` | yes | No end; runs until revoked |
| `revoked_at` is set | no | Revocation wins over any future expiry |

Because expiry is exclusive, two assignments where one ends exactly when the
next begins produce no overlap and no gap.

Other rules:

- an assignment with no `starts_at` starts at `assigned_at`;
- `expires_at` must be strictly later than the effective start, or
  `AssignmentPeriod::between()` throws `InvalidAssignmentPeriod`;
- a persisted period is **immutable**. To change one, revoke the assignment and
  create a new one with the replacement period;
- GateTrail prevents duplicate equivalent *active* assignments using both
  transactional validation and a database-enforced coordination key. Under
  contention it retries a bounded number of times and then throws
  `ConcurrencyRetryExhausted`.

The three assignment models also inherit four supported query scopes —
`activeAt()`, `scheduledAt()`, `expiredAt()` and `revoked()` — for reporting
over the same lifecycle. `activeAt()` is what the resolver itself uses, so
overriding it in a custom model changes authorization semantics for everything.

## 14. Actors: human and system

Every mutation records **who** made it. The `actor` parameter accepts either a
persisted Eloquent model or a `SystemActor`.

Use a model when a person acted:

```php
public function assignReviewerAsAdministrator(
    Model $user,
    Model $administrator,
): void {
    $this->assignments->assignRole(
        subject: $user,
        role: 'content-reviewer',
        actor: $administrator,
        reason: 'Approved in change ticket OPS-4821',
        metadata: ['ticket' => 'OPS-4821'],
    );
}
```

Use `SystemActor::named()` when no person did — a seeder, a scheduled job, a
queue worker, a data migration, an identity-provider sync:

```php
use HangTuah\GateTrail\SystemActor;

$this->assignments->assignRole(
    subject: $user,
    role: 'content-reviewer',
    actor: SystemActor::named('directory-sync'),
    reason: 'Group membership synchronised from the directory',
);
```

`SystemActor` is a `final readonly` value with a private constructor: build it
only through `named()`. The name is trimmed, must be non-empty, and must not
exceed 191 characters — otherwise `InvalidActor` is thrown. Audit records store
its type as the constant `SystemActor::TYPE`, `gatetrail.system`, which keeps
automated actors distinguishable from human ones forever.

Give system actors stable, specific names. `SystemActor::named('script')` tells
an auditor nothing; `SystemActor::named('directory-sync')` tells them which
system to go and ask.

## 15. Audit modes

GateTrail writes an append-only audit record for every mutation. Two
configuration flags choose the mode, and the choice is an explicit operational
trade-off:

Persisted `AccessAudit` model instances reject Eloquent update, delete,
increment, decrement and quiet-operation variants. This protects the package
model API; it cannot prevent a privileged DBA or a direct query-builder write.
Control those paths through database privileges and an explicit retention
policy rather than treating application-level immutability as a database
security boundary.

```php
// config/gatetrail.php
return [
    'audit' => [
        'enabled' => true,
        'strict' => true,

        'metadata' => [
            'max_bytes' => 16384,
            'max_depth' => 8,
        ],
    ],
    // ...
];
```

| `enabled` | `strict` | Behaviour |
|:-:|:-:|---|
| `true` | `true` | **Strict** (default). Mutation and audit share one transaction. An audit failure rolls the mutation back and throws `AuditWriteFailed`. No mutation can exist without its audit record |
| `true` | `false` | **Non-strict.** The mutation commits first; the audit is written afterwards in its own transaction. A failure is logged once at error level, dispatches `AuditPersistenceFailed`, and returns a `null` audit identifier. It never throws after the commit and never retries the mutation |
| `false` | ignored | **Disabled.** No audit write is attempted. Mutations still validate, lock and persist, and every result carries a `null` audit identifier |

**Strict** is the default and the right choice unless you have a specific
reason to weaken it.

**Non-strict** trades history for availability: a mutation can succeed with no
audit evidence. Note that inside a caller-owned transaction the audit waits for
the outermost commit, so the immediate result also carries a `null` audit
identifier even when the write later succeeds.

**Disabled** is the strongest warning in the configuration file. Nothing
records who changed which role, permission or assignment, when, or why, and
nothing reconstructs that history later. Choose it only when another system
already records authorization changes.

GateTrail never changes mode by itself, including after a failure.

Validation is fail-closed and ordered. `enabled` must always be a real boolean;
when it is `true`, `strict` must also be a real boolean. A missing key, `null`,
the strings `"true"` or `"false"`, `0`, `1`, an array or an object is rejected
with `InvalidAuditConfiguration` before a mutation runs. When `enabled` is
`false`, `strict` is never consulted.

Two more audit rules worth knowing:

- consumer `metadata` is normalised and bounded by
  `audit.metadata.max_bytes` and `audit.metadata.max_depth`; exceeding either
  throws `InvalidAuditMetadata`;
- attribute names listed in `sensitive_fields` are redacted from snapshots, so
  passwords and tokens never reach the audit table.

## 16. MutationResult and MutationOutcome

Every successful managed mutation returns a `MutationResult`:

```php
$result = $this->assignments->assignRole(
    subject: $user,
    role: 'content-reviewer',
    actor: SystemActor::named('access-bootstrap'),
    reason: 'Joined the document review rota',
);

$model = $result->resource;   // the affected Eloquent model
$outcome = $result->outcome;  // a MutationOutcome case
$auditId = $result->auditId;  // int|string|null
$changed = $result->changed(); // false only when Unchanged
```

`MutationOutcome` is a string-backed enum. Both the case names and their
backing values are compatibility surface, because consumers persist and
transmit them:

| Case | Value | Means |
|---|---|---|
| `Created` | `created` | A new definition or assignment row was written |
| `Updated` | `updated` | An existing definition changed |
| `Deactivated` | `deactivated` | A definition was suspended globally |
| `Restored` | `restored` | A deactivated definition was reactivated |
| `Revoked` | `revoked` | An assignment was revoked |
| `Unchanged` | `unchanged` | The request was an idempotent no-op |

Interpreting the result:

- `changed()` is `false` **only** for `Unchanged`. Use it rather than comparing
  outcomes when all you need is "did anything happen".
- `Unchanged` means GateTrail wrote nothing: no duplicate row, no audit
  record, no event. It is a success, not a failure — an idempotent replay
  reaching a state that already holds.
- `auditId` is `null` in four situations, and only these: the outcome is
  `Unchanged`; auditing is disabled; a non-strict audit write failed; a
  non-strict audit is still pending after a caller-owned transaction. A `null`
  audit identifier is therefore **not** a reliable failure signal — read the
  outcome for that.
- `resource` is the configured model class, so it is your subclass when you
  replaced one.
- Do not construct a `MutationResult` yourself. Its constructor is `@internal`
  and exists so the package can build authentic results.

## 17. Listening to GateTrail events

GateTrail dispatches two events, both after the outermost successful commit,
and both implement `Contracts\GateTrailEvent`:

| Event | Meaning |
|---|---|
| `Events\MutationCommitted` | A mutation committed durably |
| `Events\AuditPersistenceFailed` | A non-strict audit write failed after its mutation had already committed |

Listening to the **interface** receives both:

```php
use HangTuah\GateTrail\Contracts\GateTrailEvent;
use HangTuah\GateTrail\Events\MutationCommitted;
use Psr\Log\LoggerInterface;

final readonly class RecordGateTrailActivity
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function handle(GateTrailEvent $event): void
    {
        // Safe on the interface: every GateTrail event declares context().
        $this->logger->info('gatetrail', $event->context());

        if (! $event instanceof MutationCommitted) {
            return;
        }

        // Event-specific fields are available only after narrowing.
        $this->logger->info($event->mutation, [
            'outcome' => $event->outcome->value,
            'audit_id' => $event->auditId,
        ]);
    }
}
```

Register it like any Laravel listener:

```php
use HangTuah\GateTrail\Contracts\GateTrailEvent;
use Illuminate\Support\Facades\Event;

Event::listen(GateTrailEvent::class, RecordGateTrailActivity::class);
```

The two rules that matter:

1. **`context()` is safe on the interface.** It is declared on
   `GateTrailEvent` and returns `array<string, mixed>`, so a listener that only
   logs or forwards never needs to know which event it received.
2. **`instanceof` narrowing is required for event-specific fields.** Reading
   `$event->mutation` or `$event->outcome` from the interface type is not
   available — those belong to `MutationCommitted`. A listener written before
   the operational event joined the interface will fail at runtime without a
   narrowing check.

The keys of `context()` are deliberately **event-specific** and are documented
per event in [`docs/PUBLIC_API.md`](PUBLIC_API.md); they are not promised to be
identical across events. Both payloads are safe by construction: no Eloquent
models, no snapshots, no reasons, no consumer metadata, no credentials, and no
exception messages or stack traces.

`MutationCommitted::$mutation` carries one of fourteen stable strings —
`role.assigned`, `role.revoked`, `role.created`, `role.updated`,
`role.deactivated`, `role.restored`, `permission.granted_to_subject`,
`permission.revoked_from_subject`, `permission.granted_to_role`,
`permission.revoked_from_role`, `permission.created`, `permission.updated`,
`permission.deactivated`, `permission.restored`. Compare against the **string**;
the class that holds those constants is internal.

## 18. Error handling and the public exception boundary

GateTrail raises typed exceptions as control flow. Twenty-one of them are Tier 1
supported types you may catch:

```php
use HangTuah\GateTrail\Exceptions\DuplicateRole;
use HangTuah\GateTrail\Exceptions\GuardMismatch;
use HangTuah\GateTrail\Exceptions\ReasonRequired;
use HangTuah\GateTrail\Exceptions\RoleNotFound;

public function createReviewerRoleSafely(): void
{
    try {
        $this->roles->create(
            name: 'content-reviewer',
            guard: 'web',
            actor: SystemActor::named('access-bootstrap'),
            reason: 'Create the document review role',
        );
    } catch (DuplicateRole) {
        // The (name, guard_name) pair already exists — including when the
        // existing definition is deactivated.
    } catch (GuardMismatch|RoleNotFound|ReasonRequired $failure) {
        report($failure);
    }
}
```

The ones you will meet most often:

| Exception | Raised when |
|---|---|
| `RoleNotFound`, `PermissionNotFound` | A name, identifier or model does not resolve to an active definition in the resolved guard |
| `DuplicateRole`, `DuplicatePermission` | `(name, guard_name)` already exists |
| `GuardMismatch` | A subject, role or permission crosses a guard boundary |
| `InvalidGuard` | The authoritative guard source holds an empty or non-string value |
| `InvalidSubject` | A subject cannot be resolved to a stable identity |
| `InvalidMiddlewareParameter` | A GateTrail route alias has empty or invalid-cardinality parameters |
| `ReasonRequired` | A required reason is missing or blank |
| `AssignmentNotActive` | Revoking a missing, expired or already-revoked assignment |
| `DuplicateActiveAssignment`, `AssignmentScopeCollision` | An equivalent active assignment already exists or races another writer |
| `ConcurrencyRetryExhausted` | Bounded retry gave up under contention |
| `InvalidAssignmentPeriod` | `expires_at` is not strictly after the effective start |
| `InvalidActor` | A `SystemActor` name is empty or longer than 191 characters |
| `AuditWriteFailed` | Strict auditing could not persist a record; the mutation rolled back |
| `InvalidAuditConfiguration`, `InvalidAuditMetadata` | Audit flags or consumer metadata are invalid |
| `InvalidModelConfiguration` | A `gatetrail.models.*` class fails its requirements |
| `InvalidConcurrencyConfiguration` | `assignments.concurrency_attempts` is invalid |
| `UnsupportedAfterCommitBoundary` | A caller-owned transaction cannot defer GateTrail's after-commit work until its outermost commit |

What the boundary promises, and what it does not:

- **The class name, its catchability and its parent class are stable.**
  Fifteen extend `RuntimeException` and eleven extend `InvalidArgumentException`;
  there is no shared GateTrail exception base, so you cannot catch GateTrail
  errors "by namespace".
- **Only explicitly documented readable fields are promised.**
  `ConcurrencyRetryExhausted` is the fully specified example, exposing
  `$operation`, `$attempts`, `$callerOwned`, `$driver`, `$category`,
  `$sqlState` and `$vendorCode`, with `getPrevious()` always `null`.
- **Diagnostics stay safe.** No exception carries raw SQL, bindings, reasons,
  metadata or credentials.
- **You catch these types; you do not construct them.** Their named factories
  exist for GateTrail to raise its own errors.
- **Six validation sites raise PHP's own `InvalidArgumentException`**, not a
  GateTrail subtype: `AssignmentReference::id()`, `RoleReference::id()` and
  `PermissionReference::id()` reject an empty string or one longer than 191
  characters; `RoleChanges::description()` and
  `PermissionChanges::description()` reject non-UTF-8 input; and
  `MutationResult::__construct()` rejects `MutationOutcome::Unchanged` paired
  with a non-null audit identifier.

Five further exceptions are **operational** rather than Tier 1 —
`DdlCompilerUnavailable`, `SchemaInspectorUnavailable`,
`UnsupportedDatabaseDriver`, `InvalidDatabaseIdentifier` and
`MigrationDependencyError`. They surface during installation, migration and
deployment, are catchable by a deployment pipeline, and move on a schema-release
stability clock rather than Tier 1's.

## 19. Public versus internal API

The package contains 219 classes. **158 of them carry a class-level
`@internal` annotation and are not consumer API**, whatever PHP visibility or
the container allows.

| Reachable how? | Supported? |
|---|---|
| PSR-4 can autoload it | No — true of all 219 |
| The container can build it | No — 73 of the 80 bindings target internal classes |
| Listed in `docs/PUBLIC_API.md` | **Yes** — this is the only level that carries a promise |

Practical rules:

- **Inject the five service contracts**, never the `Default*` classes behind
  them. `AssignmentManager`, `RoleManager` and `PermissionManager` are
  singletons; `PermissionResolver` and `RoleResolver` are **scoped**, and that
  lifecycle is itself part of the promise — it is what keeps one request's
  answers out of the next under Octane or a queue worker.
- **Do not use** commands, handlers, input resolvers, guard resolvers, subject
  identity resolvers, query builders, row mappers, memo internals, audit
  builders, concurrency classifiers, `ModelRegistry`, `SchemaContext`, schema
  compilers, catalog readers, inspectors or diagnostics probes. Every one of
  them is internal, and several are container-bound only for the package's own
  lifecycle control.
- **Read package-produced values; do not construct them.**
  `MutationResult`, `EffectivePermission`, `EffectiveRole`,
  `MutationCommitted`, `AuditPersistenceFailed` and the exceptions all have
  package-controlled construction. A `public static` factory marked
  `@internal` exists only because PHP has no package-private visibility.
- **Compare against emitted strings, not internal constants.** The fourteen
  mutation names and the six `MutationOutcome` values are surface; the classes
  holding related constants may not be.

### Supported testing seam

GateTrail resolves every time decision through `Psr\Clock\ClockInterface`, and
**rebinding that interface is supported**:

```php
use Psr\Clock\ClockInterface;

$this->app->instance(ClockInterface::class, $frozenClock);
```

That one binding controls the timestamp captured by all fourteen mutation
command factories and the evaluation time used by the effective-access
resolver, which makes scheduled and expiring assignments straightforward to
test. The concrete clock class behind the binding is internal and may be
replaced.

## 20. Current limitations and unimplemented features

Everything in this list is designed, planned or deferred. Do not build against
an item in this table, and do not assume a symbol is available because a
specification section describes it.

| Not available | Status |
|---|---|
| GateTrail-supplied resource policies | Applications continue to own resource and state rules |
| Custom GateTrail Blade directives | Not planned; native `@can` / `@cannot` use the implemented Gate integration |
| Other console commands, including `gatetrail:doctor`, `gatetrail:matrix` and `gatetrail:cache-clear` | Planned across pre-`1.0.0` releases; not started. `gatetrail:install` is the only command currently available |
| `UnauthorizedMutation` | Named in the specification for later Laravel-integration work; the class does not exist |
| Cross-request cache and cross-request invalidation APIs | Deferred to Phase 8 by ADR 0013. The post-commit ordering slot is reserved and empty; there is no cache store, TTL, binding or configuration key |
| Administration UI | Out of scope for `1.0.0` |
| Wildcard permissions | Not in the initial release |
| Role hierarchy or role inheritance | Not in the initial release |
| Tenant- or team-scoped roles | Not in the initial release |

Additional limitations of what *is* implemented:

- **PostgreSQL and SQL Server runtimes are unverified.** PostgreSQL has
  reproducible DBA artifacts and live PostgreSQL 14 schema-inspection evidence
  for both provisioning modes, but no live behavioural suite: nothing has
  mutated, resolved, audited or coordinated against it. SQL Server has query
  compilation, schema-adapter support, reproducible DBA artifacts and live
  SQL Server 2022 schema-level DDL evidence, but **no schema inspector** and no
  behavioural suite either.
- **Oracle's declared 12c Release 2 minimum has not been exercised live.**
  Current evidence comes from Oracle Free 23.26.1.
- **Schema inspection has no supported entry point.** The read-only inspectors
  exist and the package's own integration suite uses them, but they are
  internal classes and no supported command exposes them yet.
- **No explicit deny and no super administrator.** Authorization is default
  deny plus positive grants only.
- **Assignment periods are immutable once persisted.** Changing one means
  revoke and re-create.
- **Effective-access values carry no source attribution.** They do not say
  which role or direct grant produced a permission.
- **Role and permission names and guards are immutable.** Only descriptions can
  be updated.

## 21. Where to go next

- [`UPGRADING.md`](UPGRADING.md) — the pre-v1 upgrade workflow for
  package-managed and DBA-managed schemas, including configuration, rollback
  and post-upgrade verification.
- [`CONFIGURATION.md`](CONFIGURATION.md) — every supported configuration key,
  default, validation boundary and deployment consideration.
- [`docs/PUBLIC_API.md`](PUBLIC_API.md) — the authoritative Tier 1 and Tier 2
  manifest with exact signatures, readable fields, enum values and model
  requirements. Read this before depending on any symbol.
- [ADR 0014](decisions/0014-pre-v1-public-api-boundary.md) — why the boundary
  is drawn where it is, and the pre-`1.0.0` change policy.
- [ADR 0012](decisions/0012-effective-access-resolution.md) — how effective
  access is resolved, and the package-controlled output values.
- [ADR 0010](decisions/0010-after-commit-mutation-events.md) — after-commit
  event ordering and safe payloads.
- [ADR 0008](decisions/0008-audit-snapshot-and-failure-modes.md) — audit
  snapshots and failure modes.
- [ADR 0011](decisions/0011-bounded-concurrency-retry.md) — the concurrency
  failure contract.
- [ADR 0003](decisions/0003-subject-guard-resolution.md) — subject guard
  resolution in full.
- [`PACKAGE_SPEC.md`](../PACKAGE_SPEC.md) — the complete specification,
  including designed-but-unimplemented surfaces.
- [`ROADMAP.md`](../ROADMAP.md) — phase progress and verification evidence.
- [`CHANGELOG.md`](../CHANGELOG.md) — what changed, and any migration guidance.

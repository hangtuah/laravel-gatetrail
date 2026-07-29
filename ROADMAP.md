# GateTrail Roadmap and Implementation Guide

Last updated: 2026-07-29

## 1. Purpose

This document is the official implementation roadmap and progress tracker for
GateTrail.

GateTrail is developed as an independent Laravel package. Its design,
implementation, tests, releases, and documentation must not depend on a
specific consumer application.

This roadmap answers:

- what has been completed;
- where the project is now;
- what should happen next;
- which work must not start yet;
- how completion percentages are calculated;
- what evidence is required before a phase is marked complete.

## 2. Status legend

| Status | Meaning |
|---|---|
| Complete | Deliverables and exit criteria are satisfied |
| In progress | Work has started but exit criteria are not fully satisfied |
| Not started | No implementation evidence exists yet |
| Deferred | Deliberately postponed to a later phase |
| Optional | Not required for the initial stable release |

A design described in `PACKAGE_SPEC.md` is not considered implemented until
working code and the required automated tests exist.

## 3. Current progress

### 3.1 Current position

```text
Phase 0 — Complete
Phase 1 — Complete
Phase 2 — Complete
Phase 3 — In progress
Phase 4 — In progress
Phase 5 onward — Not started
```

The package specification is accepted for implementation. Repository tooling
is complete, and database/lifecycle implementation remains in progress.

ADR numbers are decision-record numbers, not roadmap phase numbers. For
example, ADR 0004 does not mean the project has reached Phase 4.

### 3.2 Progress percentages

| Measurement | Current value | Interpretation |
|---|---:|---|
| Overall roadmap by primary phases | Approximately 28% | Phase-weighted progress across Phases 0–13 |
| Deliverable tracker | 11 of 32, approximately 34% | Completed concrete package deliverables |
| Planning and specification | 100% | Specification baseline is accepted for implementation |
| First sprint | 10 of 10, 100% | Repository-and-specification sprint tasks |

These percentages measure different things and must not be combined.

The overall percentage remains low because most work consists of implementation,
automated testing, security hardening, a pilot, and release validation.

### 3.3 Phase summary

| Phase | Name | Progress | Status |
|---:|---|---:|---|
| 0 | Ownership and product decisions | 100% | Complete |
| 1 | Package specification | 100% | Complete |
| 2 | Repository and development tooling | 100% | Complete |
| 3 | Database and assignment lifecycle | 80% | In progress |
| 4 | Mutation services | 93% | In progress |
| 5 | Effective permission resolver | 0% | Not started |
| 6 | Laravel integration | 0% | Not started |
| 7 | Audit trail | 0% | Not started |
| 8 | Cache and performance | 0% | Not started |
| 9 | Security hardening | 0% | Not started |
| 10 | Developer experience and documentation | 0% | Not started |
| 11 | Internal pilot | 0% | Not started |
| 12 | Existing-application integration | 0% | Not started |
| 13 | Stable release | 0% | Not started |
| 14 | Post-v1 optional features | — | Optional |

## 4. Completed foundation

The following product decisions are complete:

- Product name: GateTrail
- Composer package: `hangtuah/laravel-gatetrail`
- PHP namespace: `HangTuah\GateTrail`
- Repository: `hangtuah/laravel-gatetrail`
- Default branch: `main`
- Initial visibility: private
- Intended public license: MIT
- Owner and primary maintainer recorded
- Initial public description recorded

Completed documents:

- `PROJECT_CHARTER.md`
- `PACKAGE_SPEC.md`
- `docs/decisions/0001-unique-active-assignments.md`
- `docs/decisions/0002-public-mutation-api.md`
- `docs/decisions/0003-subject-guard-resolution.md`
- `docs/decisions/0004-middleware-any-all-syntax.md`
- `docs/decisions/0005-actor-identity-representation.md`
- `docs/decisions/0006-oracle-generated-key-strategy.md`
- `docs/decisions/0007-laravel-gate-hook-boundary.md`
- `docs/decisions/0008-audit-snapshot-and-failure-modes.md`
- `docs/decisions/0009-direct-database-mutation-and-diagnostics.md`

Accepted architecture decisions:

| ADR | Decision | Status |
|---|---|---|
| 0001 | Serialize equivalent assignment mutations through persistent coordination rows | Accepted |
| 0002 | Provide simple public manager methods and keep commands internal | Accepted |
| 0003 | Resolve one stable guard for each subject | Accepted |
| 0004 | Use explicit middleware aliases for singular, any, and all checks | Accepted |
| 0005 | Store human and system actor identities as portable strings | Accepted |
| 0006 | Support explicit Oracle identity and sequence-trigger generated-key strategies | Accepted |
| 0007 | Bound the Laravel Gate hook to argument-free abilities | Accepted |
| 0008 | Version audit snapshots and separate audit failure modes | Accepted |
| 0009 | Treat direct database mutation as unsupported and diagnostic-only | Accepted |

## 5. Roadmap adherence

GateTrail is still following this roadmap.

Intentional variations so far:

- the separate repository was created while specification work was still in
  progress;
- architecture decisions were documented in more detail than the minimum
  originally required;
- database portability requirements were defined before migrations were
  written.

These variations do not skip implementation evidence or move production
integration ahead of package foundations.

The project is now implementing Phase 3 rather than designing distant features
such as cache optimization.

## 6. Immediate action queue

Work should proceed in this order:

Completed in the current tooling increment:

- [x] Add code style and static analysis.
- [x] Add the initial CI workflow.
- [x] Verify Laravel package auto-discovery metadata.
- [x] Confirm the initial remote CI run.
- [x] Draw the initial ERD from the accepted schema.
- [x] Accept the migration-level constraint and index plan.
- [x] Implement the first versioned package migrations.
- [x] Verify migration and rollback behavior in the SQLite package suite.
- [x] Add the database-neutral schema manifest.
- [x] Implement the first vendor DBA DDL compiler from the canonical manifest.
- [x] Define the read-only schema-inspector contract.
- [x] Publish reproducible MySQL release `0001` install and rollback artifacts.
- [x] Implement strict read-only MySQL catalog inspection.
- [x] Verify MySQL 8.0.45 package-managed and DBA-managed paths on Laravel 12
  and Laravel 13.
- [x] Validate catalog normalization and fail-closed drift detection against
  live MySQL metadata.
- [x] Verify the minimum MySQL 8.0.16 endpoint locally on Laravel 12 and
  Laravel 13.
- [x] Add a four-lane MySQL 8.0.16/8.0.45 integration matrix to CI.
- [x] Confirm remote CI run `#15` passes all MySQL and Laravel matrix lanes.
- [x] Accept the Oracle generated-key strategy and ownership contract.
- [x] Implement the Oracle compiler core for both generated-key strategies.
- [x] Publish reproducible Oracle release `0001` artifacts for both
  generated-key strategies.
- [x] Implement strict read-only Oracle catalog inspection for both
  generated-key strategies.
- [x] Verify both Oracle generated-key strategies in package-managed and
  DBA-managed modes against a live disposable Oracle Free 23.26.1 endpoint on
  Laravel 12 and Laravel 13.
- [x] Add a cost-controlled Oracle compatibility workflow that runs both
  supported Laravel/OCI8 major lanes against one service container.

Next:

1. Continue the database and assignment-lifecycle implementation.
2. Build the first vertical slice from the accepted specification.
3. Obtain minimum-version evidence before declaring Oracle fully verified.

Do not begin the first vertical slice until the Phase 2 exit criteria are met.

## 7. Phase 0 — Ownership and product decisions

### Objective

Establish package identity, ownership, intended users, license, and repository
direction before implementation.

### Checklist

- [x] Package name selected.
- [x] Composer vendor selected.
- [x] Namespace selected.
- [x] Ownership recorded.
- [x] Repository visibility decided.
- [x] Maintainer identified.
- [x] Intended users stated.
- [x] License selected.
- [x] Project charter created.

### Exit criteria

- [x] Package identity is independent of a consumer application.
- [x] Ownership and release responsibility are explicit.
- [x] `PROJECT_CHARTER.md` is committed.

Status: **Complete**

## 8. Phase 1 — Freeze the package specification

### Objective

Define authorization behavior before implementation so behavior does not change
to match one consumer application.

### Completed

- [x] Effective-permission formula defined.
- [x] Direct permission behavior defined.
- [x] Default-deny behavior defined.
- [x] Role and permission lifecycle defined.
- [x] Assignment scheduling, expiry, and revocation defined.
- [x] Null-start assignment periods aligned with `assigned_at`.
- [x] Revoke and deactivate terminology separated.
- [x] Public mutation API direction accepted.
- [x] Mutable definition fields and immutable assignment periods accepted.
- [x] Subject guard behavior accepted.
- [x] Cross-guard authorization-check behavior accepted.
- [x] Middleware any/all syntax accepted.
- [x] Shared role-check resolver behavior accepted.
- [x] Laravel Gate hook and resource-policy boundary accepted.
- [x] Persisted human and system actor identity accepted.
- [x] Audit snapshot, metadata, and failure-mode contract accepted.
- [x] Direct database mutation and diagnostic behavior accepted.
- [x] Deactivated-definition namespace behavior accepted.
- [x] Database compatibility targets recorded.
- [x] Identifier and 30-character database-name rules recorded.
- [x] Initial feature scope and non-goals recorded.
- [x] First vertical slice defined.

### Remaining decisions

The following decisions remain open in `PACKAGE_SPEC.md`:

- [ ] Cache store, TTL, and role-change fan-out strategy.
- [ ] Whether first-class UUID/ULID migrations are required before `1.0.0`.
- [ ] Whether role convenience directives belong in the core package.

Not all decisions block repository tooling.

No remaining open architecture decision currently blocks the first vertical
slice. Cache strategy should wait until the uncached resolver exists and is
measured.

### Remaining review

- [x] An independent specification review brief and structured response
  template are available at
  `docs/reviews/0001-specification-review-brief.md`.
- [x] One independent developer reviews the charter and package specification.
- [x] Review feedback is recorded in
  `docs/reviews/0001-specification-review-disposition.md`.
- [x] Accepted review findings are resolved or explicitly deferred.
- [x] Specification status is accepted when freeze criteria are met.

### Exit criteria

- Two developers can independently reach the same authorization decision for
  every documented example.
- All decisions required by the first vertical slice are accepted.
- Compatibility, lifecycle, public API, error behavior, and non-goals are
  unambiguous.

Status: **Complete**

## 9. Phase 2 — Repository and development tooling

### Objective

Produce a fresh-clone package that installs, boots, and runs tests without a
consumer application.

### Repository

- [x] Separate repository created.
- [x] Default branch and remote configured.
- [x] Current documentation commits pushed.

### Required initial structure

```text
laravel-gatetrail/
├── src/
│   └── GateTrailServiceProvider.php
├── config/
│   └── gatetrail.php
├── database/
│   └── migrations/
├── tests/
│   ├── TestCase.php
│   └── PackageBootTest.php
├── docs/
│   └── decisions/
├── composer.json
├── phpunit.xml
├── README.md
├── CHANGELOG.md
├── LICENSE
├── PACKAGE_SPEC.md
├── PROJECT_CHARTER.md
└── ROADMAP.md
```

### Composer scaffold

The initial `composer.json` must define:

- package name `hangtuah/laravel-gatetrail`;
- type `library`;
- license `MIT`;
- PSR-4 namespace `HangTuah\GateTrail\`;
- test namespace `HangTuah\GateTrail\Tests\`;
- supported PHP and Illuminate versions from `PACKAGE_SPEC.md`;
- Laravel service-provider auto-discovery;
- validation, test, format, and static-analysis scripts.

The Oracle integration driver remains an integration dependency and must not
force OCI8 installation on non-Oracle consumers.

### Testbench

The Testbench base test case must:

- boot the GateTrail service provider;
- load package configuration;
- use an isolated test database;
- provide a test subject model;
- permit package migrations to be loaded in later phases.

The first smoke test proves:

```text
Fresh clone
→ composer install
→ package service provider boots
→ configuration is available
→ test passes
```

### Quality tooling

Required:

- PHPUnit or Pest test runner;
- Laravel Pint;
- PHPStan or Larastan;
- `composer validate`;
- a CI workflow.

Initial CI jobs:

```text
composer validate
code style
static analysis
test
```

The first CI matrix may use SQLite for package-boot tests. A database is not
advertised as production-supported until its required integration suite exists
and passes.

### Checklist

- [x] Composer autoload succeeds.
- [x] Service-provider auto-discovery metadata is verified.
- [x] Testbench boots.
- [x] Package smoke test passes.
- [x] CI is configured for pushes and pull requests.
- [x] `composer validate` passes.
- [x] Code formatting is configured.
- [x] Static analysis is configured at the maximum analysis level.

### Current verification evidence

- The full local quality suite passes on PHP 8.3 and Laravel 13.
- The package test suite passes on PHP 8.3, Laravel 12, and Testbench 10 in an
  isolated dependency installation.
- The CI matrix covers Laravel 12/Testbench 10 and Laravel 13/Testbench 11.
- Composer validation does not require a lock file because GateTrail is a
  library package.
- A reusable Eloquent test subject persists through the isolated SQLite
  connection.
- Package provisioning registers GateTrail migrations, while DBA provisioning
  leaves the package migration path unregistered.
- The initial remote CI run passes.

### Exit criteria

A fresh clone can install dependencies and run all initial quality checks
without a consumer application.

Status: **Complete**

## 10. Phase 3 — Database and assignment lifecycle

### Objective

Implement the portable schema and history-preserving assignment lifecycle.

### Deliverables

- [x] ERD.
- [x] Database-neutral schema manifest.
- [x] Versioned package migrations.
- [ ] Versioned DBA-managed DDL artifacts for every Tier 1 database.
- [x] Models and relationships.
- [x] Explicit indexes, foreign keys, and constraints in the SQLite reference schema.
- [x] `acl_assignment_locks` coordination model or query component.
- [x] Assignment period scopes.
- [x] Soft deletion for roles and permissions.
- [x] No normal hard deletion or cascading deletion of assignments.

### Default tables

```text
acl_roles
acl_permissions
acl_role_assignments
acl_permission_assignments
acl_role_permissions
acl_assignment_locks
acl_access_audits
```

### Required tests

- migration and rollback;
- relationship behavior;
- active, scheduled, expired, and revoked scopes;
- adjacent and overlapping periods;
- duplicate active-assignment concurrency;
- portable identifiers and explicit constraint names;
- Oracle sequence/trigger behavior;
- Oracle identity behavior;
- SQL Server identity behavior;
- schema-qualified tables;
- audit text/CLOB casts.

### Current verification evidence

- Seven migrations encode parent-before-child creation order.
- Configurable Eloquent models map the accepted role, permission, assignment,
  and audit tables through the same connection, schema, prefix, and table
  configuration used by migrations.
- Role, permission, subject, and history-preserving assignment relationships
  are covered by integration tests.
- Assignment relationships retain access to soft-deleted role and permission
  definitions without deleting or rewriting assignment history.
- Assignment period scopes consistently expose active, scheduled, expired, and
  revoked lifecycle states across all three assignment models. Boundary tests
  confirm that the start time is inclusive, the expiry time is exclusive, and
  `assigned_at` is used when `starts_at` is omitted.
- Canonical assignment scopes use versioned, length-prefixed keys and lowercase
  SHA-256 hashes with stable golden vectors. The internal coordination query
  component requires an existing transaction, acquires pessimistic row locks,
  preserves one row per equivalent scope, honors configured table context, and
  fails closed on a stored-key mismatch.
- The immutable public assignment-period value object normalizes timestamps to
  UTC whole seconds. Its internal effective-period representation applies the
  `starts_at ?? assigned_at` fallback, validates expiry ordering, and shares one
  half-open overlap evaluator for adjacent, bounded, and open-ended periods.
- The transaction-bound assignment-conflict query locks and returns equivalent
  non-revoked assignments whose historical periods overlap a candidate. It
  supports all assignment kinds, excludes revoked and unrelated scopes, allows
  adjacent boundaries, and retains domain-level overlap verification after its
  portable database prefilter.
- Assignment and audit timestamps use immutable datetime casts. Audit
  `before`, `after`, and `context` documents cast from portable text/CLOB
  storage.
- Reverse rollback removes child tables before parent tables.
- Missing parents fail with a GateTrail dependency error before child DDL.
- SQLite enforces assignment-period, revocation-tuple, audit-pair, uniqueness,
  and non-cascading foreign-key rules.
- Configured table prefixes are exercised by the migration suite.
- The full suite passes on Laravel 12/Testbench 10 and Laravel 13/Testbench 11.
- Manifest conformance tests compare migrated columns, nullability, indexes,
  dependency order, and rollback order with the canonical schema contract.
- The MySQL compiler generates dependency-ordered install and reverse-order
  rollback DDL directly from the canonical manifest.
- The Oracle compiler maps the same canonical manifest to identity or explicit
  sequence-trigger generated keys without mixing strategies in one schema.
- Oracle install compilation creates parent tables before children and emits
  indexes as separate Oracle statements. Sequence-trigger rollback removes
  triggers before reverse-ordered tables and sequences.
- Oracle release `0001` publishes distinct identity and sequence-trigger
  install and rollback artifacts with byte-for-byte compiler conformance tests
  and DBA operational guidance.
- The Oracle catalog reader uses accessible `ALL_*` views and performs only
  read queries. Its strict inspector validates canonical relational metadata,
  identity semantics, configured sequence-trigger objects, enabled object
  state, and mixed-strategy drift without repairing the schema.
- The isolated Oracle integration runtime keeps OCI8 and Laravel-OCI8 outside
  the package's normal dependency graph. A local disposable Oracle Free
  23.26.1 endpoint passes package-managed and DBA-managed provisioning for both
  generated-key strategies on Laravel 12/Testbench 10/Laravel-OCI8 12 and
  Laravel 13/Testbench 11/Laravel-OCI8 13. Each major lane passes 5 tests and
  107 assertions.
- Live Oracle evidence covers release `0001` artifact installation, read-only
  inspection, identity- and trigger-generated IDs, preservation of explicit
  IDs, transaction rollback, non-repairing missing-index detection, Laravel
  migration rollback, and artifact rollback.
- Package-managed Oracle migrations retain Laravel migration tracking while
  executing dependency-ordered, one-table slices of the canonical Oracle
  compiler. Non-Oracle migrations retain their existing Schema Builder path.
- The dedicated Oracle compatibility workflow runs the Laravel 13 reference
  lock and a temporary Laravel 12 compatibility checkout sequentially against
  one Oracle service container. GitHub Actions run `Oracle compatibility #1`
  for commit `882af5a` passes both supported major lanes. The accompanying
  primary workflow run `CI #25` also passes for the same commit.
- MySQL release `0001` provides committed install and rollback artifacts with
  byte-for-byte compiler conformance tests and DBA operational guidance.
- The read-only inspection contract reports schema differences without mutating
  DBA-managed objects.
- The MySQL catalog inspector compares tables, columns, indexes, constraints,
  referential rules, and check expressions using read queries only.
- MySQL 8.0.45 live integration passes for package-managed and DBA-managed
  provisioning on Laravel 12/Testbench 10 and Laravel 13/Testbench 11.
- MySQL 8.0.16 minimum-version integration passes on the same two Laravel
  lanes, including its legacy `ISNULL(column)` catalog representation.
- GitHub Actions run `#15` for commit `4a749d9` passes all four MySQL
  integration lanes across versions 8.0.16 and 8.0.45 and both supported
  Laravel majors.
- Live drift tests confirm missing indexes, changed exact-name collation, and
  cascading foreign keys are reported without automatic repair.

Production database adapters are not marked verified until their integration
suites pass. The MySQL minimum and current 8.0 endpoints are verified locally
and in the remote CI matrix. All four Oracle provisioning and generated-key
combinations now have current-version local evidence across both supported
Laravel majors, but the remote Oracle CI matrix and minimum Oracle version
remain open. The remaining Tier 1 compilers and inspectors also remain open.

ADR 0006 fixes the Oracle generated-key contract before adapter implementation:
one selected strategy per schema, `sequence_trigger` as the initial default,
distinct versioned artifacts for both supported strategies, and fail-closed
read-only inspection. Current live evidence verifies both strategies in both
provisioning modes but does not yet establish complete Oracle support.

### Exit criteria

Every supported database adapter can represent the accepted schema and the
assignment lifecycle behaves consistently in automated tests.

Status: **In progress**

## 11. Phase 4 — Mutation services

### Objective

Implement every state-changing operation through the accepted public managers.

### Deliverables

- [ ] `RoleManager`.
- [ ] `PermissionManager`.
- [x] `AssignmentManager`.
- [ ] Thin default manager adapters.
- [ ] Internal immutable commands.
- [ ] One internal handler per mutation use case.
- [ ] Typed domain exceptions.
- [x] Mutation results and outcomes.
- [ ] After-commit cache and event hooks.

### Required strict-audit flow

```text
Validate
→ Begin or join transaction
→ Resolve references
→ Acquire coordination lock
→ Persist mutation
→ Persist audit
→ Commit
→ Invalidate cache
→ Dispatch event
```

Non-strict auditing commits the mutation before attempting audit persistence
in a separate transaction. When joining a caller-owned transaction, it waits
for the outermost commit as defined by ADR 0008. Disabled auditing skips the
audit write.

### Required tests

- actor and reason rules;
- idempotent assignment replay;
- typed exceptions;
- transaction rollback;
- audit rollback in strict mode;
- non-strict audit success, failure, and caller-owned transaction behavior;
- disabled audit behavior;
- after-commit event behavior;
- bounded concurrency retry;
- no public `attach()` or `detach()` mutation path.

### Current verification evidence

- The initial mutation decision layer distinguishes create, unchanged, and
  conflict outcomes without writing state.
- Immediate replay returns one currently active equivalent assignment as
  unchanged. Scheduled overlap, future-only overlap, and ambiguous multiple
  conflicts fail closed as conflict decisions.
- Public generic mutation results expose the affected model, a typed successful
  outcome, and a nullable audit identifier. Unchanged results reject audit
  identifiers and report no state change. Duplicate active assignments have a
  typed exception containing only safe hashed scope context.
- Human and system actors normalize to immutable portable string identities
  without implicit authentication lookup. Numeric, UUID, ULID, reserved-alias,
  missing-key, and system-name boundaries are covered. Assignment reasons are
  trimmed and enforce the secure-by-default configurable requirement before a
  mutation transaction begins.
- The package binds a PSR-20 clock with UTC whole-second output. The first
  internal immutable command captures one normalized request timestamp for
  deterministic transaction retries and preserves explicit role references,
  periods, actors, reasons, and copied metadata without performing queries.
- Central subject-guard resolution follows the accepted method, property,
  exact-class configuration, package-default, and Laravel-default precedence
  without global authentication state. Transaction-bound role input resolution
  distinguishes names from IDs, enforces exact guards, rejects missing and
  deactivated definitions, and returns typed safe-context failures.
- Consumer metadata is normalized before mutation transactions as a bounded
  JSON object with nested-list support, finite depth and size, UTF-8 and
  recursion checks, reserved-marker protection, and deterministic JSON.
  Sensitive bare keys and exact dotted paths redact values only after the
  original normalized size passes validation.
- Strict mutation orchestration begins or joins the configured GateTrail
  connection transaction. Its transaction-bound audit writer persists
  canonical JSON without cast-driven re-encoding, returns the generated audit
  identifier, wraps persistence failures safely, and proves that an audit
  failure or caller-owned outer rollback removes both mutation and audit
  writes.
- Canonical role-assignment snapshots reload normalized persisted values,
  preserve the complete version 1 key order, serialize numeric identifiers as
  decimal strings, and normalize timestamps to UTC with microseconds. The
  role-assignment context builder preserves its required key order, embeds
  normalized metadata as an object, and retains JSON value types.
- The first strict `AssignRoleHandler` validates inputs before opening its
  transaction, resolves guarded roles, coordinates equivalent scopes, returns
  idempotent immediate replays unchanged, rejects scheduled overlap, persists
  role assignments and canonical `role.assigned` audits atomically, and
  redacts configured assignment and dedicated audit reasons. Stable logical
  audit-target aliases now replace PHP class names in public audit data.
- The container-bound public `AssignmentManager` now exposes the implemented
  `assignRole` slice through a thin default adapter. It supplies the immediate
  period default, captures the package clock once, delegates all behavior to
  the internal handler, and exposes no placeholder methods.
- The strict role-revocation foundation resolves model, reference, string-ID,
  and integer-ID inputs under a row lock. It allows cancellation of unrevoked
  scheduled access, rejects missing, expired, and already-revoked assignments,
  persists the complete revocation tuple, and records canonical redacted
  `role.revoked` before/after snapshots atomically.
- The public `AssignmentManager` now exposes both implemented role-assignment
  mutations. Its thin default `revokeRole` adapter captures the package clock,
  preserves explicit assignment references, actor, reason, and metadata, and
  delegates entirely to the strict internal revocation handler.
- Strict direct-permission grant foundations now resolve guarded permission
  inputs, coordinate subject-permission periods, return idempotent replays,
  reject overlaps, and atomically persist canonical
  `permission.granted_to_subject` audit evidence.
- Direct-permission revocation now locks the target assignment, rejects
  non-active rows, persists canonical before/after audit evidence, and is
  exposed together with direct grants through the thin public manager.
- Role-permission revocation now completes the six accepted assignment
  mutations. It locks the target role-permission row, rejects missing,
  expired, and already-revoked assignments, persists the complete revocation
  tuple, and records canonical `permission.revoked_from_role` before/after
  evidence atomically through the thin public manager.
- The first role-definition slice exposes strict `RoleManager::create()`
  through a thin public adapter. It validates trimmed, bounded UTF-8 names
  and guards before the transaction, honors the configurable definition
  reason requirement, rejects active and deactivated `(name, guard_name)`
  collisions as `DuplicateRole`, translates concurrent unique-constraint
  races through a narrow portable classifier, and persists canonical
  `role.created` audit evidence atomically. Update, deactivate, and restore
  remain unimplemented, so the incremental `RoleManager` contract currently
  exposes `create()` only.

### Pre-v1 assignment-manager contract gate

The current source contract is intentionally incremental while GateTrail
remains unreleased:

- all six assignment mutations accepted by ADR 0002 are now implemented,
  bound, and tested;
- the contract may still change before the public API freeze;
- custom `AssignmentManager` implementations are therefore not stable yet;
- the API freeze and `v1.0.0` release remain blocked until the accepted
  methods are documented for consumers.

### Exit criteria

Every supported mutation uses one managed path and produces deterministic,
audited, transactional behavior.

Status: **In progress**

## 12. Phase 5 — Effective permission resolver

### Objective

Implement one source of truth for every GateTrail authorization decision.

### Deliverables

- [ ] `PermissionResolver` contract and implementation.
- [ ] Direct permission resolution.
- [ ] Role-derived permission resolution.
- [ ] Subject guard resolution.
- [ ] Lifecycle filtering.
- [ ] Effective permission collection.
- [ ] Request-level memoization.
- [ ] Query-count instrumentation.

### Required rules

- default deny;
- evaluate the supplied subject;
- never substitute the authenticated global user;
- apply guard, start, expiry, revoke, and deactivation filters;
- preserve parity between boolean checks and effective collections;
- avoid N+1 queries.

### Exit criteria

The complete resolver test matrix passes without Laravel Gate, middleware, or
cross-request caching.

Status: **Not started**

## 13. Phase 6 — Laravel integration

### Objective

Expose the resolver through Laravel conventions without duplicating its logic.

### Deliverables

- [ ] `HasGateTrail` concern.
- [ ] Subject convenience methods.
- [ ] Central Laravel Gate hook.
- [ ] `Gate::forUser()` behavior.
- [ ] Native `@can` behavior.
- [ ] Six route middleware aliases.
- [ ] Authentication and authorization exception behavior.
- [ ] Policy coexistence.

### Middleware aliases

```text
gatetrail.permission
gatetrail.permission.any
gatetrail.permission.all
gatetrail.role
gatetrail.role.any
gatetrail.role.all
```

### Exit criteria

`$user->can()`, `Gate::authorize()`, `Gate::forUser()`, Blade, policies, and
GateTrail middleware all use the same underlying authorization result.

Status: **Not started**

## 14. Phase 7 — Audit trail

### Objective

Persist complete, safe, append-only records for managed authorization
mutations.

### Deliverables

- [ ] Human actor persistence.
- [ ] System actor persistence.
- [ ] Reason enforcement.
- [ ] Before and after snapshots.
- [ ] Safe contextual metadata.
- [ ] Sensitive-field filtering.
- [ ] Append-only enforcement.
- [ ] Strict audit mode.
- [ ] Audit transaction tests.

### Exit criteria

Every managed mutation is attributable and reconstructable without exposing
configured sensitive values.

Status: **Not started**

## 15. Phase 8 — Cache and performance

### Objective

Optimize a correct, measured resolver without changing authorization behavior.

### Sequence

1. Measure uncached resolver queries and latency.
2. Keep request-level memoization.
3. Decide whether cross-request cache is necessary.
4. Record the cache strategy in an ADR.
5. Implement deterministic invalidation.
6. Ensure entries do not outlive relevant assignment expiry.
7. Compare cached and uncached authorization parity.

### Deliverables

- [ ] Reproducible benchmark.
- [ ] Cache ADR, if cross-request caching is retained.
- [ ] Guard-aware subject cache keys.
- [ ] Invalidation matrix.
- [ ] Expiry-safe TTL.
- [ ] Stampede and stale-access tests where applicable.

### Exit criteria

Every performance claim has a reproducible benchmark and caching cannot grant
access beyond uncached resolver behavior.

Status: **Not started**

## 16. Phase 9 — Security hardening

### Objective

Review abuse paths and prove that secure defaults fail closed.

### Deliverables

- [ ] Threat model.
- [ ] Mutation authorization guidance or hook.
- [ ] SQL injection regression tests.
- [ ] Cross-subject and cross-guard tests.
- [ ] Concurrency and retry tests.
- [ ] Audit tampering review.
- [ ] Sensitive audit filtering tests.
- [ ] Alias-collision diagnostics.
- [ ] Security reporting policy.
- [ ] Static-analysis baseline with no unexplained high-risk findings.

### Exit criteria

No known critical or high-severity issue remains and every security default is
documented and tested.

Status: **Not started**

## 17. Phase 10 — Developer experience and documentation

### Objective

Make installation, correct usage, diagnostics, and extension points
understandable without reading package internals.

### Deliverables

- [ ] Complete README.
- [ ] Installation guide.
- [ ] Configuration reference.
- [ ] Public manager API guide.
- [ ] Role and permission naming guide.
- [ ] Lifecycle and audit guide.
- [ ] DBA-managed installation guides for every Tier 1 database.
- [ ] SQL Server setup guide.
- [ ] Upgrade and migration guides.
- [ ] `gatetrail:install`.
- [ ] `gatetrail:doctor`.
- [ ] `gatetrail:matrix`.
- [ ] Minimal example application.

### Exit criteria

A developer unfamiliar with the package can install it in a fresh Laravel
application, run diagnostics, assign access, authorize a route, and understand
the resulting audit records.

Status: **Not started**

## 18. Phase 11 — Internal pilot

### Objective

Validate GateTrail in a controlled application before any stable or public
release claim.

### Pilot rules

- use an application that can tolerate package iteration;
- integrate through the package's public API only;
- do not copy internal classes into the application;
- record every workaround as package feedback;
- measure query count, cache behavior, developer usability, and operational
  diagnostics.

### Deliverables

- [ ] Pre-release package tag.
- [ ] Private Composer installation path.
- [ ] Pilot test plan.
- [ ] Pilot defect log.
- [ ] Performance observations.
- [ ] Developer feedback.
- [ ] Pilot retrospective.

### Exit criteria

The pilot uses GateTrail without application-specific changes inside the
package, and all release-blocking findings are resolved.

Status: **Not started**

## 19. Phase 12 — Existing-application integration

### Objective

Provide a controlled path for applications with existing authorization data or
behavior.

### Sequence

1. Inventory existing roles, permissions, assignments, guards, and audit data.
2. Map semantic differences.
3. Build an adapter or migration tool outside the core resolver where
   practical.
4. Run shadow authorization comparisons.
5. Investigate every mismatch.
6. Plan rollback before switching enforcement.

### Deliverables

- [ ] Inventory template.
- [ ] Mapping guide.
- [ ] Adapter or migration strategy.
- [ ] Shadow comparison report.
- [ ] Consumer change-control plan.
- [ ] Rollback plan.

### Exit criteria

Authorization parity or explicitly approved differences are demonstrated before
GateTrail becomes the enforcement source.

Status: **Not started**

## 20. Phase 13 — Stable release

### Objective

Release `1.0.0` only after implementation, security, compatibility,
documentation, and pilot evidence are complete.

### Code and quality

- [ ] Initial feature scope complete.
- [ ] Full test suite passes.
- [ ] Code style passes.
- [ ] Static analysis passes.
- [ ] Database matrix passes.
- [ ] Concurrency suite passes.
- [ ] Reproducible performance results recorded.

### Security

- [ ] Threat model reviewed.
- [ ] Security review complete.
- [ ] No known critical or high-severity issue.
- [ ] Security reporting channel available.
- [ ] Strict audit mode tested.

### Documentation

- [ ] README complete.
- [ ] Fresh-install guide verified.
- [ ] Configuration reference complete.
- [ ] Upgrade guide available.
- [ ] Migration guide available.
- [ ] API examples tested.
- [ ] Changelog complete.

### Release operations

- [ ] Maintainer and release approver confirmed.
- [ ] Semantic versioning policy followed.
- [ ] Supported compatibility matrix published.
- [ ] Rollback version and procedure known.
- [ ] Release tag created.
- [ ] Repository visibility decision revisited.
- [ ] Public package publication decision made.

### Exit criteria

A fresh consumer can install the tagged package and reproduce all advertised
behavior using the documented compatibility matrix.

Status: **Not started**

## 21. Phase 14 — Optional features after v1

These features are not part of the initial stable-release commitment:

- explainable access;
- point-in-time authorization queries;
- temporary-access management UI;
- access request and approval workflows;
- separation of duties;
- role hierarchy;
- team or tenant scope;
- administration UI;
- wildcard permissions;
- explicit deny;
- super-administrator bypass;
- audit-retention automation.

Each feature requires a new specification and architecture review before
implementation.

Status: **Optional**

## 22. First sprint tracker

The first sprint proves that the repository and specification are solid.

- [x] Select package name.
- [x] Create separate repository.
- [x] Write `PROJECT_CHARTER.md`.
- [x] Write `PACKAGE_SPEC.md`.
- [x] Set up Composer and namespace.
- [x] Set up Orchestra Testbench.
- [x] Set up CI.
- [x] Add service-provider smoke test.
- [x] Draw initial ERD.
- [x] Obtain one independent developer review.

Current result: **10 of 10 tasks, 100%**

### First sprint deliverable

```text
Fresh clone
→ composer install
→ quality checks
→ tests
→ package boots successfully
```

### Do not do in the first sprint

- build an administration UI;
- integrate a production application;
- migrate production data;
- add teams, hierarchy, or wildcard permissions;
- publish a public package release;
- optimize cross-request caching before the resolver exists.

## 23. First vertical slice

After Phase 2, build one end-to-end flow:

```text
Create role
→ Create permission
→ Grant permission to role with actor and reason
→ Assign role to test subject with actor and reason
→ Laravel Gate allows the permission
→ Revoke the role assignment
→ Laravel Gate denies the permission
→ Assignment history remains
→ Audit records contain actor and reason
```

The vertical slice is complete only when:

- migrations run;
- model relationships work;
- public mutation managers are used;
- assignment coordination prevents duplicates;
- the resolver returns the expected result;
- Laravel Gate uses the resolver;
- audit records persist in the same mutation transaction under the default
  strict audit mode;
- revoke does not hard delete;
- automated integration tests pass.

## 24. Deliverable progress tracker

This tracker measures completed package artifacts and implementation, not design
discussion.

### Foundation

- [x] Project charter
- [x] Package specification
- [x] Repository
- [x] Composer
- [x] Testbench
- [x] CI
- [x] Code style
- [x] Static analysis

Progress: **8 of 8**

### Core implementation

- [x] Migrations
- [x] Models
- [ ] Contracts
- [ ] Assignment lifecycle
- [ ] Mutation services
- [ ] Effective permission resolver
- [ ] Gate integration
- [ ] Middleware
- [ ] Audit trail
- [ ] Cache invalidation

Progress: **3 of 10**

### Quality

- [ ] Unit tests
- [ ] Integration tests
- [ ] Security tests
- [ ] Concurrency tests
- [ ] Verified compatibility matrix
- [ ] Performance benchmark

Progress: **0 of 6**

### Delivery

- [ ] Complete README
- [ ] Full user documentation
- [ ] Artisan diagnostics
- [ ] Internal pilot
- [ ] Pilot retrospective
- [ ] Existing-application parity study
- [ ] Security review
- [ ] `v1.0.0`

Progress: **0 of 8**

### Total

```text
Completed: 11
Total:     32
Progress:  34.375%, reported as approximately 34%
```

## 25. Progress update rules

When work is completed:

1. update the relevant checklist;
2. add evidence such as a commit, test, CI run, or release tag;
3. recalculate the affected phase;
4. recalculate the first sprint or deliverable tracker when applicable;
5. update the `Last updated` date;
6. do not mark design-only work as implemented.

A phase reaches 100% only when its exit criteria are satisfied.

## 26. Definition of success

GateTrail is successful when:

- its authorization decisions are deterministic;
- its mutation history is attributable and preserved;
- its public API is simple to use correctly;
- its internal design remains testable and replaceable;
- it behaves consistently across advertised databases;
- it can be installed without knowledge of a specific consumer application;
- its secure defaults fail closed;
- its documentation is sufficient for a first-time maintainer;
- its stable-release claims are backed by reproducible evidence.

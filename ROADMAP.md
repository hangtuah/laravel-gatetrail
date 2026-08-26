# GateTrail Roadmap and Implementation Guide

Last updated: 2026-08-26

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
Phase 5 — Complete
Phase 6 onward — Not started
```

The package specification is accepted for implementation. Repository tooling
is complete, and database/lifecycle implementation remains in progress.

ADR numbers are decision-record numbers, not roadmap phase numbers. For
example, ADR 0004 does not mean the project has reached Phase 4.

### 3.2 Progress percentages

| Measurement | Current value | Interpretation |
|---|---:|---|
| Overall roadmap by primary phases | approximately 41% | Equal-phase progress across Phases 0–13 |
| Deliverable tracker | 12 of 32, approximately 38% | Completed concrete package deliverables |
| Planning and specification | 100% | Specification baseline is accepted for implementation |
| First sprint | 10 of 10, 100% | Repository-and-specification sprint tasks |

These percentages measure different things and must not be combined.

Phase percentages use an explicit atomic completion checklist. Every atomic
item is binary:

```text
0%   = acceptance criteria are not yet satisfied
100% = acceptance criteria and implementation evidence are complete
```

Shared infrastructure is included in the acceptance criteria of the mutation
or capability that uses it and is not counted again as separate progress.
This prevents commands, handlers, tests, and audit plumbing for one use case
from inflating the phase percentage through repeated counting.

The overall roadmap gives each primary phase from Phase 0 through Phase 13
equal weight:

```text
Overall progress = sum of Phase 0–13 percentages / 14
```

Phase 14 is optional and excluded. Summary percentages are rounded to the
nearest whole number. The deliverable tracker remains a separate artifact
count and does not contribute to phase or overall percentages.

The overall percentage remains low because most work consists of implementation,
automated testing, security hardening, a pilot, and release validation.

### 3.3 Phase summary

| Phase | Name | Progress | Status |
|---:|---|---:|---|
| 0 | Ownership and product decisions | 100% | Complete |
| 1 | Package specification | 100% | Complete |
| 2 | Repository and development tooling | 100% | Complete |
| 3 | Database and assignment lifecycle | 80% | In progress |
| 4 | Mutation services | 95% | In progress |
| 5 | Effective permission resolver | 100% | Complete |
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
- `docs/decisions/0010-after-commit-mutation-events.md`
- `docs/decisions/0011-bounded-concurrency-retry.md`
- `docs/decisions/0012-effective-access-resolution.md`

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
| 0010 | Dispatch one generic after-commit mutation event | Accepted |
| 0011 | Retry only assignment coordination on confirmed transient failures | Accepted |
| 0012 | Resolve effective access once and expose it through two thin contracts | Accepted |
| 0013 | Keep the after-commit invalidation slot reserved and defer cross-request caching to Phase 8 | Accepted |
| 0014 | Define the pre-v1 public API boundary | Accepted |

[ADR 0014](docs/decisions/0014-pre-v1-public-api-boundary.md) — *Define the
Pre-v1 Public API Boundary* — was **Accepted** on 2026-08-26. It is based on
the complete 218-symbol inventory in
[Public API Inventory 0002](docs/reviews/0002-public-api-inventory.md) and
defines five compatibility tiers: 39 supported public symbols, 13 consumer
extension points, 4 framework integration surfaces, 5 operational exception
types and 157 internal symbols. It also records fifteen corrections required
before any freeze, nine of which block it.

Its five open questions were **resolved by maintainer decision on 2026-08-20**
and are recorded in ADR 0014 section 21: catch-all event listeners use
`instanceof` rather than a second marker; all four assignment-period scopes are
extension surface; rebinding `Psr\Clock\ClockInterface` is a supported testing
seam; `ModelRegistry` stays internal with no discovery contract; and value-object
validation keeps PHP's `InvalidArgumentException` with its behavior documented
instead of gaining new exception types. **No open question remains.** The tier
counts are unchanged by these decisions.

**No API is frozen.** Accepting ADR 0014 settles the boundary and authorises its
ordered correction increments; it does not itself change a source file,
annotation or signature, accept a documentation promise, or complete Phase 4.
Phase 4 therefore remains **19 of 20 = 95%**, Phase 5 remains **100%**, overall
equal-phase progress remains **approximately 41%**, and the deliverable tracker
remains **12 of 32, approximately 38%**.

ADR 0014 **Increment 1 of 10** was completed on 2026-08-26. It corrects the
configurable-model result generics, makes `GateTrailEvent::context()` a typed
contract implemented by both package events, and aligns package-controlled
constructors. Targeted reflection tests and `CHANGELOG.md` migration guidance
pin the new boundary. This completes corrections 1–4 but does not freeze the API
or complete another roadmap deliverable, so every progress figure above remains
unchanged.

ADR 0014 **Increment 2 of 10** was completed on 2026-08-26. The configured
access-audit model must now extend `Models\AccessAudit`; the observable
connection/table behavior inherited by the six supported concrete models and
all seven table keys is pinned; and all four assignment-period scopes are
documented and tested as Tier 2 surface. Corrections 5–7 are complete. This
hardens the boundary without freezing it or completing a separate roadmap
deliverable, so Phase 4 and all global progress figures remain unchanged.

ADR 0014 **Increment 3 of 10** was completed on 2026-08-26. The accepted
218-symbol manifest was split into 61 non-internal symbols and 157 Tier 4
symbols; all 157 now carry class-level `@internal` annotations, with 81 added and
none removed. Correction 11 is complete. The sweep changes documentation
visibility only, so Phase 4 remains 19 of 20 and every global progress figure is
unchanged.

ADR 0014 **Increment 4 of 10** was completed on 2026-08-26. A reflection-based
boundary test now classifies all 218 source symbols, requires the 157 Tier 4
symbols to retain real class-level `@internal` tags and snapshots the exact
runtime surface of all 39 Tier 1 and 13 Tier 2 symbols. Correction 12 is complete.
This safeguard completes no separate roadmap deliverable, so Phase 4 remains
19 of 20 and every global progress figure is unchanged.

ADR 0014 **Increment 5 of 10** was completed on 2026-08-26. Container tests now
pin all five public contract-to-concrete bindings and prove that the two public
resolvers are replaced across scope boundaries while the three managers remain
singletons. A contract-typed event listener receives both `MutationCommitted`
and `AuditPersistenceFailed`, consumes their safe contexts generically and uses
explicit narrowing for event-specific fields. Correction 13 is complete. This
test hardening changes no roadmap deliverable or global progress figure.

ADR 0014 **Increment 6 of 10** was completed on 2026-08-26. `PACKAGE_SPEC.md`
now classifies all 25 implemented exceptions as 20 Tier 1 and 5 operational
types, keeps `InvalidMiddlewareParameter` and `UnauthorizedMutation` explicitly
unimplemented for Phase 6, labels the middleware design as proposed, and aligns
its public-stability section with the accepted 218-symbol boundary. The stale
`AuditMode::Disabled` comment now describes its implemented behavior. Corrections
8–10 are complete. This documentation alignment changes no roadmap deliverable
or global progress figure.

[ADR 0013](docs/decisions/0013-after-commit-cache-invalidation.md) was accepted
on 2026-08-19; it decides the *sequencing* of cross-request caching and
implements none of it, leaving the cache store, TTL, fan-out, generation scheme
and post-commit failure policy to Phase 8 — which may also decide that no
cross-request cache is needed.

Acceptance records an architectural decision, not implementation. ADR 0012 is
accepted **and its implementation is complete**: all ten increments are
delivered as of 2026-08-19. Both public resolvers are implemented, bound and
memoized per scope, the connection-level statement instrumentation of section 8
exists, and the section 12.1 acceptance matrix has been produced on commit
`9a66824` — CI run #98 for the SQLite and four live MySQL lanes, Oracle
compatibility run #11 for both live Oracle lanes. PostgreSQL and SQL Server
remain query-compilation and schema-adapter inspection only, with no live
endpoint executed.

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

### Atomic completion checklist

Each mutation item includes its public manager method, thin adapter, immutable
command, single-use-case handler, typed exceptions, audit evidence, and
automated tests. Those internal components are acceptance criteria rather than
additional progress items.

#### Assignment mutations

- [x] `AssignmentManager::assignRole()`.
- [x] `AssignmentManager::revokeRole()`.
- [x] `AssignmentManager::grantDirectPermission()`.
- [x] `AssignmentManager::revokeDirectPermission()`.
- [x] `AssignmentManager::grantPermissionToRole()`.
- [x] `AssignmentManager::revokePermissionFromRole()`.

#### Role-definition mutations

- [x] `RoleManager::create()`.
- [x] `RoleManager::update()`.
- [x] `RoleManager::deactivate()`.
- [x] `RoleManager::restore()`.

#### Permission-definition mutations

- [x] `PermissionManager::create()`.
- [x] `PermissionManager::update()`.
- [x] `PermissionManager::deactivate()`.
- [x] `PermissionManager::restore()`.

#### Cross-cutting mutation behavior

- [x] Strict audit behavior.
- [x] Non-strict audit behavior.
- [x] Disabled audit behavior.
- [x] After-commit event dispatch.
- [x] Bounded concurrency retry. Contract accepted in
      [ADR 0011](docs/decisions/0011-bounded-concurrency-retry.md). The runtime
      implementation is complete, and the acceptance evidence ADR 0011 requires
      — a live parallel MySQL run on both supported versions — has been observed
      passing in `CI #82` for commit `c5822a0`.
- [ ] Consumer documentation and pre-v1 API freeze.

```text
Completed: 19
Total:     20
Progress:  95%
```

### Required strict-audit flow

```text
Validate
→ Begin or join transaction
→ Resolve references
→ Acquire coordination lock
→ Persist mutation
→ Persist audit
→ Commit
→ Invalidate cross-request cache, if enabled
  (reserved slot; currently empty; Phase 8)
→ Dispatch MutationCommitted
```

The cross-request invalidation step is a **reserved ordering point, not
implemented behavior**. ADR 0010 fixed where such work would run — after the
outermost successful commit and before the `MutationCommitted` dispatch — and
[ADR 0013](docs/decisions/0013-after-commit-cache-invalidation.md) keeps that
slot empty. It remains empty unless Phase 8 retains **and enables**
cross-request caching; no cross-request cache, invalidator, container binding,
configuration key or public API exists today.

Request-local memo clearing from
[ADR 0012](docs/decisions/0012-effective-access-resolution.md) is already
implemented and is a **separate** mechanism: the `EffectiveAccessMemo` listener
flushes the scoped in-process store when it observes `MutationCommitted` in the
same process. That is same-process bookkeeping and is **not** distributed or
cross-request invalidation.

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
- Strict `RoleManager::update()` now applies immutable `RoleChanges`
  description edits. Its transaction-bound definition resolver reloads and
  locks the active role from model, integer-ID, explicit-reference, and
  default-guard name inputs, keeps numeric-looking strings as names, rejects
  deactivated definitions and malformed identifiers before any driver
  binding, and never inspects authentication state. Identical descriptions
  return `Unchanged` without an audit record or an `updated_at` change, while
  real edits persist description only and record canonical `role.updated`
  before/after snapshots proving name, guard, and deletion state are
  unchanged.
- Strict `RoleManager::deactivate()` now suspends a role definition without
  rewriting history. It resolves and locks the active role from every accepted
  input, rejects missing and already-deactivated definitions as `RoleNotFound`,
  and soft-deactivates through a deterministic update so `deleted_at` and
  `updated_at` both carry the single captured package clock timestamp instead
  of an Eloquent wall-clock value. Name, guard, and description are untouched,
  the `(name, guard_name)` namespace stays occupied so creation still throws
  `DuplicateRole`, and subject-role and role-permission assignment rows remain
  byte-identical and unrevoked. Canonical `role.deactivated` before/after
  evidence is persisted atomically, and the audit snapshot builder now reloads
  definitions through a model-bound unscoped primary-key query so a
  just-deactivated row is still reconstructable.
- Strict `RoleManager::restore()` completes the accepted four-method role
  contract. A dedicated transaction-bound
  `DeactivatedRoleDefinitionInputResolver` locks soft-deleted definitions and
  rejects active and missing rows as `RoleNotFound`, so the active-only
  resolver every other mutation depends on stays unchanged. Restoration is the
  exact inverse of deactivation: `deleted_at` is cleared and `updated_at`
  carries the captured package clock through one explicit update, without
  Eloquent's `restore()`, its wall-clock timestamps, or its
  `restoring`/`restored` events. Name, guard, and description are untouched,
  the same persisted row is reactivated so no duplicate-name translation and
  no second definition occur, and the `(name, guard_name)` namespace stays
  occupied. Subject-role and role-permission rows remain byte-identical:
  unrevoked assignments become eligible again on their own merits and revoked
  assignments keep their full revocation tuple. Canonical `role.restored`
  before/after evidence is persisted atomically.
- The first permission-definition slice exposes strict
  `PermissionManager::create()` through a thin public adapter that owns only
  the creation handler and the package clock. It reuses the shared definition
  input validator, so names and guards are trimmed, case-preserving, bounded
  to 191 Unicode characters, and required — the guard is never inferred from
  authentication state and never defaults during creation — while descriptions
  stay untrimmed, untruncated, and distinguish `null` from `''`. Active and
  deactivated `(name, guard_name)` collisions are both rejected as
  `DuplicatePermission` under a `withTrashed()` row-locked pre-check, so
  creation never resurrects a deactivated definition, and a concurrent race is
  translated only when the narrow portable classifier confirms the
  `acl_perm_name_guard_uq` identity. Primary-key collisions and missing-table
  failures surface untranslated. The exception carries a hashed namespace only,
  with no SQL, bindings, or chained driver exception. Canonical version 1
  permission snapshots reload persisted state through a model-bound unscoped
  primary-key query, so deactivated rows already reconstruct before the
  remaining lifecycle slices exist, and `permission.created` evidence is
  persisted atomically with its purpose-specific permission-definition context.
- Strict `PermissionManager::update()` now applies immutable
  `PermissionChanges` description edits, so name and guard have no change API
  at all. A dedicated transaction-bound `PermissionDefinitionInputResolver`
  reloads and row-locks the active permission from model, integer-ID,
  explicit-reference, and default-guard name inputs, keeps numeric-looking
  strings as names, rejects deactivated definitions and malformed identifiers
  before any driver binding, and never inspects authentication state.
  `PermissionNotFound` now carries a nullable guard, so a missing explicit
  reference reports no guard rather than inventing one, while name-based and
  assignment paths keep their resolved guard. Identical descriptions return
  `Unchanged` with no audit record, no `updated_at` change, and no update
  statement at all; real edits persist description only and record canonical
  `permission.updated` before/after snapshots proving id, name, guard, and
  deletion state are untouched.
- Strict `PermissionManager::deactivate()` now suspends a permission
  definition without rewriting history. It reuses the transaction-bound
  `PermissionDefinitionInputResolver`, so every accepted input resolves and
  row-locks the active permission under the established semantics, and
  missing, unpersisted, malformed, and already-deactivated targets all fail
  consistently as `PermissionNotFound` rather than through a second
  idempotency policy. Deactivation is a deterministic update rather than
  Eloquent's `delete()`, so `deleted_at` and `updated_at` both carry the
  single captured package clock timestamp instead of a wall-clock value.
  Name, guard, and description are untouched, the `(name, guard_name)`
  namespace stays occupied so creation still throws `DuplicatePermission`,
  and direct permission assignments and role-permission grants remain
  byte-identical — unrevoked rows stay unrevoked and revoked rows keep their
  full revocation tuple. Existing audit rows are appended to, never rewritten,
  and canonical `permission.deactivated` before/after evidence is persisted
  atomically.
- Strict `PermissionManager::restore()` completes the accepted four-method
  permission contract. A dedicated transaction-bound
  `DeactivatedPermissionDefinitionInputResolver` locks soft-deleted
  definitions and rejects active, missing, unpersisted, and malformed targets
  as `PermissionNotFound`, so the active-only resolver every other mutation
  depends on stays unchanged. Restoration is the exact inverse of
  deactivation: `deleted_at` is cleared and `updated_at` carries the captured
  package clock through one explicit update, without Eloquent's `restore()`,
  its wall-clock timestamps, or its `restoring`/`restored` events. Name,
  guard, description, and identifier are untouched, the same persisted row is
  reactivated so no second definition is created, and the namespace stays
  occupied. Direct permission assignments and role-permission grants remain
  byte-identical: unrevoked rows become eligible again on their own merits and
  revoked rows keep their full revocation tuple. Existing audit rows are
  appended to, never rewritten, and canonical `permission.restored`
  before/after evidence is persisted atomically.

- Enabled non-strict auditing is now supported by every managed mutation. One
  central `AuditedMutationTransaction` owns the transaction state machine, so
  the fourteen handlers only validate, mutate, build canonical snapshots, and
  return an immutable `PreparedMutation`; none of them inspects audit
  configuration any more. Strict behavior is unchanged and still atomic. When
  GateTrail owns the outermost transaction, non-strict mode commits the
  mutation and then writes the audit in a second transaction, returning the
  audit identifier. When it joins a caller-owned transaction it verifies
  outermost after-commit support *before* the mutation closure runs, rejecting
  an unsupported boundary with `UnsupportedAfterCommitBoundary` rather than
  silently
  writing the audit inside the caller's transaction; the audit then waits for
  the outer commit and the immediate result reports a null identifier. That
  verification reads transaction state and registers nothing, so an unchanged
  result leaves no callback of any kind behind it. A caller rollback discards
  both the mutation and the pending audit. Audit mode selection itself fails
  closed: `audit.enabled` must always be a real boolean and `audit.strict` must
  be one whenever auditing is enabled, so a missing, null, string, numeric,
  array, or object value is rejected with `InvalidAuditConfiguration` before any
  table is touched rather than being coerced into weaker auditing. A disabled
  mode never consults `strict`. A non-strict audit failure
  leaves the mutation committed, returns a null identifier, writes one
  error-level log, and dispatches an `AuditPersistenceFailed` operational event
  carrying safe identifiers, the audit event name, an optional request ID, and
  the underlying exception class only — never snapshots, metadata, reasons,
  credentials, or exception messages. Operational reporting is best effort: a
  broken log channel or a throwing listener cannot turn a committed mutation
  into an application error, and a failed audit is never retried. Unchanged
  results write no audit, schedule no audit callback, and emit nothing.
  Canonical audit documents are proven byte-identical between strict and
  non-strict execution across all fourteen mutations.
- Disabled auditing completes the three accepted audit modes. `audit.enabled`
  set to `false` runs every mutation through its normal single-attempt
  transaction with unchanged validation, locking, conflict handling, lifecycle
  and rollback behavior, then discards the prepared audit document and returns
  a null identifier. `AccessAuditWriter` is never called, no statement reaches
  the access-audit table, and no operational failure is logged or dispatched
  because nothing was attempted. A caller-owned transaction is joined normally:
  an outer commit keeps the mutation and an outer rollback removes it. `strict`
  has no effect while auditing is off, and GateTrail never selects this mode by
  itself. Mutations succeed even when audit storage is entirely unavailable,
  which is the point: disabled auditing is an explicit consumer choice that
  leaves no authorization history behind, documented as such in the published
  configuration file.
- After-commit mutation events are implemented to the contract accepted in
  [ADR 0010](docs/decisions/0010-after-commit-mutation-events.md). Every changed
  managed mutation dispatches exactly one public `MutationCommitted` after its
  outermost successful commit, in strict, non-strict and disabled audit modes
  alike. The event implements the `GateTrailEvent` marker interface, so a
  listener bound to that interface receives every GateTrail event while a
  listener bound to the class receives only this one. It carries the canonical
  mutation name, outcome, target pair, subject pair, actor pair, audit
  identifier, UTC timestamp and optional request ID — no models, snapshots,
  metadata, reasons or credentials — and consumers cannot construct it. Dispatch
  is central: the fourteen handlers still return a `PreparedMutation` and know
  nothing about events. Inside a caller-owned transaction one combined callback
  performs the deferred audit attempt, the reserved cache-invalidation point and
  the dispatch in that order, so the event can carry an audit identifier the
  immediate `MutationResult` structurally could not. A rollback discards it and
  an unchanged outcome schedules nothing. Nothing after the commit can escape:
  a throwing listener, and an event that cannot even be constructed because a
  package invariant was violated, are both caught and logged once with safe
  context and the exception class only, never re-dispatched, and never retried. Event production
  is always on, with no configuration flag. The after-commit boundary rule is now
  uniform across every audit mode and its exception is
  `UnsupportedAfterCommitBoundary`; `UnsupportedAuditBoundary` no longer exists.

`RoleManager` and `PermissionManager` are now each 4 of 4 complete —
`create()`, `update()`, `deactivate()`, and `restore()` on both contracts have
a real single-use-case handler and no stub exists. Every definition mutation
accepted by ADR 0002 is therefore implemented, bound, and tested. Both
contracts remain pre-v1. All three accepted audit modes, the accepted
after-commit mutation event, and bounded concurrency retry are now implemented
and accepted. Phase 4 stays open for **one** remaining item, the consumer
documentation and pre-v1 API freeze.

**`After-commit cache invalidation` was moved to Phase 8.**
[ADR 0013](docs/decisions/0013-after-commit-cache-invalidation.md) is
**Accepted** as of 2026-08-19. It recorded a sequencing conflict: this phase
listed after-commit cache invalidation as a deliverable, while Phase 8 decides
whether a cross-request cache is wanted **at all** only after a reproducible
uncached benchmark. The accepted resolution keeps ADR 0010's reserved ordering
point empty, leaves request-local memoization as the only active optimization,
and moves real cross-request invalidation to Phase 8.

The item was **moved, not renamed and checked**. ADR 0010's ordering slot is
tested, valuable foundation that a future invalidator inherits — the
outermost-commit rule, ADR 0011's exactly-once retry semantics and the
audit-then-cache-then-event order — but it is not invalidation, and checking it
under a new name would have implied invalidation was delivered.

**Nothing was implemented.** No cross-request cache, invalidator, container
binding, configuration key or public API exists, and no Phase 8 work has started.
The accepted posture is that any future cross-request cache stays optional,
ships disabled by default, is never enabled automatically by GateTrail, and must
work on a tagless baseline; the public `MutationCommitted` event stays unchanged;
and the post-commit invalidation-failure policy, the guard-wide generation
strategy and whether cross-request caching is needed before `1.0.0` at all remain
deliberately open Phase 8 questions.

**Progress effect.** Phase 4's denominator drops from 21 to 20 and the phase
moves to **19 of 20 = 95%**, still `In progress`. Phase 5 remains **100%**.
Overall equal-phase progress becomes (100 + 100 + 100 + 80 + 95 + 100 + 0×8) / 14
= 575 / 14 = 41.071%, still reported as **approximately 41%**. The global
deliverable tracker is **unchanged at 12 of 32, approximately 38%** — relocating
an unfinished item between phases completes no deliverable.

Bounded concurrency retry is implemented.
[ADR 0011](docs/decisions/0011-bounded-concurrency-retry.md) is accepted and now
realised in code: GateTrail retries only the six assignment mutations, only when
it owns the outermost transaction, and only for positively classified transient
failures — a coordination-row insert race confirmed against the lock table's own
primary key, plus deadlock and serialization rollbacks. Owner review resolved its
three open decisions — no previous exception at all, no lock-timeout or SQLite
busy retry, and no definition deadlock retry — and the ADR was accepted on
2026-08-02, with the Oracle SQLSTATE portability clarification approved on
2026-08-12.

The first increment delivered the foundation types: the shared internal
`MutationName` vocabulary owns all fourteen canonical names, `RetryPolicy`
validates `assignments.concurrency_attempts` fail-closed as a real integer from
`1` to `10`, `ClassifiedConcurrencyFailure` is a non-`Throwable` immutable value
carrying four safe scalars, and the public `ConcurrencyRetryExhausted` reports
operation, attempts and caller-owned state with an always-empty previous chain.

The second increment delivered portable classification: canonical uppercase
SQLSTATE hardening, `TransientFailureClassifier` for driver-aware deadlock and
serialization shapes, `CoordinationRaceClassifier` for the assignment-lock
insert race alone, and the then-inert `CoordinationInsertRace` marker.

The third increment activated retry, landing the marker's production throw site
and its catch site atomically:

- **`AssignmentLockStore` is wired at the exact insert boundary.** The
  coordination-row `INSERT` is the only statement wrapped in a `QueryException`
  catch. A confirmed race becomes `CoordinationInsertRace`; anything the
  classifier does not positively recognise is rethrown as the same object with
  its own chain intact. The initial `SELECT ... FOR UPDATE` and the re-read are
  deliberately outside the catch, so
  `CoordinationRaceClassifier`'s positional precondition is structurally true
  rather than a caller convention. `AssignmentScopeCollision` is unchanged.
- **`AuditedMutationTransaction::runCoordinated()` is the second entry point.**
  Before any transaction opens it validates the mutation name against the six
  coordinated assignments, resolves the bounded attempt count, decides ownership
  from the connection's transaction level, and applies the existing
  after-commit boundary rule.
- **The loop sits inside the class, around the mutation transaction attempt
  only.** One private seam serves all three audit modes. `announce()` remains
  outside it, which is what makes ADR 0010's exactly-once guarantee structural.
- **`RetryOrchestrator` counts attempts and decides exhaustion.** It catches
  exactly two types and never `Throwable`, reduces a classified driver
  exception to safe scalars and drops it inside the `catch`, and refuses to
  retry on a connection a failed attempt did not leave at transaction level
  zero.
- **The six assignment handlers opt in with the same constant they audit with.**
  All eight definition handlers still call `run()`, and
  `StrictMutationTransaction` still runs with `attempts: 1`.

The fourth increment completed and hardened the contract:

- **Revocation coordinates.** ADR 0001 requires every managed assignment
  creation *and revocation* to serialize through `acl_assignment_locks`.
  `revokeRole()`, `revokeDirectPermission()` and `revokePermissionFromRole()`
  entered `runCoordinated()` in the third increment but took no coordination
  row, so two writers could still interleave a creation and a revocation on one
  scope. All three now coordinate through a shared `RevocationCoordinator` in
  the one order that cannot deadlock against the creation path: probe the
  assignment's persisted identity **without** a lock, derive the canonical
  scope from those persisted values, acquire the coordination row, reload the
  assignment `FOR UPDATE`, re-derive the scope from the locked row, and fail
  closed with `AssignmentScopeCollision` if it moved. Locking the assignment row
  first — the obvious single-read version — would invert the lock order against
  creation, which takes coordination first and equivalent assignment rows
  second.
- **Caller-supplied models are never trusted for scope.** Only the identifier is
  taken from an incoming Eloquent model; `subject_type`, `subject_id`,
  `role_id` and `permission_id` are read back from the database by primary key,
  so a stale or edited in-memory attribute cannot steer GateTrail onto the wrong
  coordination row. Identifiers reuse the existing normalizer, so a malformed
  numeric identifier never reaches a PostgreSQL, Oracle or SQL Server binding.
- **The unsupported-driver contract is restored.** Both classification paths
  previously swallowed `UnsupportedDatabaseDriver` and treated it as an
  unclassified failure. That silently extended retry semantics to drivers whose
  error surface GateTrail has never verified. Both now let the typed exception
  propagate unchanged, with zero retry.

### Bounded concurrency retry: implementation versus acceptance evidence

These are deliberately reported as two different things.

**Runtime implementation: complete.** Every behaviour ADR 0011 specifies exists
in code and is covered by unit and single-process integration tests. Races in
those tests are real rather than stubbed — the coordination row is planted
immediately before the store's own insert, so the engine raises a genuine
duplicate-key failure at the exact statement and the production classifier
confirms it unaided. Coverage proves one attempt on success; retry-then-commit
producing exactly one mutation, one audit row and one event; a fresh transaction
per attempt; re-resolution of transaction-bound state on every attempt; the
configured maximum of ten; exhaustion leaving no mutation, audit, coordination
row or event; caller-owned transactions making exactly one attempt; the marker
never reaching a consumer; and every listed domain exception, `AuditWriteFailed`,
`InvalidAuditMetadata`, unclassified `QueryException`s, lock timeouts, SQLite
`BUSY`/`LOCKED` and unsupported drivers never being retried.

**Acceptance evidence: complete.** ADR 0011 also requires real-concurrency
evidence: parallel writers against one assignment scope on the disposable MySQL
service. `tests/Integration/MySql/ParallelCoordinationTest` spawns separate PHP
processes on separate MySQL connections, releases them from a database barrier,
and asserts the final invariant. GitHub Actions run `CI #82` for commit
`c5822a0` passes, and with it all four live MySQL integration lanes:

```text
Laravel 12 / MySQL 8.0.16 — pass
Laravel 12 / MySQL 8.0.45 — pass
Laravel 13 / MySQL 8.0.16 — pass
Laravel 13 / MySQL 8.0.45 — pass
```

That run is the acceptance criterion, so the checklist item is now checked and
the Quality deliverable "Concurrency tests" is checked. Phase 4 moved to 19 of 21
at that point; ADR 0013 later moved one item to Phase 8, so the denominator is
now 20 — see the Phase 4 total below.

**The two kinds of evidence stay separate.** Live MySQL ran only in CI, against
the workflow's disposable MySQL service containers; it was not executed locally.
The local gates for the same commit — PHPUnit at 970 tests and 5926 assertions
on the default SQLite suite, PHPStan at level max, Pint, `composer validate` and
`git diff --check` — are quality-gate evidence, not live-database evidence. Two
limits explain why they cannot substitute: ADR 0011 gives SQLite no retryable
deadlock or serialization shape, so those two matrix rows are covered only by
driver-shape vectors and by connections that report another driver without
opening one; and a single-process test cannot produce genuine inter-process
contention.

One detail bounds what the coordination race can affect. Only `assignRole()`,
`grantDirectPermission()` and `grantPermissionToRole()` insert a coordination
row on a scope that has never been used; a revocation normally finds the row its
creation left behind and takes it without inserting. All six coordinate, and all
six retry a classified deadlock or serialization rollback.

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

- [x] `PermissionResolver` contract and implementation.
- [x] `RoleResolver` contract and implementation.
- [x] Direct permission resolution.
- [x] Role-derived permission resolution.
- [x] Effective role collection.
- [x] Subject guard resolution.
- [x] Lifecycle filtering.
- [x] Effective permission collection.
- [x] Request-level memoization.
- [x] Query-count instrumentation.

### Required rules

- default deny;
- evaluate the supplied subject;
- never substitute the authenticated global user;
- apply guard, start, expiry, revoke, and deactivation filters;
- preserve parity between boolean checks and effective collections;
- avoid N+1 queries.

### Design status

Design is complete and accepted. Effective access resolution is specified in
[ADR 0012](docs/decisions/0012-effective-access-resolution.md), which is
**Accepted** as of 2026-08-13, with all ten review questions resolved.

The deliverable list above was corrected from eight items to ten at acceptance.
`PACKAGE_SPEC.md` sections 11.1 and 11.5 define both a `PermissionResolver` and
a `RoleResolver`, while the earlier list named only `PermissionResolver` and
placed the role resolver in no phase at all. The corrected list adds
`RoleResolver` and the effective role collection.

`EffectiveAccessSnapshot`, `EffectiveAccessResolver` and
`SubjectIdentityResolver` are **not** counted separately. Under section 3.2,
shared infrastructure is included in the acceptance criteria of the capability
that uses it and is not counted again as separate progress.

The correction changed the denominator from eight to ten. **Accepting an
architecture is not implementation evidence**, so the numerator stayed at zero
until implementation produced it; see the implementation status below for what
has since been evidenced.

### Implementation status

**Implementation is complete: all ten increments of ADR 0012 are delivered as
of 2026-08-19**, and the section 12.1 acceptance matrix is recorded. The
increment-by-increment record below is chronological, so a statement inside an
increment describes the project at that increment rather than today.

**Increment 1 — shared subject identity.** `SubjectIdentity`,
`SubjectIdentityResolver` and `InvalidSubject` exist, are container-bound, and
have replaced the two divergent private `subjectIdentity()` copies in
`AssignRoleHandler` and `GrantDirectPermissionHandler`. A subject now resolves
to one canonical identity, or is rejected identically, on every path.

**Increment 2 — value objects and public contracts.** `EffectivePermission`
and `EffectiveRole` exist as `final readonly` output types with
package-controlled construction, and the `PermissionResolver` and
`RoleResolver` interfaces carry the accepted signatures. The contracts are
**deliberately left unbound**: they have no implementation yet, and a binding
without one would mean a fake, a placeholder or a null object. Their bindings
arrive in Increment 7 alongside the real default adapters.

**Increment 3 — the immutable `EffectiveAccessSnapshot`.** The internal
snapshot exists: one canonical subject identity, one resolved guard, one
supplied evaluation instant, and the effective role and permission lists,
deduplicated by definition primary key and ordered by raw-byte `strcmp()`. It
projects fresh `Collection` instances and exact in-memory membership, and it
retains no model, connection, query builder, mutable collection, counter or
closure. It is built entirely from values handed to it: **it holds an evaluated
state, it does not evaluate one.**

**Increment 4 — the portable query builders.** `EffectiveAccessQueryBuilder`
builds ADR queries 1 and 2 — effective roles and direct permissions — and
**returns them unexecuted**. Each joins the configured definition table and
writes its own `deleted_at IS NULL` and `guard_name` predicates, because
neither is available to inherit: the root of every snapshot query is an
assignment model, none of which soft delete, and this package's definition
relations are deliberately `withTrashed()`. The existing `activeAt()` scope is
called, not copied. Every table and column name is resolved from
`ModelRegistry` model accessors, so a configured prefix, a configured schema, a
replacement model, an overridden `DELETED_AT` and a custom primary-key name all
apply. Nothing executes these builders.

**Increment 5 — internal resolution.** `EffectiveAccessResolver` executes
queries 1 and 2 and returns one snapshot. Per call it resolves the **supplied**
subject once through `SubjectIdentityResolver`, one guard once through
`SubjectGuardResolver`, and reads the clock **once**, passing the identical
instant to both statements. `EffectiveAccessRowMapper` turns rows into
`EffectiveRole` and `EffectivePermission` values through the three fixed query
aliases, normalizing identifiers with the same `DefinitionIdentifierNormalizer`
the mutation paths use, and dropping any row whose guard is not byte-for-byte
the resolved guard. Reads are `SELECT` only: no write, no lock, no coordination
row and no package-owned transaction. The resolver is registered as a scoped
internal service, and nothing consumer-facing can reach it.

**Increment 6 — role-derived permissions.** Query 3 completes the
specification's effective-permission formula. It is rooted on the
role-permission grant, narrowed by the candidate role identifiers query 1
observed, and it **revalidates the entire path inside one statement**: the
grant's own lifecycle, the role definition's lifecycle and guard, the
permission definition's lifecycle and guard, and — through a correlated
`EXISTS` over `RoleAssignment` — that the subject still actually holds the
role. The candidate list is a scan bound and a chunk unit, never evidence, so
a role revoked or deactivated between query 1 and query 3 contributes nothing.
Candidates are chunked at a fixed **900**, giving one statement per chunk and
never one per role, and query 3 is skipped outright when no role was observed.

**Increment 7 — the public resolvers.** `DefaultPermissionResolver` and
`DefaultRoleResolver` implement the two contracts as thin projections over one
snapshot, and both contracts are now bound. A shared
`RequestedDefinitionNormalizer` interprets the caller's input once for both:
**a bare string is always a name** — `'007'` asks for a definition literally
named `007`, while `Reference::id('007')` asks for identifier 7 — and an
identifier only ever arrives through a reference, a native `int` or a
configured model's persisted key. A definition model is honoured only when it
is the `ModelRegistry`-resolved class or a subclass of it, so an unrelated
application model whose key collides cannot authorize anyone. An impossible
input denies **before** the subject, guard, clock or any statement is touched.

**At Increment 7, eight deliverables were checked and this phase stood at
80%.**

- `PermissionResolver contract and implementation` — `allows()` and
  `effectivePermissions()` over one snapshot, with the complete input matrix.
- `RoleResolver contract and implementation` — `has()` and `effectiveRoles()`
  over the same snapshot, so the two can never disagree.
- `Direct permission resolution` — query 2, filtered by assignment lifecycle,
  definition lifecycle and guard.
- `Effective role collection` — query 1's ordered, unique role set, including
  roles that grant no permissions.
- `Subject guard resolution` — one guard per evaluation through
  `SubjectGuardResolver`, with byte-exact post-verification of every returned
  row, including query 3's second, role-side guard.
- `Role-derived permission resolution` — query 3 with full-path revalidation
  and 900-identifier chunking.
- `Lifecycle filtering` — all four assignment-shaped lifecycles now flow
  through the one `activeAt()` scope: role assignments, direct permission
  assignments, role-permission grants, and the assignment revalidated inside
  query 3's `EXISTS`. The mirror matrix proves the outer and inner predicates
  bind to their own roots.
- `Effective permission collection` — direct and role-derived permissions
  merged, deduplicated by primary key and ordered by raw-byte `strcmp()`, so a
  permission reachable through two roles and a direct grant appears once.

**Increment 8 — request-local memoization.** `EffectiveAccessMemo` is a
scoped, bounded, least-recently-used store keyed on the canonical subject
identity, the resolved guard and the evaluation instant truncated to a UTC
whole second. Repeated checks within one second share one snapshot and cost
**zero additional statements**; a second boundary always rebuilds, so a memo
cannot outlive a lifecycle edge. A `MutationCommitted` observed in this scope
clears the whole store through a listener that resolves the memo from the
container **at dispatch time**, never in its constructor — a captured store
would serve one request's answers to the next under Octane or a queue worker.
An active caller-owned transaction bypasses the memo in both directions:
transaction-visible state is read fresh and never stored, and the committed
entry is neither returned nor evicted, so it survives a rollback intact.

- `Request-level memoization` — the scoped bounded store, the whole-second UTC
  key, the wholesale same-scope flush, and the caller-transaction bypass.

**Increment 9 — query instrumentation and portability hardening.**
`GateTrailStatementProbe` and `StatementMeasurement` exist under
`src/Diagnostics/`. The probe registers its listener through the `Connection`
`SchemaContext` selects — not through the application dispatcher, which a
connection with a dispatcher of its own would bypass entirely — and then filters
on that **`Connection` instance**, not its name, because a connection's
dispatcher is normally shared with every other connection. A measurement is
seven `int` counters — `total`, `select`, `insert`, `update`, `delete`,
`schema`, `other` — and nothing else: no SQL, no bindings, no identifier, no
name, no guard, no duration, no `QueryExecuted`. The whole section 6.3 budget
is now measured rather than asserted — 2 cold without roles, 3 with roles, 4 at
901 roles, 0 warm, 0 for an impossible input, 3 per call inside a caller's
transaction — and every one of those statements is measured to be a `SELECT`.
Adding one extra `SELECT` to the resolver moved 13 of the 14 budget cases,
which is the evidence that the mechanism measures the connection rather than
the algorithm's opinion of itself. Alongside it, the three snapshot shapes are
compiled under the framework's real PostgreSQL and SQL Server grammars and
inspected for qualification, correlation, aliases and every construct section
6.4 forbids.

The instrumentation is **test and diagnostic only**. `GateTrailServiceProvider`
binds nothing, no file outside `src/Diagnostics/` references it, neither public
resolver nor the internal resolver depends on it, a real authorization check
adds no listener, and no configuration key can enable it.
`EffectiveAccessSnapshot` still carries no query counter.

**Increment 10 — the full acceptance matrix, and the tenth deliverable.**
`tests/Integration/Support/ResolverAcceptanceFixture.php` holds one dataset and
one expected answer; `tests/Integration/Concerns/AssertsResolverAcceptance.php`
holds the assertions; and SQLite, live MySQL and live Oracle each run **that**
fixture against **that** expectation, so parity is one expectation checked three
times rather than three that happen to resemble each other. MySQL contributes
the `gt_` prefix evidence — with the unprefixed names asserted absent, so a
hard-coded table name would fail rather than silently succeed — and the live
900/901 chunk boundary. Oracle contributes schema-qualified `GATETRAIL.acl_*`
tables, OCI8 alias survival, `NUMBER`-to-`int` normalization and the half-open
boundaries against a real `DATE` column.

**The acceptance evidence is a recorded CI run, and it exists.** Both workflows
ran against commit `9a66824` and concluded success:

| Workflow | Run | Duration | Conclusion | Lanes proved |
|---|---|---|---|---|
| CI | #98 | 2m 18s | Success | Composer validate, code style, static analysis, SQLite / Laravel 12 / Testbench 10, SQLite / Laravel 13 / Testbench 11, and live MySQL 8.0.16 and 8.0.45 against each of Laravel 12 / Testbench 10 and Laravel 13 / Testbench 11 |
| Oracle compatibility | #11 | 2m 54s | Success | `Laravel 12 and 13 / Oracle Free 23.26.1`, whose final `Both Oracle lanes must pass` gate makes the job's success proof that Lane A (Laravel 13 / OCI8 13) and Lane B (Laravel 12 / OCI8 12) both passed |

Run URLs and internal run identifiers are not recorded, because they were not
part of the supplied evidence and inventing them would make this entry
unverifiable.

**`Query-count instrumentation` is therefore checked**, and with it the tenth
and last Phase 5 deliverable:

- `Query-count instrumentation` — `GateTrailStatementProbe` and
  `StatementMeasurement`, plus the acceptance of the section 12.1 engine matrix
  those budgets were measured across. The atomic item was always the mechanism
  *and* its live acceptance, which is why it stayed unchecked while only SQLite
  evidence existed.

Under section 3.2, `EffectiveAccessSnapshot`, `EffectiveAccessQueryBuilder`,
`EffectiveAccessResolver`, `EffectiveAccessRowMapper`,
`RequestedDefinitionNormalizer`, `RequestedDefinition`,
`EffectiveAccessMemoKey`, `FlushEffectiveAccessMemo`,
`SubjectIdentityResolver`, `GateTrailStatementProbe`, `StatementMeasurement`
and the shared acceptance fixture are all shared infrastructure counted through
the capabilities above, never separately.

**Phase 5 is complete at 10 of 10 = 100%**, and the global deliverable tracker
moves to **12 of 32** as `Effective permission resolver` — one tracked
deliverable covering the whole phase — is now satisfied.

**Cross-request cache invalidation is still not touched.** ADR 0013 has since
moved it to Phase 8, leaving Phase 4 at 19 of 20 = 95%. This memo is
request-local: it uses no cache store, no TTL, no tag and no broadcast, and a
mutation committed in another process does not reach it. **No Phase 6 work has
started**: no Gate hook, no subject concern, no middleware.

**The engine boundary, stated precisely.** SQLite carries the full behavioural
matrix on both Laravel/Testbench lanes. Live MySQL 8.0.16 and 8.0.45 carry the
four-lane resolver matrix. Live Oracle Free 23.26.1 carries the targeted
resolver matrix on both dependency lanes. **PostgreSQL and SQL Server are
covered by query compilation and schema-adapter inspection only — no live
endpoint of either kind has ever been executed**, and no runtime compatibility
is claimed for them from compiling a statement. Their live harnesses remain
future work, exactly as ADR 0012 section 12.1 requires.

Both CI runs surfaced a non-blocking GitHub Actions notice that Node.js 20 is
deprecated and `actions/checkout@v4` is being forced onto Node.js 24. It is a
runner-image observation about an action version, affects no assertion in this
matrix, and is recorded only as future CI maintenance.

```text
Completed: 10
Total:     10
Progress:  100%
```

### Exit criteria

The complete resolver test matrix passes without Laravel Gate, middleware, or
cross-request caching.

Status: **Complete**

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
- [ ] After-commit cross-request cache invalidation, if cross-request caching is
      retained. **Moved here from Phase 4** by
      [ADR 0013](docs/decisions/0013-after-commit-cache-invalidation.md),
      accepted 2026-08-19. This is the **runtime behaviour** — the code that runs
      in ADR 0010's reserved ordering slot after the outermost commit — and it is
      distinct from `Invalidation matrix` above, which is the design artifact
      describing which mutation invalidates what. Neither exists, and both are
      conditional on step 3 of the sequence deciding that a cross-request cache
      is wanted at all.

### Exit criteria

Every performance claim has a reproducible benchmark and caching cannot grant
access beyond uncached resolver behavior.

If step 3 concludes that no cross-request cache is necessary, every
cache-conditional deliverable above — all of them except the benchmark itself —
is closed as **not required** rather than implemented, and GateTrail ships with
request-local memoization as its only cache-like behaviour.

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
- [x] Effective permission resolver
- [ ] Gate integration
- [ ] Middleware
- [ ] Audit trail
- [ ] Cache invalidation

Progress: **3 of 10**

### Quality

- [ ] Unit tests
- [ ] Integration tests
- [ ] Security tests
- [x] Concurrency tests
- [ ] Verified compatibility matrix
- [ ] Performance benchmark

Progress: **1 of 6**

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
Completed: 12
Total:     32
Progress:  37.5%, reported as approximately 38%
```

## 25. Progress update rules

When work is completed:

1. update the relevant atomic checklist only when its acceptance criteria and
   implementation evidence are complete;
2. add evidence such as a commit, test, CI run, or release tag;
3. recalculate the affected phase from completed atomic items;
4. recalculate the equal-phase overall percentage;
5. recalculate the first sprint or deliverable tracker when applicable,
   without combining those measurements with phase progress;
6. do not count shared infrastructure again when its consuming use case is
   already an atomic progress item;
7. update the `Last updated` date;
8. do not mark design-only work as implemented.

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

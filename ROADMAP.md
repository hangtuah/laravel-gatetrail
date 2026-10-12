# GateTrail Roadmap and Implementation Guide

Last updated: 2026-10-12

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
Phase 4 — Complete
Phase 5 — Complete
Phase 6 — Complete
Phase 7 — Complete
Phase 8 — Complete
Phase 9 — Complete
Phase 10 — Complete
Phase 11 — In progress
Phase 12 onward — Not started
```

The package specification is accepted for implementation. Repository tooling
is complete, and database/lifecycle implementation remains in progress.

ADR numbers are decision-record numbers, not roadmap phase numbers. For
example, ADR 0004 does not mean the project has reached Phase 4.

### 3.2 Progress percentages

| Measurement | Current value | Interpretation |
|---|---:|---|
| Overall roadmap by primary phases | approximately 82% | Equal-phase progress across Phases 0–13 |
| Deliverable tracker | 23 of 32, approximately 72% | Completed concrete package deliverables |
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
| 3 | Database and assignment lifecycle | 10 of 11, approximately 91% | In progress |
| 4 | Mutation services | 100% | Complete |
| 5 | Effective permission resolver | 100% | Complete |
| 6 | Laravel integration | 8 of 8, 100% | Complete |
| 7 | Audit trail | 9 of 9, 100% | Complete |
| 8 | Cache and performance | 1 of 1 applicable, 100% | Complete |
| 9 | Security hardening | 10 of 10, 100% | Complete |
| 10 | Developer experience and documentation | 13 of 13, 100% | Complete |
| 11 | Internal pilot | 4 of 7, approximately 57% | In progress |
| 12 | Existing-application integration | 0% | Not started |
| 13 | Stable release | 0% | Not started |
| 14 | Post-v1 optional features | — | Optional |

### 3.4 Internal alpha fast track

The immediate delivery target is `v0.1.0-alpha` for a controlled, Oracle-first
internal pilot. The alpha is intended to make the package usable by the team;
it is not a stable-release or full Tier 1 compatibility claim.

The alpha critical path is deliberately limited to:

1. complete Laravel authorization integration through the existing resolver;
2. accept and harden the audit behavior already implemented by the mutation
   layer, including append-only enforcement;
3. pass one fresh-consumer Oracle vertical slice covering installation, role
   and permission creation, assignment, authorization, revocation and audit;
4. close release-blocking security findings for that supported slice;
5. publish the minimum installation and usage guidance needed for the pilot;
6. tag a reproducible `v0.1.0-alpha` release for internal Composer use.

The following work does not block the internal alpha unless the pilot exposes a
direct dependency on it:

- the SQL Server schema inspector and SQL Server application-runtime evidence;
- cross-request caching and performance optimization beyond the existing
  request-local memoization;
- the complete diagnostics command set;
- existing-application migration tooling;
- public package publication and the stable `1.0.0` release checklist.

Deferred work remains part of the roadmap. The fast track changes sequencing,
not the accepted compatibility or stable-release requirements.

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

**Initial ADR acceptance froze no API.** It settled the boundary and authorised
the ordered correction increments without itself changing source or completing
Phase 4. The corrections, documentation and independent review later completed,
and final maintainer acceptance froze that boundary on 2026-09-04 as recorded
under Increment 10 below.

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

ADR 0014 **Increment 7 of 10** was completed on 2026-08-27. The new authoritative
`docs/PUBLIC_API.md` lists the exact 39 Tier 1 and 13 Tier 2 symbols and documents
their consumer signatures, readable fields, model and lifecycle-scope extension
surface, event and exception contracts, the `ClockInterface` testing seam and
the six standard `InvalidArgumentException` sites. The reflection safeguard now
requires the document's 52-symbol manifest to equal the source manifest and
behaviorally pins all six validation sites. Correction 15 is complete. This
documentation increment changes no roadmap deliverable or global percentage.

ADR 0014 **Increment 8 of 10** was completed on 2026-08-27. `README.md` now
states GateTrail's purpose, its pre-alpha and pre-v1 status, the supported
runtime and database evidence, installation and auto-discovery, the
`gatetrail-config` publish tag, both provisioning modes, a first mutation and a
first effective-permission check, and the `@internal` warning. The new
`docs/CONSUMER_GUIDE.md` covers the same ground in depth through model and guard
setup, every implemented mutation, the resolvers, half-open assignment periods,
human and system actors, the three audit modes, `MutationResult` interpretation,
generic `GateTrailEvent` listening and the public exception boundary. Both
documents confine every Phase 6 to Phase 10 surface to an explicit unimplemented
section, and `tests/Documentation/ConsumerGuideTest.php` executes the documented
first flow and rejects unsupported examples. Correction 14 is complete. **At
that point the public API was not frozen**: Increment 9's independent review and
Increment 10's Phase 4 acceptance were outstanding, so Phase 4 stayed at
**19 of 20 = 95%**, Phase 5 at **100%**, overall equal-phase progress at
**approximately 41%**, and the deliverable tracker at **12 of 32,
approximately 38%**.

ADR 0014 **Increment 9 of 10** was completed on 2026-09-04. The independent
[pre-v1 freeze review](docs/reviews/0003-pre-v1-public-api-freeze-review.md)
verified every blocking correction, the exact 39 Tier 1 plus 13 Tier 2
source/document manifest, and that planned Phase 6 through Phase 13 surfaces
are never presented as available. It found no documentation leak or runtime
defect. One safeguard gap was corrected so the consumer-documentation test
detects both multiline and one-line class-level `@internal` PHPDoc and asserts
coverage of all 157 Tier 4 symbols. **At that point the API remained unfrozen
and Phase 4 remained 19 of 20 = 95% pending Increment 10 acceptance.** Overall
and deliverable percentages were unchanged.

ADR 0014 **Increment 10 of 10** was completed on 2026-09-04. The maintainer
accepted Review 0003 and froze the documented 39-symbol Tier 1 plus 13-symbol
Tier 2 boundary under the pre-v1 change policy. Phase 4 is now **20 of 20 =
100%** and `Mutation services` becomes the thirteenth completed global
deliverable. Overall equal-phase progress is (100 + 100 + 100 + 80 + 100 + 100
+ 0×8) / 14 = 41.429%, still reported as **approximately 41%**. The global
deliverable tracker is **13 of 32 = 40.625%, approximately 41%**. Tier 4 remains
unsupported; future phases may add classified API, while a deliberate pre-v1
breaking change to the frozen boundary requires an ADR, changelog entry and
migration guidance when applicable.

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
contribute no row to that matrix: PostgreSQL now has a live PostgreSQL 14
endpoint for release `0001` DDL and read-only schema inspection, but no
resolver, mutation, audit or concurrency path has executed against it, and SQL
Server's live endpoint covers release `0001` DDL only.

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
- [x] Versioned DBA-managed DDL artifacts for every Tier 1 database.
- [x] Models and relationships.
- [x] Explicit indexes, foreign keys, and constraints in the SQLite reference schema.
- [x] `acl_assignment_locks` coordination model or query component.
- [x] Assignment period scopes.
- [x] Soft deletion for roles and permissions.
- [x] No normal hard deletion or cascading deletion of assignments.
- [ ] Read-only schema inspection and automated schema-level integration
      evidence for every Tier 1 database adapter.

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
- The PostgreSQL compiler maps release `0001` to named primary, unique,
  foreign-key and check constraints, `GENERATED BY DEFAULT AS IDENTITY`,
  second-precision timestamps, deterministic `C` collation for exact strings,
  and separate index statements. Install and rollback artifacts are generated
  reproducibly from the canonical manifest with parent-first creation and
  child-first removal.
- A disposable local PostgreSQL 14 endpoint accepts the complete release `0001`
  install, produces all seven tables and 24 total indexes including
  constraint-backed indexes, returns an identity-generated ID, preserves an
  explicit ID, and returns to zero GateTrail tables after rollback.
- The PostgreSQL catalog reader is read-only by construction. It issues five
  `SELECT` statements against `pg_catalog` and `information_schema` — the
  current schema, the seven configured tables, their columns, their indexes and
  their constraints, with generated-key metadata projected from the same column
  rows rather than read again — and never emits `CREATE`, `ALTER`, `DROP`,
  `COMMENT`, `GRANT`, `SET`, repair SQL or an advisory lock. The configured
  schema and all seven table names are bound, never interpolated, and each has
  already passed the `SchemaContext` identifier boundary. All vendor-specific
  PostgreSQL SQL lives in that one class.
- The strict PostgreSQL inspector reports missing tables, unexpected and missing
  columns, wrong data types, character lengths and nullability, missing and
  unexpected indexes, wrong indexed-column order and invalid index state,
  missing or wrong primary, unique, foreign-key and check constraints, wrong
  referenced tables and columns, `ON DELETE`/`ON UPDATE` drift, generated-key
  drift and exact-comparison collation drift. It fails closed on catalog
  metadata it cannot compare — an expression or partial index, an unresolvable
  index key, an unnamed referential action, and a check constraint outside
  PostgreSQL's `CHECK (...)` form — and repairs nothing.
- Its normalization is deliberate and bounded. Every canonical primary-key,
  unique and foreign-key constraint must be `NOT DEFERRABLE`, because a
  deferrable constraint is enforced only when PostgreSQL chooses to check it —
  transaction commit, for `INITIALLY DEFERRED` — and the mutation and
  concurrency contract requires a duplicate to be rejected at the write rather
  than tolerated until commit. `RESTRICT` and `NO ACTION` are equivalent
  restrictive actions only at that setting, because PostgreSQL can postpone a
  `NO ACTION` check to commit time and `RESTRICT` cannot be postponed the same
  way. `CASCADE`, `SET NULL` and `SET DEFAULT` are drift on
  their own. A foreign key's identity is its referenced schema, table and
  ordered columns together, compared against the schema actually being
  inspected, so a key pointing at an identically named table in another schema
  is reported rather than accepted. Both canonical
  generated-key representations are accepted — the artifacts' `GENERATED BY
  DEFAULT AS IDENTITY` and the migrations' `bigserial` `nextval(...)` default —
  because both auto-assign an identifier and both accept an explicit one, while
  `GENERATED ALWAYS` is drift. A primary key may carry either the manifest name
  or PostgreSQL's `<table>_pkey`, because Laravel's PostgreSQL grammar cannot
  name one; unique, foreign-key and check constraints get no such latitude.
  `timestamp without time zone` is compared with its declared precision, an
  exact-comparison column must declare `C` explicitly rather than inherit a
  database default, PostgreSQL's own check-expression rewrite is normalized on
  both sides, and the nine indexes backing manifest primary-key and unique
  constraints are never counted against the 15 explicit manifest indexes.
- Live PostgreSQL 14 integration passes for both provisioning lanes, kept
  separate so the evidence cannot blur. The package-managed lane migrates,
  inspects clean, and is pinned to the `bigserial` and `<table>_pkey`
  representation it actually installs. The DBA-managed lane installs release
  `0001`, inspects clean, is pinned to the identity and manifest-name
  representation, and confirms 24 indexes of which nine are constraint-backed
  and none is reported as unexpected. Both roll back to zero tables.
- Live drift tests confirm that a missing table, a missing explicit index, a
  changed exact-string collation, a cascading foreign key, a dropped identity
  and a `GENERATED ALWAYS` identity are each reported and left in place. A
  SHA-256 catalog fingerprint taken before and after inspection is unchanged,
  and inspecting twice reports identical issues.
- A connection-level query listener records every statement the live inspector
  executes. All are `SELECT`; the proof itself is verified to fail when a
  mutating statement is introduced. Only SQL text is recorded — bindings are
  never captured, because they carry consumer schema, prefix and table
  identifiers.
- A configured non-default schema and a configured table prefix are both
  exercised live, installing and inspecting clean and reporting drift against
  the physical object names.
- Two fail-open foreign-key gaps found reviewing the first inspection increment
  are closed and covered by live regressions: a key referencing an identically
  named table in another schema, and a `DEFERRABLE` key. Both were accepted as
  canonical before the correction, and the regressions were confirmed to fail
  against the pre-correction comparison.
- The `NOT DEFERRABLE` requirement is now enforced once, in the shared
  constraint path, so it covers primary-key and unique constraints as well as
  foreign keys; an inconsistent `condeferred` without `condeferrable` fails
  closed. Live PostgreSQL 14 regressions cover a deferrable unique constraint
  and a deferrable primary key in both initial states, each confirmed to fail
  against the previous foreign-key-only check.
- This is schema evidence only. **No PostgreSQL application runtime
  compatibility is claimed**: no mutation service, resolver, audit writer or
  concurrency path has executed against PostgreSQL.
- The SQL Server compiler maps release `0001` to `BIGINT IDENTITY(1,1)`
  generated keys, `DATETIME2(0)` timestamps, Unicode `NVARCHAR`/`NCHAR`/
  `NVARCHAR(MAX)` character storage, `Latin1_General_100_BIN2` on
  exact-comparison columns, explicitly named primary, unique, foreign-key and
  check constraints, and `ON DELETE NO ACTION ON UPDATE NO ACTION` — SQL
  Server's spelling of the restrictive action the other drivers write as
  `RESTRICT`. Install and rollback artifacts are generated reproducibly from the
  canonical manifest with parent-first creation and child-first removal, and
  carry no `GO` batch separator, so they execute through any ordinary client.
- A disposable local SQL Server 2022 database, whose default collation was the
  common case-insensitive `SQL_Latin1_General_CP1_CI_AS`, accepts the complete
  release `0001` install in a single batch. Catalog verification confirms seven
  tables, fifteen explicit indexes with their canonical ordered columns, seven
  primary keys, two unique constraints, four foreign keys and seven check
  constraints; every foreign key `NO ACTION` and every foreign-key and check
  constraint enabled and trusted; all timestamp columns `DATETIME2` scale 0;
  every character column Unicode with only exact-comparison columns carrying the
  binary collation; six identity columns; and no object name over 30 characters.
  An insert without an identifier returns a server-generated key, case-sensitive
  comparison holds despite the case-insensitive database default, an orphan
  foreign key is refused, and rollback removes all seven tables.
- SQL Server evidence stops at the schema. **There is no SQL Server schema
  inspector** — `SchemaInspectorFactory` still refuses `sqlsrv` — and no
  mutation, resolver, audit or concurrency path has executed against it. The
  missing inspector is the one unchecked item in this phase's 11-item atomic
  checklist, so Phase 3 is 10 of 11, approximately 91%.

Production database adapters are not marked verified until their integration
suites pass. The MySQL minimum and current 8.0 endpoints are verified locally
and in the remote CI matrix. All four Oracle provisioning and generated-key
combinations now have current-version local evidence across both supported
Laravel majors, but the minimum Oracle version remains open. PostgreSQL now has
a compiler, versioned artifacts and a live-verified read-only inspector, but no
application behavioral runtime evidence. SQL Server now has a schema adapter, a
compiler and versioned artifacts with live schema-level evidence, which
completes the DBA-artifact deliverable for every Tier 1 database; its schema
inspector and all of its application behavioral evidence remain open.

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
- [x] Consumer documentation and pre-v1 API freeze.

```text
Completed: 20
Total:     20
Progress:  100%
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
and accepted. The consumer documentation and pre-v1 API freeze are also
accepted, so every Phase 4 atomic item is complete.

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

The source contract remains pre-v1 while GateTrail is unreleased:

- all six assignment mutations accepted by ADR 0002 are now implemented,
  bound, and tested;
- the contract is part of the frozen pre-v1 Tier 1 boundary;
- custom `AssignmentManager` implementations may rely on its documented
  signature under ADR 0014's pre-v1 change policy;
- this freeze does not imply `v1.0.0`; later phases and release validation
  remain outstanding.

### Exit criteria

Every supported mutation uses one managed path and produces deterministic,
audited, transactional behavior.

Status: **Complete**

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
mutation committed in another process does not reach it. Phase 6 has since
started and is now complete; its evidence is recorded in the Phase 6 section.

**The engine boundary, stated precisely.** SQLite carries the full behavioural
matrix on both Laravel/Testbench lanes. Live MySQL 8.0.16 and 8.0.45 carry the
four-lane resolver matrix. Live Oracle Free 23.26.1 carries the targeted
resolver matrix on both dependency lanes. **Neither PostgreSQL nor SQL Server
carries any live behavioural evidence.** PostgreSQL's live PostgreSQL 14
endpoint proves release `0001` DDL and read-only schema inspection and nothing
more — no resolver, mutation, audit or concurrency path has executed there — and
SQL Server's live SQL Server 2022 endpoint proves release `0001` DDL only, with
no schema inspector yet. No runtime compatibility is claimed for either from
compiling a statement, executing DDL or inspecting a schema. Their live behavioural harnesses remain future work,
exactly as ADR 0012 section 12.1 requires.

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

- [x] `HasGateTrail` concern.
- [x] Subject convenience methods.
- [x] Central Laravel Gate hook.
- [x] `Gate::forUser()` behavior.
- [x] Native `@can` behavior.
- [x] Six route middleware aliases.
- [x] Authentication and authorization exception behavior.
- [x] Policy coexistence.

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

```text
Completed: 8
Total:     8
Progress:  100%
```

The centralized Gate hook resolves lazily within the current container scope
and delegates argument-free permission abilities to `PermissionResolver`.
Tests cover `$user->can()`, `Gate::forUser()`, `Gate::authorize()`, native Blade
`@can` / `@cannot`, unauthenticated denial, exact definition matching,
deactivated-definition denial, application-Gate fall-through, resource-policy
coexistence, and propagation of configuration and database failures. No
individual database permission is registered as a Laravel ability.

The six stable route aliases are also implemented through thin Tier 1
middleware classes. Their shared evaluators load one effective-access snapshot
per evaluation, preserve exact-name semantics, reject invalid route parameters,
and use Laravel's native authentication and authorization exceptions. Tests
cover all six aliases, one/any/all behavior, real route execution, generic 403
responses, alias collisions, constant query count and unrelated-Gate isolation.

This completes the global `Gate integration` and `Middleware` deliverables.
The completed `HasGateTrail` Tier 2 extension surface also closes the global
`Contracts` deliverable. The global tracker is now **16 of 32 = 50%**. With
Phase 6 complete, overall equal-phase progress is **approximately 49%**.

The concern delegates singular permission and role checks to the public
resolvers. Batch role checks normalize all inputs, read one effective snapshot
and deny empty lists. Effective collections remain immutable value objects;
the three raw Eloquent relations are documented as history/administration
views and never authorization evidence.

Status: **Complete**

## 14. Phase 7 — Audit trail

### Objective

Persist complete, safe, append-only records for managed authorization
mutations.

### Deliverables

- [x] Human actor persistence.
- [x] System actor persistence.
- [x] Reason enforcement.
- [x] Before and after snapshots.
- [x] Safe contextual metadata.
- [x] Sensitive-field filtering.
- [x] Append-only enforcement.
- [x] Strict audit mode.
- [x] Audit transaction tests.

### Exit criteria

Every managed mutation is attributable and reconstructable without exposing
configured sensitive values.

### Acceptance evidence

The shared `ExercisesEveryMutation` matrix executes all fourteen managed
events. `NonStrictAuditModeTest` proves strict and non-strict modes persist the
same canonical records, while `DisabledAuditModeTest` proves the disabled mode
does not touch audit storage. Handler tests cover both human and system actors,
reason validation, full before/after snapshots, safe context and redaction.
`AuditedMutationTransactionTest` and `StrictMutationTransactionTest` prove
commit, rollback, caller-owned transaction and failure behavior.

Persisted `AccessAudit` instances reject Eloquent update, delete, increment,
decrement and quiet-operation variants. `AccessAuditAppendOnlyTest` pins that
model boundary; direct database administration remains governed by consumer
database privileges and explicit retention policy.

```text
Completed: 9
Total:     9
Progress:  100%
```

This completes the global `Audit trail` deliverable. The global tracker is now
**17 of 32 = 53.125%, approximately 53%**. With Phase 7 complete, overall
equal-phase progress is **approximately 57%**.

Status: **Complete**

## 15. Phase 8 — Cache and performance

### Objective

Optimize a correct, measured resolver without changing authorization behavior.

The cache-decision threshold was registered before the first benchmark run in
[the resolver benchmark protocol](docs/performance/resolver-benchmark.md).

### Sequence

1. Measure uncached resolver queries and latency.
2. Keep request-level memoization.
3. Decide whether cross-request cache is necessary.
4. Record the cache strategy in an ADR.
5. Implement deterministic invalidation.
6. Ensure entries do not outlive relevant assignment expiry.
7. Compare cached and uncached authorization parity.

### Deliverables

- [x] Reproducible benchmark.
- **Closed as not required:** cache ADR.
- **Closed as not required:** cross-request guard-aware subject cache keys.
- **Closed as not required:** invalidation matrix.
- **Closed as not required:** expiry-safe TTL.
- **Closed as not required:** stampede and stale-access tests.
- **Closed as not required:** after-commit cross-request cache invalidation.
  This item moved here from Phase 4 through
  [ADR 0013](docs/decisions/0013-after-commit-cache-invalidation.md), accepted
  2026-08-19, and remains unimplemented because the benchmark did not retain a
  cross-request cache.

### Exit criteria

Every performance claim has a reproducible benchmark and caching cannot grant
access beyond uncached resolver behavior.

If step 3 concludes that no cross-request cache is necessary, every
cache-conditional deliverable above — all of them except the benchmark itself —
is closed as **not required** rather than implemented, and GateTrail ships with
request-local memoization as its only cache-like behaviour.

### Acceptance evidence

The threshold was committed before measurement in the
[resolver benchmark protocol](docs/performance/resolver-benchmark.md). The
isolated live MySQL 8.0.45 run measured 30 cold samples and 3,000 warm
operations per workload through the existing connection-level statement probe.
The representative workload passed at 6.069 ms cold p95 and 0.009 ms warm p95,
with three cold selects and zero warm statements. The heavier 50-role,
500-permission workload remained informational and measured 16.082 ms cold p95.

Request-local memo parity, lifecycle-boundary rebuilding, transaction bypass
and same-process mutation clearing remain covered by Phase 5. No cross-request
cache exists, so it cannot grant stale access.

```text
Applicable deliverables completed: 1
Applicable deliverables total:     1
Conditional deliverables closed:   6, not required
Progress:                           100%
```

This completes the global `Performance benchmark` deliverable. The global
tracker is now **18 of 32 = 56.25%, approximately 56%**. With Phase 8 complete,
overall equal-phase progress is **approximately 64%**.

Status: **Complete**

## 16. Phase 9 — Security hardening

### Objective

Review abuse paths and prove that secure defaults fail closed.

### Deliverables

- [x] Threat model.
- [x] Mutation authorization guidance or hook.
- [x] SQL injection regression tests.
- [x] Cross-subject and cross-guard tests.
- [x] Concurrency and retry tests.
- [x] Audit tampering review.
- [x] Sensitive audit filtering tests.
- [x] Alias-collision diagnostics.
- [x] Security reporting policy.
- [x] Static-analysis baseline with no unexplained high-risk findings.

### Exit criteria

No known critical or high-severity issue remains and every security default is
documented and tested.

Acceptance evidence:

- `docs/security/threat-model.md` records assets, trust boundaries, threats,
  mitigations, evidence and residual risks. It makes explicit that mutation
  actors provide attribution while the consumer remains responsible for
  authorizing the operation before calling a manager.
- `tests/Security/MutationInputBindingTest.php` proves that a SQL-shaped
  permission name is bound and stored literally without entering generated SQL
  or damaging the permissions table.
- Existing resolver, mutation, concurrency, audit, middleware and integration
  suites cover default deny, cross-subject and cross-guard isolation, bounded
  retries, append-only audit persistence, sensitive-value redaction and visible
  alias collisions.
- The supported local dependency set was updated until `composer audit`
  reported no known advisories. CI now rejects known dependency advisories on
  every push, and PHPStan continues to pass at level max without an unexplained
  high-risk finding.
- `SECURITY.md` defines the private reporting channel and supported-version
  policy for the pre-release package.

```text
Applicable deliverables completed: 10
Applicable deliverables total:     10
Progress:                           100%
```

This completes the global `Security tests` and `Security review` deliverables.
The global tracker is now **20 of 32 = 62.5%**. With Phase 9 complete, overall
equal-phase progress is **approximately 71%**.

Status: **Complete**

## 17. Phase 10 — Developer experience and documentation

### Objective

Make installation, correct usage, diagnostics, and extension points
understandable without reading package internals.

### Deliverables

- [x] Complete README.
- [x] Installation guide.
- [x] Configuration reference.
- [x] Public manager API guide.
- [x] Role and permission naming guide.
- [x] Lifecycle and audit guide.
- [x] DBA-managed installation guides for every Tier 1 database.
- [x] SQL Server setup guide.
- [x] Upgrade and migration guides.
- [x] `gatetrail:install`.
- [x] `gatetrail:doctor`.
- [x] `gatetrail:matrix`.
- [x] Minimal example application.

### Exit criteria

A developer unfamiliar with the package can install it in a fresh Laravel
application, run diagnostics, assign access, authorize a route, and understand
the resulting audit records.

Readiness evidence is recorded in
`docs/reviews/0004-phase-10-documentation-readiness.md`. The accepted baseline
covers installation through first use, upgrades in both provisioning modes and
the implemented public surface. `gatetrail:install` now safely publishes config
and provisions only package-managed schemas. `gatetrail:doctor` now exposes
read-only schema and data diagnostics with human and JSON output.
`gatetrail:matrix` now reports local runtime and configured-driver readiness
alongside the exact recorded evidence boundary without connecting to a
database. `examples/minimal-app` is a separately installed Laravel 13 consumer
whose CI vertical slice proves package discovery, migration, public mutations,
Gate and route authorization, revocation, retained history and audit evidence.

```text
Applicable deliverables completed: 13
Applicable deliverables total:     13
Progress:                           100%
```

With Phase 10 complete, overall equal-phase progress is **approximately 78%**.

Status: **Complete**

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

- [x] Pre-release package tag.
- [x] Private Composer installation path.
- [x] Pilot test plan.
- [x] Pilot defect log.
- [ ] Performance observations.
- [ ] Developer feedback.
- [ ] Pilot retrospective.

### Exit criteria

The pilot uses GateTrail without application-specific changes inside the
package, and all release-blocking findings are resolved.

Current evidence:

- annotated tag `v0.1.0-alpha.1` points to commit `830b8d5` and is available on
  the private Git remote;
- `docs/PRIVATE_COMPOSER_INSTALLATION.md` defines exact-tag installation,
  credential boundaries and fresh-versus-existing-application activation;
- `docs/pilot/TEST_PLAN.md` defines the staged, coexistence-first pilot sequence
  and its entry, pass and exit criteria;
- `docs/pilot/DEFECT_LOG.md` defines finding severity, release-blocker rules,
  status vocabulary and the evidence-safe entry format.

```text
Applicable deliverables completed: 4
Applicable deliverables total:     7
Progress:                           approximately 57%
```

With Phase 11 at approximately 57%, overall equal-phase progress is
**approximately 82%**.

Status: **In progress**

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
- [x] Contracts
- [ ] Assignment lifecycle
- [x] Mutation services
- [x] Effective permission resolver
- [x] Gate integration
- [x] Middleware
- [x] Audit trail
- [ ] Cache invalidation

Progress: **8 of 10**

### Quality

- [ ] Unit tests
- [ ] Integration tests
- [x] Security tests
- [x] Concurrency tests
- [ ] Verified compatibility matrix
- [x] Performance benchmark

Progress: **3 of 6**

### Delivery

- [x] Complete README
- [x] Full user documentation
- [x] Artisan diagnostics
- [ ] Internal pilot
- [ ] Pilot retrospective
- [ ] Existing-application parity study
- [x] Security review
- [ ] `v1.0.0`

Progress: **3 of 8**

### Total

```text
Completed: 22
Total:     32
Progress:  71.875%, reported as approximately 72%
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

# Changelog

All notable changes to GateTrail will be documented in this file.

## Unreleased

### Added

- Internal, immutable diagnostic finding and report primitives for the planned
  read-only `gatetrail:doctor` command. They pin stable severity, privacy-safe
  references, deterministic output ordering, coverage reporting and the rule
  that an incomplete diagnostic run can never report a clean or successful
  result. No command or database scan is exposed by this foundation increment.
- A safe `gatetrail:install` command. It publishes configuration without
  overwriting an existing file, runs only GateTrail migrations in
  package-managed mode, supports a no-migration setup path, preserves Laravel's
  production confirmation boundary, and never runs DDL in DBA-managed mode.
- A pre-v1 upgrade and migration guide covering package-managed and DBA-managed
  deployments, published-config reconciliation, Oracle generated-key strategy,
  rollback boundaries, post-upgrade verification and the evidence required of
  future schema releases.
- An exhaustive consumer configuration reference covering every supported key,
  default, accepted value, validation boundary, failure mode and deployment
  consideration.
- A documented security baseline for the internal alpha: a maintained threat
  model, private vulnerability-reporting policy, explicit consumer-side
  mutation authorization guidance, SQL-binding regression coverage and a CI
  dependency-advisory gate. Existing default-deny, guard isolation,
  concurrency, append-only audit, redaction and alias-collision evidence is now
  indexed from the threat model rather than left implicit across the suite.
- A reproducible, isolated live-MySQL resolver benchmark with pre-registered
  cache-decision thresholds. The accepted MySQL 8.0.45 run passed at 6.069 ms
  cold p95 and 0.009 ms warm p95 for the representative workload, with three
  cold selects and zero warm statements. Cross-request caching is therefore
  not required for the internal alpha; request-local memoization remains the
  only cache-like behavior.
- Central Laravel authorization integration for argument-free GateTrail
  permission abilities, including `$user->can()`, `Gate::forUser()`,
  `Gate::authorize()` and native Blade `@can` / `@cannot`. Six stable route
  middleware aliases now provide singular, any and all permission and role
  checks through the same effective-access snapshots. The six middleware
  classes and `InvalidMiddlewareParameter` are additive Tier 1 API; invalid
  route declarations fail visibly, authorization denials remain generic, and
  alias collisions are never silently overwritten.
- The Tier 2 `HasGateTrail` concern now exposes its complete nine-method
  subject API. Singular checks delegate to the public resolvers, batch role
  checks evaluate one snapshot and fail closed for an empty list, effective
  collections return the existing value objects, and three explicitly raw
  Eloquent relations expose direct definitions and assignment history without
  becoming authorization or mutation APIs.
- SQL Server release `0001` DBA-managed DDL artifacts, which completes versioned
  artifacts for every Tier 1 database. An internal `SqlServerDdlCompiler`
  compiles the canonical manifest for the `sqlsrv` driver and
  `composer ddl:generate` writes
  `database/ddl/sqlserver/0001/{install,rollback}.sql` reproducibly. The mapping
  is `BIGINT IDENTITY(1,1)` generated keys, `DATETIME2(0)` timestamps, Unicode
  `NVARCHAR`/`NCHAR`/`NVARCHAR(MAX)` character storage,
  `Latin1_General_100_BIN2` pinned on exact-comparison columns rather than left
  to a commonly case-insensitive database default, explicitly named primary,
  unique, foreign-key and check constraints, and `ON DELETE NO ACTION ON UPDATE
  NO ACTION`. Identifiers are bracketed one component at a time, so a configured
  schema yields `[schema].[table]`. No `CASCADE`, `SET NULL`, `SET DEFAULT`,
  existence guard, trigger, dynamic SQL or `GO` batch separator is emitted, so
  the files execute through any ordinary client. The artifacts have passed a
  live install-and-rollback smoke test on SQL Server 2022. This is schema-level
  evidence only: SQL Server still has **no schema inspector**, and no mutation,
  resolver, audit or concurrency path has run against it.
- Read-only PostgreSQL schema inspection. `SchemaInspectorFactory` now resolves
  the `pgsql` driver to a strict inspector that compares an installed schema
  with the canonical manifest and **reports** differences without creating,
  altering, dropping, repairing or locking anything. Every statement it issues
  is a `SELECT` against `pg_catalog` and `information_schema`, and the
  configured schema and table names are bound rather than interpolated. It
  reports missing tables, unexpected and missing columns, wrong data types,
  character lengths and nullability, missing and unexpected indexes, wrong
  indexed-column order and index state, missing or wrong primary, unique,
  foreign-key and check constraints, wrong referenced tables and columns,
  `ON DELETE`/`ON UPDATE` drift, generated-key drift and exact-comparison
  collation drift, and fails closed on catalog metadata it cannot compare.
  Both canonical PostgreSQL representations of the same contract are accepted:
  `RESTRICT` and `NO ACTION` on a `NOT DEFERRABLE` foreign key;
  `GENERATED BY DEFAULT AS IDENTITY` and `bigserial`; the manifest primary-key
  name and PostgreSQL's `<table>_pkey`.
  Live PostgreSQL 14 tests cover both provisioning modes, seeded drift, a
  query-listener proof that only reads are executed, a configured non-default
  schema and a configured table prefix. This is schema evidence only —
  PostgreSQL application runtime compatibility is still not claimed.

### Fixed

- Laravel 12 can no longer route multi-column increment/decrement calls on an
  access-audit model around GateTrail's append-only model hook and into an
  Eloquent builder. Explicit compatibility shims now reject all four `*Each`
  variants on both supported Laravel majors, with version-neutral regression
  coverage for every increment/decrement and quiet-operation path.
- Deferrable PostgreSQL constraints are now rejected for every canonical
  constraint type, not only foreign keys. The `NOT DEFERRABLE` requirement moved
  into the shared constraint-inspection path, so a `DEFERRABLE` primary-key,
  unique or foreign-key constraint reports `constraint_state_mismatch` in both
  the `INITIALLY IMMEDIATE` and `INITIALLY DEFERRED` states, and the diagnostic
  says which. Immediate enforcement is required because a deferrable unique
  constraint would let two equivalent active assignments coexist until commit,
  which is the race GateTrail's assignment coordination exists to prevent. A
  `condeferred` flag reported without `condeferrable` — a state PostgreSQL
  cannot produce — fails closed instead of passing. Check constraints are
  untouched, because PostgreSQL cannot make them deferrable.
- Two fail-open gaps in PostgreSQL foreign-key inspection, found reviewing the
  increment above and closed before either shipped. A foreign key's identity now
  includes the schema it references, so a key resolving to an identically named
  table in another schema is reported as `foreign_reference_mismatch` instead of
  being accepted on its table name alone; the expected namespace is the schema
  actually being inspected, never a hardcoded `public`. And `RESTRICT` and
  `NO ACTION` are now treated as equivalent only while the constraint is
  `NOT DEFERRABLE` — PostgreSQL can postpone a `NO ACTION` check to commit time,
  which `RESTRICT` cannot be postponed the same way, so a `DEFERRABLE` foreign
  key is reported as `constraint_state_mismatch` regardless of its action. A
  non-deferrable `NO ACTION` key, which Laravel's PostgreSQL grammar can emit,
  still passes.

### Changed

- Persisted `AccessAudit` model instances now enforce the documented
  append-only boundary by rejecting Eloquent update, delete, increment,
  decrement and quiet-operation variants. Direct database administration is
  still governed by consumer database privileges and retention policy.
- PostgreSQL release `0001` DBA artifacts are now generated reproducibly from
  the canonical schema manifest. The compiler uses named constraints,
  `GENERATED BY DEFAULT AS IDENTITY`, deterministic exact-string collation,
  dependency-safe install/rollback order and separate index statements. A
  local PostgreSQL 14 smoke test covers installation, generated and explicit
  IDs, and rollback; runtime compatibility is not yet claimed.
- The MySQL catalog-reader test now uses `sqlsrv` as its unsupported-driver
  example, because `pgsql` is no longer unsupported. The MySQL DDL-compiler test
  likewise moved its no-compiler example to `sqlite`, because `sqlsrv` now has
  one. Neither change alters MySQL behaviour.
- The documented pre-v1 API boundary is now frozen: 39 Tier 1 symbols and 13
  Tier 2 extension points. Accidental drift is rejected by the reflection
  safeguard; deliberate breaking change before `1.0.0` requires an ADR,
  changelog entry and migration guidance when applicable. Tier 4 internals
  remain unsupported, and future phases may add newly classified API.
- `RoleManager::create()` and `PermissionManager::create()` now describe their
  result as the configured Eloquent model implementing the corresponding marker
  contract, instead of incorrectly promising the package's default model.
- `GateTrailEvent` now declares `context(): array`, and
  `AuditPersistenceFailed` implements the contract. A listener registered for
  `GateTrailEvent` therefore receives both successful mutation events and audit
  persistence failure events.
- Constructors used only by GateTrail are now private or explicitly marked
  `@internal`; consumers should obtain results and events from package APIs and
  catch exceptions produced by their named factories.
- A configured `access_audit` model must now extend `Models\AccessAudit`, which
  guarantees GateTrail connection/table resolution and the absence of an
  `updated_at` write.
- The inherited connection/table behavior of the six supported package models,
  the seven valid table keys, and all four assignment-period scopes are now
  documented and regression-tested as extension-surface constraints.
- All 157 Tier 4 implementation symbols now carry a class-level `@internal`
  annotation. This changes IDE and static-analysis visibility only; no runtime
  behavior or supported symbol is changed.
- A reflection-based boundary test now rejects unclassified source symbols,
  missing Tier 4 annotations and drift in the accepted Tier 1 and Tier 2
  signatures, readable fields, constants, enum values and constructors.
- Container and event-listener safeguards now pin all five public service
  bindings, the resolver scope lifecycle and generic `GateTrailEvent` delivery
  for both mutation-commit and audit-persistence-failure events.
- The package specification now records all 25 implemented exceptions in their
  accepted Tier 1 or operational category, labels the two planned Phase 6
  exceptions and middleware as unimplemented, and reflects the working disabled
  audit mode.
- `docs/PUBLIC_API.md` now provides the authoritative pre-v1 Tier 1 and Tier 2
  inventory, exact consumer signatures, model and scope extension surface,
  event and exception fields, the supported clock seam, and standard argument
  validation behavior. Automated tests require its 52-symbol manifest to match
  the reflected source boundary.
- `README.md` now introduces the package, its pre-alpha and pre-v1 status,
  the supported runtimes and database evidence, installation and
  auto-discovery, the `gatetrail-config` publish tag, both provisioning modes,
  a first mutation, a first effective-permission check, and the warning that
  `@internal` symbols are not consumer API.
- The new `docs/CONSUMER_GUIDE.md` documents installation, configuration
  publishing, package-managed and DBA-managed provisioning, subject and model
  setup, every implemented mutation and check, half-open assignment periods,
  human and `SystemActor` examples, the strict, non-strict and disabled audit
  modes, `MutationResult` interpretation, generic `GateTrailEvent` listening
  and the public exception boundary. Both documents keep every unimplemented
  Phase 6 to Phase 10 surface labelled as future work.
- Documentation tests execute the documented first mutation-and-check flow and
  reject an example that fails to parse, names a `Default*` concrete, reaches
  for an `@internal` symbol, passes a named argument no reflected signature
  accepts, or presents an unavailable API as implemented.
- The documentation guard now recognizes both multiline and one-line
  class-level `@internal` PHPDoc and asserts that all 157 Tier 4 symbols are
  covered. The independent pre-v1 freeze review found no unsupported API in
  consumer examples; this review does not itself freeze the API.

### Migration guidance

- Catch-all `GateTrailEvent` listeners that read mutation-specific fields must
  first narrow the event with `instanceof MutationCommitted`. Generic listeners
  may call `context()` directly on the contract.
- Do not construct `AuditPersistenceFailed`, `ActorIdentity`, GateTrail
  exceptions, or authentic mutation results in consumer code. Their construction
  is package-controlled and is not part of the pre-v1 compatibility promise.
- Change a custom audit model that extends Eloquent `Model` directly to extend
  `HangTuah\GateTrail\Models\AccessAudit` instead. Custom role and permission
  models remain independent Eloquent models when they satisfy their existing
  marker-contract and `SoftDeletes` requirements.

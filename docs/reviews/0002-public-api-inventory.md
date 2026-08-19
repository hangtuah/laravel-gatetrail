# GateTrail Public API Inventory

## Review record

| Item | Value |
|---|---|
| Review identifier | `0002` |
| Review date | 2026-08-19 |
| Commit inventoried | `cf456b2` |
| Phase | 4 — Mutation services |
| Phase 4 item | Consumer documentation and pre-v1 API freeze |
| Increment | 1 of N — public API inventory |
| Result | **Evidence produced. No API frozen.** |
| Runtime changes | **None.** This increment changed no source, test, config, migration or workflow file |

This document is **input to** a freeze decision. It is not the freeze, it does
not accept a boundary, and it does not bind the maintainers to any
classification below. Every category in this document is **preliminary**.

## 1. Executive summary

GateTrail's `src/` tree declares **218 symbols**. Composer PSR-4 makes all 218
autoloadable, and Laravel's container can build most of them. That is
reachability, not support.

The inventory proposes this preliminary split:

| Category | Meaning | Count | Share |
|---|---|---:|---:|
| **A** | Supported public API candidate | 39 | 17.9% |
| **B** | Consumer extension point | 14 | 6.4% |
| **C** | Framework integration surface | 4 | 1.8% |
| **D** | Diagnostic or operational surface | 43 | 19.7% |
| **E** | Internal implementation | 116 | 53.2% |
| **F** | Undecided — needs maintainer approval | 2 | 0.9% |
| | **Total** | **218** | **100%** |

Six findings shape the freeze that follows.

**One. The annotation boundary is roughly half-built.** 74 symbols carry a
class-level `@internal` tag, 4 carry one only on a member, and **140 carry
none**. The gap is not random: every `Mutations\*Handler` and
`Mutations\Commands\*` is annotated, but `Managers\DefaultAssignmentManager`,
`Managers\DefaultRoleManager`, `Managers\DefaultPermissionManager`,
`Access\DefaultPermissionResolver`, `Access\DefaultRoleResolver`, all eleven
`Mutations\*InputResolver` classes, both `Guards\*` resolvers and the entire
`Database\Schema\*` tree are unannotated. Under this increment's proposal, 116
symbols belong in E and 74 are tagged, so roughly **50 internal symbols are
currently indistinguishable from supported API** by annotation alone.

**Two. The public manager contracts name concrete default models in their
generic return types, and `ModelRegistry` does not require those models.**
`RoleManager::create()` is annotated `@return MutationResult<Role>`, and
`PermissionManager::create()` `@return MutationResult<Permission>`. But
`ModelRegistry::role()` requires only `Model` + `RoleContract` + `SoftDeletes`
— it does **not** require the configured class to extend
`HangTuah\GateTrail\Models\Role`. A consumer may therefore configure a role
model that satisfies every runtime check while violating the declared generic.
The same contract annotates `update()`, `deactivate()` and `restore()` as
`MutationResult<Model&RoleContract>`, which is correct. The two styles disagree
inside one interface. This is a real defect, not a stylistic one, and it is
recorded in section 16 rather than fixed here.

**Three. `README.md` is two lines long.** It contains a title and a one-line
description, no installation step, no configuration step and **no consumer
example of any kind**. The Phase 4 item is *consumer documentation and* API
freeze; on the documentation half, the starting point is zero.

**Four. `PACKAGE_SPEC.md` section 12.2 promises a subject API that does not
exist.** It lists `hasRole()`, `hasAnyRole()`, `hasAllRoles()`,
`hasPermissionTo()`, `effectivePermissions()`, `roles()`,
`directPermissions()`, `roleAssignments()` and `permissionAssignments()` on the
consumer subject. `Concerns\HasGateTrail` declares exactly one method,
`gateTrailGuardNameFromDeclaredProperty()`, and it is `@internal`. The spec
labels the list "Proposed", so this is a Phase 6 surface — but it is the single
most likely place for a reader to believe a shipped API exists.

**Five. The `Diagnostics` pair is the clearest case for a separate stability
policy.** `GateTrailStatementProbe` and `StatementMeasurement` are already
`@internal` ("Test and diagnostic use only"), yet the query-budget behavior they
measure is a documented ADR 0012 guarantee that a consumer might reasonably want
to assert in their own suite. "Internal" and "useful to consumers" are both true.

**Six. Two symbols cannot be classified from evidence alone.**
`Support\ModelRegistry` and `Database\Schema\SchemaContext` are container-bound,
carry no `@internal`, have entirely read-shaped public methods, and are reached
by consumer-extended models through inheritance. Both are recorded as F.

## 2. Scope and methodology

### What was inspected

1. Every `.php` file under `src/` — 218 files, one declared symbol each.
2. All 11 `Contracts\*` interfaces, read in full, including docblocks.
3. `GateTrailServiceProvider` — every binding, lifecycle, listener and publish.
4. `config/gatetrail.php` in full.
5. `composer.json` autoload, `extra.laravel.providers`, and requirements.
6. `README.md` in full (2 lines).
7. `PACKAGE_SPEC.md` sections 3.4, 3.7, 3.8, 10.6, 10.7, 12.1, 12.2, 13.6, 15,
   16, 18 and 22.
8. ADRs 0002, 0003, 0005, 0007, 0008, 0010, 0011, 0012, 0013 (statuses and
   public-surface consequences).
9. `tests/` — 180 files; container-resolution and reflection usage sampled.
10. Every `@internal` occurrence, separated into class-level and member-level.
11. `database/migrations/`, `database/ddl/`, `tools/generate-ddl-artifacts.php`.

### How symbols were counted

The count is **218**, established two independent ways that agree:

- `find src -name '*.php'` returns 218 files;
- a regex over every file for `class|interface|enum|trait` declarations returns
  218 declarations, with **zero duplicate fully-qualified names**.

Counting rules, stated because they change the number:

- **One symbol per file.** Every file declares exactly one top-level type. No
  file declares two.
- **Anonymous classes are not counted.** `grep -r 'new class' src` returns
  **0 occurrences**, so this rule excludes nothing in practice.
- **Imported symbols are not counted.** A `use Illuminate\...` statement makes a
  foreign symbol visible inside a file; it does not make it a GateTrail symbol.
  Only types whose namespace begins `HangTuah\GateTrail` are inventoried.
  Framework and PSR types that appear in GateTrail's public signatures —
  `Illuminate\Database\Eloquent\Model`, `Illuminate\Support\Collection`,
  `Psr\Clock\ClockInterface`, `DateTimeImmutable`, `DateTimeInterface` — are
  **not** symbols of this package, but they *are* part of its compatibility
  surface. They are analysed in section 14 instead of being counted here.
- **Nested and used traits are not double-counted.** A trait is counted once,
  where it is declared. `Models\Role` uses `Illuminate\...\SoftDeletes` and
  `Models\RoleAssignment` uses `Models\Concerns\HasAssignmentPeriodScopes`; the
  first is foreign and uncounted, the second is counted once at its declaration.
  The package declares exactly 2 traits.
- **Enum cases and constants are not symbols.** They are compatibility surface
  and are listed under their owning type in sections 10 and 11.

Breakdown by kind: **192 classes, 16 interfaces, 8 enums, 2 traits**.

### How categories were assigned

Each symbol received exactly one preliminary category. Assignment used, in
order: an explicit per-symbol decision where evidence was specific; then a
namespace rule; then the default of E. The rule set and the resulting
assignment were generated mechanically so that the inventory in section 4 is
provably complete and duplicate-free — 218 rows, 218 distinct names.

**A symbol was not made public merely because** it has public methods, is
container-bound, is instantiated by tests, or must be `public` for PHP or
Laravel to work. Those four facts are true of most of the 116 E symbols.

## 3. Reachable versus supported

This distinction is the whole point of the increment, so it is stated plainly.

> **"Public in PHP" is not the same as "supported public API."**

PSR-4 autoloading makes all 218 symbols loadable by any consumer. PHP has no
package-private visibility, so a class the package intends for its own use is
syntactically indistinguishable from one it supports. Laravel's container will
autowire nearly any of them on request. None of that is a promise.

A **supported** API is one the maintainers commit to keeping stable, document
for consumers, and change only through an explicit, recorded process. That
commitment is a maintainer decision, and this document does not make it.

Four consequences follow, and they hold regardless of which boundary is
eventually adopted:

1. **No API is frozen by this inventory.** Every classification here is
   preliminary and subject to maintainer approval.
2. **No existing consumer guarantee is removed by this inventory.** Nothing
   here narrows a promise that `PACKAGE_SPEC.md` section 22 already makes.
3. **Phases 6 through 13 may add APIs.** A boundary drawn over currently
   implemented behavior does not forbid additive surface later.
4. **After a later freeze, a supported API should not change silently.** If the
   eventual policy adopts that rule, an intentional breaking change before
   `1.0.0` should require an ADR, a changelog entry and migration guidance.
   Section 17 proposes this; it does not enact it.

## 4. Complete symbol inventory by namespace

All 218 symbols, grouped by namespace, each appearing exactly once. `Cat` is the
preliminary category. `Consumer reach` records how a consumer could obtain the
symbol today: `signature` (named in a public contract signature), `container`
(explicitly bound in the service provider), `construction` (constructor is
public), `model config` (reachable through `gatetrail.models`), `event`
(delivered by dispatch), `catch` (an exception type), `migration/DBA`
(provisioning or schema workflow), `trait use`, `implement/listen`, or
`autowire only` (no explicit binding; the container can still build it).

### `(root)` — 10 symbols

| Symbol | Kind | Cat | Evidence | Consumer reach | `@internal` | Recommended | Risk if misclassified |
|---|---|:-:|---|---|---|---|---|
| `AssignmentPeriod` | class | A | Optional `$period` on three `AssignmentManager` methods; spec 10.6. | signature | **no** | A | Scheduling input; change breaks every scheduled assignment call. |
| `AssignmentReference` | class | A | Accepted in four `AssignmentManager` revoke unions. | signature | **no** | A | Ambiguous-ID escape hatch; removal strands UUID/ULID consumers. |
| `GateTrailServiceProvider` | class | C | `composer.json` `extra.laravel.providers`; publishes `gatetrail-config`. | construction, migration/DBA | **no** | C | Binding and publish-tag changes break installs. |
| `MutationOutcome` | enum | A | Public `$outcome` field of `MutationResult`; spec 10.7 lists the six cases. | autowire only | **no** | A | Enum cases are matched in consumer `match`/`switch`. |
| `MutationResult` | class | A | Return type of all 14 public manager methods; spec 10.7. | signature, construction | **no** | A | Every mutation call site reads it. |
| `PermissionChanges` | class | A | Required second argument of `PermissionManager::update()`. | signature | **no** | A | Same as `RoleChanges`. |
| `PermissionReference` | class | A | Accepted in `AssignmentManager`/`PermissionManager` unions. | signature | **no** | A | Same as above for permissions. |
| `RoleChanges` | class | A | Required second argument of `RoleManager::update()`. | signature | **no** | A | Only way to express a nullable description patch. |
| `RoleReference` | class | A | Accepted in `AssignmentManager`/`RoleManager` role unions. | signature | **no** | A | Same as above for roles. |
| `SystemActor` | class | A | `Model` or `SystemActor` `$actor` on all 14 manager methods. | signature | **no** | A | Only supported non-human actor representation. |

### `Access` — 13 symbols

| Symbol | Kind | Cat | Evidence | Consumer reach | `@internal` | Recommended | Risk if misclassified |
|---|---|:-:|---|---|---|---|---|
| `DefaultPermissionResolver` | class | E | Implementation detail behind a public boundary. | container, construction | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `DefaultRoleResolver` | class | E | Implementation detail behind a public boundary. | container, construction | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `EffectiveAccessMemo` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `EffectiveAccessMemoKey` | class | E | Implementation detail behind a public boundary. | autowire only | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `EffectiveAccessQueryBuilder` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `EffectiveAccessResolver` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `EffectiveAccessRowMapper` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `EffectiveAccessSnapshot` | class | E | Implementation detail behind a public boundary. | autowire only | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `EffectivePermission` | class | A | Element type of `PermissionResolver::effectivePermissions()`; spec 11.7. | signature | member only | A | Read fields feed UI and API projections. |
| `EffectiveRole` | class | A | Element type of `RoleResolver::effectiveRoles()`; spec 11.7. | signature | member only | A | Same as `EffectivePermission`. |
| `FlushEffectiveAccessMemo` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `RequestedDefinition` | class | E | Implementation detail behind a public boundary. | autowire only | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `RequestedDefinitionNormalizer` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |

### `Assignments` — 12 symbols

| Symbol | Kind | Cat | Evidence | Consumer reach | `@internal` | Recommended | Risk if misclassified |
|---|---|:-:|---|---|---|---|---|
| `AssignmentConflict` | class | E | Implementation detail behind a public boundary. | construction | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `AssignmentConflictQuery` | class | E | Implementation detail behind a public boundary. | container, construction | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `AssignmentDecision` | class | E | Implementation detail behind a public boundary. | autowire only | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `AssignmentDecisionMaker` | class | E | Implementation detail behind a public boundary. | container, construction | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `AssignmentDecisionOutcome` | enum | E | Implementation detail behind a public boundary. | autowire only | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `AssignmentKind` | enum | E | Implementation detail behind a public boundary. | autowire only | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `AssignmentLockStore` | class | E | Implementation detail behind a public boundary. | container, construction | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `AssignmentPeriodOverlap` | class | E | Implementation detail behind a public boundary. | construction | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `AssignmentScope` | class | E | Implementation detail behind a public boundary. | autowire only | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `AssignmentScopeProbe` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `EffectiveAssignmentPeriod` | class | E | Implementation detail behind a public boundary. | autowire only | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `RevocationCoordinator` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |

### `Audit` — 16 symbols

| Symbol | Kind | Cat | Evidence | Consumer reach | `@internal` | Recommended | Risk if misclassified |
|---|---|:-:|---|---|---|---|---|
| `AccessAuditWriter` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `AuditContextBuilder` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `AuditFailureReporter` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `AuditMode` | enum | E | Implementation detail behind a public boundary. | autowire only | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `AuditModeResolver` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `CanonicalAuditJson` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `MetadataNormalizer` | class | E | Implementation detail behind a public boundary. | container, construction | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `NormalizedMetadata` | class | E | Implementation detail behind a public boundary. | construction | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `PendingAuditRecord` | class | E | Implementation detail behind a public boundary. | construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `PermissionAssignmentSnapshotBuilder` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `PermissionSnapshotBuilder` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `RoleAssignmentSnapshotBuilder` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `RolePermissionSnapshotBuilder` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `RoleSnapshotBuilder` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `SensitiveAuditValueRedactor` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `SensitiveMetadataRedactor` | class | E | Implementation detail behind a public boundary. | container, construction | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |

### `Concerns` — 1 symbols

| Symbol | Kind | Cat | Evidence | Consumer reach | `@internal` | Recommended | Risk if misclassified |
|---|---|:-:|---|---|---|---|---|
| `HasGateTrail` | trait | B | Spec 12.1 tells consumers to `use` it on subject models; guard property path depends on it. | trait use | member only | B | Spec 12.2 promises a subject API this trait does not have. |

### `Concurrency` — 8 symbols

| Symbol | Kind | Cat | Evidence | Consumer reach | `@internal` | Recommended | Risk if misclassified |
|---|---|:-:|---|---|---|---|---|
| `ClassifiedConcurrencyFailure` | class | E | Implementation detail behind a public boundary. | autowire only | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `CoordinationInsertRace` | class | E | Implementation detail behind a public boundary. | autowire only | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `CoordinationPlan` | class | E | Implementation detail behind a public boundary. | construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `CoordinationRaceClassifier` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `DriverDiagnostics` | class | E | Implementation detail behind a public boundary. | autowire only | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `RetryOrchestrator` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `RetryPolicy` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `TransientFailureClassifier` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |

### `Contracts` — 11 symbols

| Symbol | Kind | Cat | Evidence | Consumer reach | `@internal` | Recommended | Risk if misclassified |
|---|---|:-:|---|---|---|---|---|
| `AssignmentManager` | interface | A | Bound singleton; spec 10.6; ADR 0002. | signature, container, implement/listen | **no** | A | Primary assignment API. |
| `GateTrailEvent` | interface | A | Marker implemented by `MutationCommitted`; listeners register against it. | signature, implement/listen | **no** | A | Listening by interface is the documented fan-in point. |
| `PermissionAssignmentContract` | interface | B | Consumers implement, extend, configure, or `use` it. | signature, implement/listen | **no** | B | Silent change breaks every consumer subclass or configured model. |
| `PermissionContract` | interface | B | Consumers implement, extend, configure, or `use` it. | signature, implement/listen | **no** | B | Silent change breaks every consumer subclass or configured model. |
| `PermissionManager` | interface | A | Bound singleton; spec 10.6; ADR 0002. | signature, container, implement/listen | **no** | A | Primary permission-definition API. |
| `PermissionResolver` | interface | A | Bound scoped; spec 11.3; ADR 0012. | signature, container, implement/listen | **no** | A | Every authorization check. |
| `RoleAssignmentContract` | interface | B | Consumers implement, extend, configure, or `use` it. | signature, implement/listen | **no** | B | Silent change breaks every consumer subclass or configured model. |
| `RoleContract` | interface | B | Consumers implement, extend, configure, or `use` it. | signature, implement/listen | **no** | B | Silent change breaks every consumer subclass or configured model. |
| `RoleManager` | interface | A | Bound singleton; spec 10.6; ADR 0002. | signature, container, implement/listen | **no** | A | Primary role-definition API. |
| `RolePermissionContract` | interface | B | Consumers implement, extend, configure, or `use` it. | signature, implement/listen | **no** | B | Silent change breaks every consumer subclass or configured model. |
| `RoleResolver` | interface | A | Bound scoped; spec 11.5; ADR 0012. | signature, container, implement/listen | **no** | A | Every role check. |

### `Database` — 1 symbols

| Symbol | Kind | Cat | Evidence | Consumer reach | `@internal` | Recommended | Risk if misclassified |
|---|---|:-:|---|---|---|---|---|
| `UniqueConstraintViolationClassifier` | class | E | Implementation detail behind a public boundary. | container, construction, migration/DBA | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |

### `Database\Migrations` — 3 symbols

| Symbol | Kind | Cat | Evidence | Consumer reach | `@internal` | Recommended | Risk if misclassified |
|---|---|:-:|---|---|---|---|---|
| `AssignmentTableMigration` | class (abs) | C | Base of the three assignment-table migrations. | migration/DBA | **no** | C | Same as `GateTrailMigration`. |
| `GateTrailMigration` | class (abs) | C | Base class of all seven package migrations; `shouldRun()` honours DBA mode. | migration/DBA | **no** | C | Consumers may extend it for their own GateTrail-aware migrations. |
| `OracleMigrationExecutor` | class | C | Executes Oracle statement batches during package migrations. | construction, migration/DBA | **no** | C | Oracle provisioning depends on it. |

### `Database\Schema` — 4 symbols

| Symbol | Kind | Cat | Evidence | Consumer reach | `@internal` | Recommended | Risk if misclassified |
|---|---|:-:|---|---|---|---|---|
| `IdentifierValidator` | class | E | Implementation detail behind a public boundary. | container, construction, migration/DBA | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `SchemaAdapter` | interface | E | Implementation detail behind a public boundary. | migration/DBA | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `SchemaAdapterFactory` | class | E | Implementation detail behind a public boundary. | container, construction, migration/DBA | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `SchemaContext` | class | F | Bound singleton; `GateTrailModel::getConnectionName()`/`getTable()` call it; no `@internal`. | container, construction, migration/DBA | **no** | F | Consumer model subclasses depend on it indirectly. |

### `Database\Schema\Adapters` — 6 symbols

| Symbol | Kind | Cat | Evidence | Consumer reach | `@internal` | Recommended | Risk if misclassified |
|---|---|:-:|---|---|---|---|---|
| `AbstractSchemaAdapter` | class (abs) | E | Implementation detail behind a public boundary. | migration/DBA | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `MySqlAdapter` | class | E | Implementation detail behind a public boundary. | construction, migration/DBA | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `OracleAdapter` | class | E | Implementation detail behind a public boundary. | construction, migration/DBA | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `PostgreSqlAdapter` | class | E | Implementation detail behind a public boundary. | construction, migration/DBA | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `SQLiteAdapter` | class | E | Implementation detail behind a public boundary. | construction, migration/DBA | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `SqlServerAdapter` | class | E | Implementation detail behind a public boundary. | construction, migration/DBA | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |

### `Database\Schema\Ddl` — 3 symbols

| Symbol | Kind | Cat | Evidence | Consumer reach | `@internal` | Recommended | Risk if misclassified |
|---|---|:-:|---|---|---|---|---|
| `DdlArtifactGenerator` | class | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | construction, migration/DBA | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |
| `DdlCompiler` | interface | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | migration/DBA | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |
| `DdlCompilerFactory` | class | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | container, construction, migration/DBA | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |

### `Database\Schema\Ddl\MySql` — 1 symbols

| Symbol | Kind | Cat | Evidence | Consumer reach | `@internal` | Recommended | Risk if misclassified |
|---|---|:-:|---|---|---|---|---|
| `MySqlDdlCompiler` | class | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | construction, migration/DBA | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |

### `Database\Schema\Ddl\Oracle` — 3 symbols

| Symbol | Kind | Cat | Evidence | Consumer reach | `@internal` | Recommended | Risk if misclassified |
|---|---|:-:|---|---|---|---|---|
| `OracleDdlCompiler` | class | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | construction, migration/DBA | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |
| `OracleGeneratedKeyStrategy` | enum | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | migration/DBA | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |
| `OracleSchemaOptions` | class | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | migration/DBA | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |

### `Database\Schema\Inspection` — 5 symbols

| Symbol | Kind | Cat | Evidence | Consumer reach | `@internal` | Recommended | Risk if misclassified |
|---|---|:-:|---|---|---|---|---|
| `InspectionSeverity` | enum | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | migration/DBA | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |
| `SchemaInspection` | class | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | construction, migration/DBA | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |
| `SchemaInspector` | interface | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | migration/DBA | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |
| `SchemaInspectorFactory` | class | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | container, construction, migration/DBA | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |
| `SchemaIssue` | class | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | construction, migration/DBA | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |

### `Database\Schema\Inspection\MySql` — 7 symbols

| Symbol | Kind | Cat | Evidence | Consumer reach | `@internal` | Recommended | Risk if misclassified |
|---|---|:-:|---|---|---|---|---|
| `InformationSchemaMySqlCatalogReader` | class | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | construction, migration/DBA | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |
| `MySqlCatalogReader` | interface | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | migration/DBA | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |
| `MySqlCatalogSnapshot` | class | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | construction, migration/DBA | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |
| `MySqlColumnMetadata` | class | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | construction, migration/DBA | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |
| `MySqlConstraintMetadata` | class | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | construction, migration/DBA | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |
| `MySqlIndexMetadata` | class | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | construction, migration/DBA | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |
| `MySqlSchemaInspector` | class | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | construction, migration/DBA | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |

### `Database\Schema\Inspection\Oracle` — 10 symbols

| Symbol | Kind | Cat | Evidence | Consumer reach | `@internal` | Recommended | Risk if misclassified |
|---|---|:-:|---|---|---|---|---|
| `AllViewsOracleCatalogReader` | class | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | construction, migration/DBA | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |
| `OracleCatalogReader` | interface | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | migration/DBA | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |
| `OracleCatalogSnapshot` | class | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | construction, migration/DBA | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |
| `OracleColumnMetadata` | class | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | construction, migration/DBA | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |
| `OracleConstraintMetadata` | class | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | construction, migration/DBA | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |
| `OracleIdentityMetadata` | class | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | construction, migration/DBA | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |
| `OracleIndexMetadata` | class | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | construction, migration/DBA | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |
| `OracleSchemaInspector` | class | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | construction, migration/DBA | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |
| `OracleSequenceMetadata` | class | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | construction, migration/DBA | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |
| `OracleTriggerMetadata` | class | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | construction, migration/DBA | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |

### `Database\Schema\Manifest` — 7 symbols

| Symbol | Kind | Cat | Evidence | Consumer reach | `@internal` | Recommended | Risk if misclassified |
|---|---|:-:|---|---|---|---|---|
| `ColumnDefinition` | class | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | construction, migration/DBA | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |
| `ColumnType` | enum | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | migration/DBA | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |
| `ConstraintDefinition` | class | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | construction, migration/DBA | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |
| `ConstraintType` | enum | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | migration/DBA | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |
| `IndexDefinition` | class | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | construction, migration/DBA | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |
| `SchemaManifest` | class | D | Bound singleton; canonical schema for migrations, DDL artifacts and inspection. | container, construction, migration/DBA | **no** | D | Shared by package-managed and DBA-managed paths. |
| `TableDefinition` | class | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | construction, migration/DBA | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |

### `Diagnostics` — 2 symbols

| Symbol | Kind | Cat | Evidence | Consumer reach | `@internal` | Recommended | Risk if misclassified |
|---|---|:-:|---|---|---|---|---|
| `GateTrailStatementProbe` | class | D | Query-budget instrumentation; ADR 0012 section 8. | construction | yes (class) | D | Consumer test suites may assert budgets with it. |
| `StatementMeasurement` | class | D | Return value of `GateTrailStatementProbe::measure()`. | autowire only | yes (class) | D | Counts read by budget assertions. |

### `Events` — 4 symbols

| Symbol | Kind | Cat | Evidence | Consumer reach | `@internal` | Recommended | Risk if misclassified |
|---|---|:-:|---|---|---|---|---|
| `AuditPersistenceFailed` | class | A | Dispatched on non-strict post-commit audit failure; spec 13.6, 16. | construction, event | **no** | A | Operational alerting depends on its shape. |
| `MutationCommitted` | class | A | Dispatched after every changed mutation; ADR 0010; spec 16. | event | member only | A | Payload fields are read by listeners, queues and log sinks. |
| `MutationEventDispatcher` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `SafeMutationContext` | class | E | Implementation detail behind a public boundary. | construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |

### `Exceptions` — 25 symbols

| Symbol | Kind | Cat | Evidence | Consumer reach | `@internal` | Recommended | Risk if misclassified |
|---|---|:-:|---|---|---|---|---|
| `AssignmentNotActive` | class | A | Consumer-facing: named in a contract signature, dispatched, or thrown across a public boundary. | catch | **no** | A | Silent change breaks consumer code. |
| `AssignmentScopeCollision` | class | A | Consumer-facing: named in a contract signature, dispatched, or thrown across a public boundary. | construction, catch | **no** | A | Silent change breaks consumer code. |
| `AuditWriteFailed` | class | A | Consumer-facing: named in a contract signature, dispatched, or thrown across a public boundary. | construction, catch | **no** | A | Silent change breaks consumer code. |
| `ConcurrencyRetryExhausted` | class | A | Consumer-facing: named in a contract signature, dispatched, or thrown across a public boundary. | catch | **no** | A | Silent change breaks consumer code. |
| `DdlCompilerUnavailable` | class | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | construction, catch | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |
| `DuplicateActiveAssignment` | class | A | Consumer-facing: named in a contract signature, dispatched, or thrown across a public boundary. | catch | **no** | A | Silent change breaks consumer code. |
| `DuplicatePermission` | class | A | Consumer-facing: named in a contract signature, dispatched, or thrown across a public boundary. | catch | **no** | A | Silent change breaks consumer code. |
| `DuplicateRole` | class | A | Consumer-facing: named in a contract signature, dispatched, or thrown across a public boundary. | catch | **no** | A | Silent change breaks consumer code. |
| `GuardMismatch` | class | A | Consumer-facing: named in a contract signature, dispatched, or thrown across a public boundary. | catch | **no** | A | Silent change breaks consumer code. |
| `InvalidActor` | class | A | Consumer-facing: named in a contract signature, dispatched, or thrown across a public boundary. | construction, catch | **no** | A | Silent change breaks consumer code. |
| `InvalidAssignmentPeriod` | class | A | Consumer-facing: named in a contract signature, dispatched, or thrown across a public boundary. | construction, catch | **no** | A | Silent change breaks consumer code. |
| `InvalidAuditConfiguration` | class | A | Consumer-facing: named in a contract signature, dispatched, or thrown across a public boundary. | catch | **no** | A | Silent change breaks consumer code. |
| `InvalidAuditMetadata` | class | A | Consumer-facing: named in a contract signature, dispatched, or thrown across a public boundary. | construction, catch | **no** | A | Silent change breaks consumer code. |
| `InvalidConcurrencyConfiguration` | class | A | Consumer-facing: named in a contract signature, dispatched, or thrown across a public boundary. | catch | **no** | A | Silent change breaks consumer code. |
| `InvalidDatabaseIdentifier` | class | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | construction, catch | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |
| `InvalidGuard` | class | A | Consumer-facing: named in a contract signature, dispatched, or thrown across a public boundary. | catch | **no** | A | Silent change breaks consumer code. |
| `InvalidModelConfiguration` | class | A | Consumer-facing: named in a contract signature, dispatched, or thrown across a public boundary. | construction, catch | **no** | A | Silent change breaks consumer code. |
| `InvalidSubject` | class | A | Consumer-facing: named in a contract signature, dispatched, or thrown across a public boundary. | catch | **no** | A | Silent change breaks consumer code. |
| `MigrationDependencyError` | class | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | construction, catch | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |
| `PermissionNotFound` | class | A | Consumer-facing: named in a contract signature, dispatched, or thrown across a public boundary. | catch | **no** | A | Silent change breaks consumer code. |
| `ReasonRequired` | class | A | Consumer-facing: named in a contract signature, dispatched, or thrown across a public boundary. | construction, catch | **no** | A | Silent change breaks consumer code. |
| `RoleNotFound` | class | A | Consumer-facing: named in a contract signature, dispatched, or thrown across a public boundary. | catch | **no** | A | Silent change breaks consumer code. |
| `SchemaInspectorUnavailable` | class | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | construction, catch | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |
| `UnsupportedAfterCommitBoundary` | class | A | Consumer-facing: named in a contract signature, dispatched, or thrown across a public boundary. | catch | **no** | A | Silent change breaks consumer code. |
| `UnsupportedDatabaseDriver` | class | D | DBA provisioning, schema inspection, or test/diagnostic tooling. | construction, catch | **no** | D | Change breaks DBA workflow or a consumer diagnostic harness. |

### `Guards` — 2 symbols

| Symbol | Kind | Cat | Evidence | Consumer reach | `@internal` | Recommended | Risk if misclassified |
|---|---|:-:|---|---|---|---|---|
| `DefaultGuardResolver` | class | E | Implementation detail behind a public boundary. | container, construction | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `SubjectGuardResolver` | class | E | Implementation detail behind a public boundary. | container, construction | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |

### `Managers` — 3 symbols

| Symbol | Kind | Cat | Evidence | Consumer reach | `@internal` | Recommended | Risk if misclassified |
|---|---|:-:|---|---|---|---|---|
| `DefaultAssignmentManager` | class | E | Implementation detail behind a public boundary. | construction | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `DefaultPermissionManager` | class | E | Implementation detail behind a public boundary. | construction | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `DefaultRoleManager` | class | E | Implementation detail behind a public boundary. | construction | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |

### `Models` — 7 symbols

| Symbol | Kind | Cat | Evidence | Consumer reach | `@internal` | Recommended | Risk if misclassified |
|---|---|:-:|---|---|---|---|---|
| `AccessAudit` | class | B | `models.access_audit` config key. **No marker contract is required.** | construction, model config | **no** | B | Weakest configured-model contract in the package. |
| `GateTrailModel` | class (abs) | B | Abstract base of all six package models; consumers extend it transitively. | model config | **no** | B | Protected `tableKey()` is an inherited obligation. |
| `Permission` | class | B | Consumers implement, extend, configure, or `use` it. | signature, construction, model config | **no** | B | Silent change breaks every consumer subclass or configured model. |
| `PermissionAssignment` | class | B | Consumers implement, extend, configure, or `use` it. | signature, construction, model config | **no** | B | Silent change breaks every consumer subclass or configured model. |
| `Role` | class | B | Consumers implement, extend, configure, or `use` it. | signature, construction, model config | **no** | B | Silent change breaks every consumer subclass or configured model. |
| `RoleAssignment` | class | B | Consumers implement, extend, configure, or `use` it. | signature, construction, model config | **no** | B | Silent change breaks every consumer subclass or configured model. |
| `RolePermission` | class | B | Consumers implement, extend, configure, or `use` it. | signature, construction, model config | **no** | B | Silent change breaks every consumer subclass or configured model. |

### `Models\Concerns` — 1 symbols

| Symbol | Kind | Cat | Evidence | Consumer reach | `@internal` | Recommended | Risk if misclassified |
|---|---|:-:|---|---|---|---|---|
| `HasAssignmentPeriodScopes` | trait | B | Used by the three assignment models; inherited by consumer subclasses. | trait use | **no** | B | `scopeActiveAt`/`scopeScheduledAt` become query API for extenders. |

### `Mutations` — 21 symbols

| Symbol | Kind | Cat | Evidence | Consumer reach | `@internal` | Recommended | Risk if misclassified |
|---|---|:-:|---|---|---|---|---|
| `ActorIdentity` | class | E | Implementation detail behind a public boundary. | construction | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `ActorIdentityResolver` | class | E | Implementation detail behind a public boundary. | container, construction | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `AfterCommitBoundary` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `AssignmentIdentifierNormalizer` | class | E | Implementation detail behind a public boundary. | container, construction | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `AuditedMutationTransaction` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `DeactivatedPermissionDefinitionInputResolver` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `DeactivatedRoleDefinitionInputResolver` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `DefinitionIdentifierNormalizer` | class | E | Implementation detail behind a public boundary. | container, construction | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `DefinitionInputValidator` | class | E | Implementation detail behind a public boundary. | container, construction | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `MutationName` | class | E | Implementation detail behind a public boundary. | construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `MutationReasonValidator` | class | E | Implementation detail behind a public boundary. | container, construction | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `PermissionAssignmentInputResolver` | class | E | Implementation detail behind a public boundary. | container, construction | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `PermissionDefinitionInputResolver` | class | E | Implementation detail behind a public boundary. | container, construction | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `PermissionInputResolver` | class | E | Implementation detail behind a public boundary. | container, construction | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `PreparedMutation` | class | E | Implementation detail behind a public boundary. | construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `RoleAssignmentInputResolver` | class | E | Implementation detail behind a public boundary. | container, construction | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `RoleDefinitionInputResolver` | class | E | Implementation detail behind a public boundary. | container, construction | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `RoleInputResolver` | class | E | Implementation detail behind a public boundary. | container, construction | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `RolePermissionDefinitionResolver` | class | E | Implementation detail behind a public boundary. | container, construction | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `RolePermissionInputResolver` | class | E | Implementation detail behind a public boundary. | container, construction | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `StrictMutationTransaction` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |

### `Mutations\Commands` — 14 symbols

| Symbol | Kind | Cat | Evidence | Consumer reach | `@internal` | Recommended | Risk if misclassified |
|---|---|:-:|---|---|---|---|---|
| `AssignRoleCommand` | class | E | Implementation detail behind a public boundary. | autowire only | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `CreatePermissionCommand` | class | E | Implementation detail behind a public boundary. | autowire only | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `CreateRoleCommand` | class | E | Implementation detail behind a public boundary. | autowire only | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `DeactivatePermissionCommand` | class | E | Implementation detail behind a public boundary. | autowire only | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `DeactivateRoleCommand` | class | E | Implementation detail behind a public boundary. | autowire only | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `GrantDirectPermissionCommand` | class | E | Implementation detail behind a public boundary. | autowire only | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `GrantPermissionToRoleCommand` | class | E | Implementation detail behind a public boundary. | autowire only | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `RestorePermissionCommand` | class | E | Implementation detail behind a public boundary. | autowire only | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `RestoreRoleCommand` | class | E | Implementation detail behind a public boundary. | autowire only | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `RevokeDirectPermissionCommand` | class | E | Implementation detail behind a public boundary. | autowire only | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `RevokePermissionFromRoleCommand` | class | E | Implementation detail behind a public boundary. | autowire only | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `RevokeRoleCommand` | class | E | Implementation detail behind a public boundary. | autowire only | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `UpdatePermissionCommand` | class | E | Implementation detail behind a public boundary. | autowire only | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `UpdateRoleCommand` | class | E | Implementation detail behind a public boundary. | autowire only | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |

### `Mutations\Handlers` — 14 symbols

| Symbol | Kind | Cat | Evidence | Consumer reach | `@internal` | Recommended | Risk if misclassified |
|---|---|:-:|---|---|---|---|---|
| `AssignRoleHandler` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `CreatePermissionHandler` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `CreateRoleHandler` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `DeactivatePermissionHandler` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `DeactivateRoleHandler` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `GrantDirectPermissionHandler` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `GrantPermissionToRoleHandler` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `RestorePermissionHandler` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `RestoreRoleHandler` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `RevokeDirectPermissionHandler` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `RevokePermissionFromRoleHandler` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `RevokeRoleHandler` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `UpdatePermissionHandler` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `UpdateRoleHandler` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |

### `Subjects` — 2 symbols

| Symbol | Kind | Cat | Evidence | Consumer reach | `@internal` | Recommended | Risk if misclassified |
|---|---|:-:|---|---|---|---|---|
| `SubjectIdentity` | class | E | Implementation detail behind a public boundary. | construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
| `SubjectIdentityResolver` | class | E | Implementation detail behind a public boundary. | container, construction | yes (class) | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |

### `Support` — 1 symbols

| Symbol | Kind | Cat | Evidence | Consumer reach | `@internal` | Recommended | Risk if misclassified |
|---|---|:-:|---|---|---|---|---|
| `ModelRegistry` | class | F | Bound singleton; the only resolver of `gatetrail.models`; no `@internal`. | container, construction | **no** | F | Only supported way to learn the configured model class. |

### `Time` — 1 symbols

| Symbol | Kind | Cat | Evidence | Consumer reach | `@internal` | Recommended | Risk if misclassified |
|---|---|:-:|---|---|---|---|---|
| `SystemClock` | class | E | Implementation detail behind a public boundary. | construction | **no** | E + `@internal` | If treated as public, refactoring freedom is lost permanently. |
## 5. Proposed supported public API — category A

39 symbols. Nothing here is accepted; this is the recommendation the freeze
decision should evaluate.

### 5.1 The five service contracts

| Contract | Binding | Lifecycle | Concrete |
|---|---|---|---|
| `Contracts\AssignmentManager` | explicit | singleton | `Managers\DefaultAssignmentManager` |
| `Contracts\RoleManager` | explicit | singleton | `Managers\DefaultRoleManager` |
| `Contracts\PermissionManager` | explicit | singleton | `Managers\DefaultPermissionManager` |
| `Contracts\PermissionResolver` | explicit | **scoped** | `Access\DefaultPermissionResolver` |
| `Contracts\RoleResolver` | explicit | **scoped** | `Access\DefaultRoleResolver` |

`PACKAGE_SPEC.md` section 10.6 names the three managers as "three injectable
public contracts". Section 11.3 and ADR 0012 define the two resolvers as "the
source of truth for every GateTrail permission decision". These five are the
strongest A candidates in the package.

### 5.2 The nine input and output value types

`AssignmentPeriod`, `AssignmentReference`, `RoleReference`,
`PermissionReference`, `RoleChanges`, `PermissionChanges`, `MutationResult`,
`MutationOutcome`, `SystemActor` — all in the root `HangTuah\GateTrail`
namespace. Every one is named directly in a public contract signature. Exact
signatures are in section 10.

### 5.3 The two effective-access value objects

`Access\EffectivePermission` and `Access\EffectiveRole`. Both are the element
type of a public resolver return, both are `final readonly`, and both already
carry a member-level `@internal` on their construction factory while leaving the
type itself unmarked — which is exactly the shape `PACKAGE_SPEC.md` section 22
prescribes for a package-constructed value object.

### 5.4 The event surface

`Events\MutationCommitted`, `Events\AuditPersistenceFailed`, and the
`Contracts\GateTrailEvent` marker they are listened to through.

### 5.5 The twenty consumer-facing exception types

Listed and analysed in section 11.

### 5.6 What is deliberately *not* proposed for A

- **`Managers\Default*Manager` and `Access\Default*Resolver`.** Consumers should
  depend on the contract. See open question 1.
- **`Mutations\MutationName`.** Its constants are the audit and event mutation
  vocabulary, but the class is `@internal` and directs consumers to the audit
  `event` column or `MutationCommitted::$mutation` instead.
- **`Models\*` as *called* API.** The models are proposed as extension points
  (B), not as an API consumers should query directly for authorization.

## 6. Proposed extension points — category B

14 symbols, in three groups.

### 6.1 The five marker contracts

`Contracts\RoleContract`, `PermissionContract`, `RoleAssignmentContract`,
`PermissionAssignmentContract`, `RolePermissionContract`.

All five are **empty interfaces** — zero methods. They exist so
`Support\ModelRegistry` can verify a configured model, and so public manager
signatures can accept "a role model" without naming a concrete class. Because
they declare nothing, implementing one costs a consumer nothing and constrains
the package almost entirely: **adding a single method to any of these five is a
breaking change for every consumer with a custom model.**

### 6.2 The seven configurable models

`Models\Role`, `Permission`, `RoleAssignment`, `PermissionAssignment`,
`RolePermission`, `AccessAudit`, and the abstract `Models\GateTrailModel` they
all extend. Section 13 gives the exact per-model requirements.

### 6.3 The two traits

- **`Concerns\HasGateTrail`** — the consumer subject concern. Spec 12.1 tells
  consumers to `use` it. Today it contributes one `final public` method,
  `gateTrailGuardNameFromDeclaredProperty(): array{defined: bool, value: mixed}`,
  annotated `@internal`. Its only current effect is to let
  `Guards\SubjectGuardResolver` read a `protected string $gateTrailGuardName`
  property. A subject that declares a **public `gateTrailGuardName(): string`
  method instead does not need the trait at all** — the resolver checks
  `method_exists()` before it checks the trait.
- **`Models\Concerns\HasAssignmentPeriodScopes`** — used by the three assignment
  models and therefore inherited by every consumer subclass of them. It
  contributes four query scopes: `scopeActiveAt`, `scopeScheduledAt`,
  `scopeExpiredAt`, `scopeRevoked`, each `(Builder $query, DateTimeInterface
  $time): Builder` except `scopeRevoked`. These become consumer query API by
  inheritance whether or not anyone intends them to.

## 7. Framework integration surfaces — category C

4 symbols. Laravel or Composer must reach these; ordinary consumers do not call
them.

| Symbol | Why it must be reachable |
|---|---|
| `GateTrailServiceProvider` | Named in `composer.json` `extra.laravel.providers`; package discovery instantiates it |
| `Database\Migrations\GateTrailMigration` | Abstract base of all seven package migration files; provides `getConnection()` and `shouldRun()` |
| `Database\Migrations\AssignmentTableMigration` | Abstract base of the three assignment-table migrations |
| `Database\Migrations\OracleMigrationExecutor` | Executes Oracle statement batches during package-managed provisioning |

The provider's observable surface is not its class API but its **effects**:
80 container bindings, one event listener, one publish tag and one conditional
migration load. Section 12 analyses those.

`GateTrailMigration::shouldRun()` is public and is the mechanism by which
DBA-managed provisioning disables package migrations. A consumer writing their
own GateTrail-aware migration could legitimately extend it — which is why it is
C rather than E, and why open question 7 asks whether that is intended.

## 8. Diagnostic and operational surfaces — category D

43 symbols — the second-largest group, and the one most in need of its own
stability policy.

| Group | Count | Contents |
|---|---:|---|
| Schema inspection | 22 | `Database\Schema\Inspection\**` — inspector, factory, severity, issue, result, plus MySQL (7) and Oracle (10) catalog readers and metadata records |
| Schema manifest | 7 | `Database\Schema\Manifest\*` — the canonical table/column/index/constraint definitions and their two enums |
| DDL generation | 7 | `Database\Schema\Ddl\**` — artifact generator, compiler contract and factory, MySQL and Oracle compilers, Oracle key strategy and options |
| Operational exceptions | 5 | `DdlCompilerUnavailable`, `SchemaInspectorUnavailable`, `UnsupportedDatabaseDriver`, `InvalidDatabaseIdentifier`, `MigrationDependencyError` |
| Runtime diagnostics | 2 | `Diagnostics\GateTrailStatementProbe`, `Diagnostics\StatementMeasurement` |

**Why these are not simply internal.** `PACKAGE_SPEC.md` section 3.4 makes
DBA-managed provisioning "a first-class option", section 3.7 requires versioned
install and rollback artifacts that must "remain byte-for-byte reproducible by
the package's artifact generator", and section 3.8 requires read-only schema
inspection so DBA environments "must be inspectable without granting GateTrail
schema mutation privileges". A DBA workflow that the specification calls
first-class is an operational surface, and an operational surface that can be
refactored without notice is not usable by an operations team.

**Why they are not category A either.** No consumer *application code* calls
them for authorization. Their audience is a DBA, a deployment pipeline or a test
harness — a different audience, with different change tolerance, than a
developer calling `assignRole()`.

**Current annotation state is inconsistent within this group.** The two
`Diagnostics` classes carry class-level `@internal`. The 36 schema symbols carry
**none**. Whatever policy is chosen should apply to all 43 the same way.

## 9. Proposed internal implementation surface — category E

116 symbols — 53.2% of the package. Recommended for `@internal` where not
already annotated.

| Namespace group | Count | Nature |
|---|---:|---|
| `Mutations\Handlers\*` | 14 | One use-case handler per managed mutation |
| `Mutations\Commands\*` | 14 | Immutable internal command objects |
| `Mutations\*` (remaining) | 15 | Input resolvers, normalizers, validators, transactions, boundary, prepared mutation, mutation vocabulary |
| `Audit\*` | 16 | Writers, snapshot builders, redactors, normalizers, canonical JSON, pending record, mode |
| `Assignments\*` | 12 | Decision maker, conflict query, lock store, scope probe, revocation coordinator, period overlap, enums |
| `Access\*` | 11 | Default resolvers, memo, memo key, query builder, row mapper, snapshot, flush listener, requested-definition types |
| `Concurrency\*` | 8 | Retry orchestrator and policy, classifiers, coordination plan and race, driver diagnostics |
| `Database\Schema\Adapters\*` + `SchemaAdapter*` + `IdentifierValidator` | 9 | Driver-portable table building for package migrations |
| `Managers\Default*` | 3 | Thin adapters behind the three manager contracts |
| `Events\MutationEventDispatcher`, `Events\SafeMutationContext` | 2 | Event construction and safe payload shaping |
| `Guards\*` | 2 | Subject and default guard resolution |
| `Subjects\*` | 2 | Subject identity and its resolver |
| `Time\SystemClock`, `Database\UniqueConstraintViolationClassifier`, `Models\...` residue | 8 | Remaining single-purpose components |

**ADR 0002 is explicit** that "internal commands and handlers are not public API
and must not contain a second consumer-facing mutation path", and
`PACKAGE_SPEC.md` section 22 says "internal commands, handlers, repositories,
adapters, and orchestration services are not public API". The E proposal is the
mechanical application of both statements.

**The annotation gap inside E is the actionable part.** Of the 116 proposed E
symbols, roughly 50 carry no `@internal` tag today, including every
`Managers\Default*`, both `Access\Default*Resolver`, all eleven
`Mutations\*InputResolver` classes, both `Guards\*` resolvers, and the whole
`Database\Schema\Adapters` tree. A consumer reading the source cannot tell those
apart from the supported contracts.

## 10. Exact public signatures and transitive dependencies

Exceptions are covered in section 11; configurable models in section 13.

### 10.1 `Contracts\AssignmentManager` — 6 methods

```php
assignRole(
    Model $subject,
    RoleContract|RoleReference|string|int $role,
    Model|SystemActor $actor,
    string $reason,
    ?AssignmentPeriod $period = null,
    array $metadata = [],
): MutationResult                      // @return MutationResult<Model&RoleAssignmentContract>

revokeRole(
    RoleAssignmentContract|AssignmentReference|string|int $assignment,
    Model|SystemActor $actor,
    string $reason,
    array $metadata = [],
): MutationResult                      // @return MutationResult<RoleAssignment>

grantDirectPermission(
    Model $subject,
    PermissionContract|PermissionReference|string|int $permission,
    Model|SystemActor $actor,
    string $reason,
    ?AssignmentPeriod $period = null,
    array $metadata = [],
): MutationResult                      // @return MutationResult<Model&PermissionAssignmentContract>

revokeDirectPermission(
    PermissionAssignmentContract|AssignmentReference|string|int $assignment,
    Model|SystemActor $actor,
    string $reason,
    array $metadata = [],
): MutationResult                      // @return MutationResult<PermissionAssignment>

grantPermissionToRole(
    RoleContract|RoleReference|string|int $role,
    PermissionContract|PermissionReference|string|int $permission,
    Model|SystemActor $actor,
    string $reason,
    ?AssignmentPeriod $period = null,
    array $metadata = [],
): MutationResult                      // @return MutationResult<Model&RolePermissionContract>

revokePermissionFromRole(
    RolePermissionContract|AssignmentReference|string|int $assignment,
    Model|SystemActor $actor,
    string $reason,
    array $metadata = [],
): MutationResult                      // @return MutationResult<RolePermission>
```

`$metadata` is `array<string, mixed>` on all six. `$reason` is **required and
non-nullable** on all six, unlike the definition managers.

**Transitive public dependencies:** `Model`, `RoleContract`,
`RoleAssignmentContract`, `PermissionContract`, `PermissionAssignmentContract`,
`RolePermissionContract`, `RoleReference`, `PermissionReference`,
`AssignmentReference`, `SystemActor`, `AssignmentPeriod`, `MutationResult`, and —
through the generic annotations only — the concrete `Models\RoleAssignment`,
`Models\PermissionAssignment`, `Models\RolePermission`.

### 10.2 `Contracts\RoleManager` and `Contracts\PermissionManager` — 4 methods each

```php
create(
    string $name,
    string $guard,
    Model|SystemActor $actor,
    ?string $reason = null,
    ?string $description = null,
    array $metadata = [],
): MutationResult                      // @return MutationResult<Role>  /  <Permission>

update(
    RoleContract|RoleReference|string|int $role,          // permission variant: PermissionContract|…
    RoleChanges $changes,                                  // permission variant: PermissionChanges
    Model|SystemActor $actor,
    ?string $reason = null,
    array $metadata = [],
): MutationResult                      // @return MutationResult<Model&RoleContract>

deactivate(<reference>, Model|SystemActor $actor, ?string $reason = null, array $metadata = []): MutationResult
restore(<reference>,    Model|SystemActor $actor, ?string $reason = null, array $metadata = []): MutationResult
```

`$reason` is **nullable** here and required in `AssignmentManager`. That
asymmetry is intentional and configuration-driven:
`gatetrail.assignments.require_reason` defaults `true`,
`gatetrail.definitions.require_reason` defaults `false`. Both are validated as
real booleans at binding time by `Mutations\MutationReasonValidator`.

Name and guard are immutable — the contracts document that only the nullable
description can change, which is why `RoleChanges` and `PermissionChanges` have
exactly one factory each.

### 10.3 `Contracts\PermissionResolver` and `Contracts\RoleResolver` — 2 methods each

```php
PermissionResolver::allows(Model $subject, PermissionContract|PermissionReference|string|int $permission): bool
PermissionResolver::effectivePermissions(Model $subject): Collection   // Collection<int, EffectivePermission>

RoleResolver::has(Model $subject, RoleContract|RoleReference|string|int $role): bool
RoleResolver::effectiveRoles(Model $subject): Collection               // Collection<int, EffectiveRole>
```

Both interfaces document that the two methods are projections over **one**
evaluated state, that batch checks must resolve one state rather than calling
the singular method per parameter, and that `EffectivePermission` /
`EffectiveRole` are deliberately excluded from the input unions so passing an
output type is a type error rather than a `false`.

`$subject` is `Model`, not `mixed`. The interfaces state that narrowing an
untyped framework subject is a **Phase 6 adapter responsibility** — that adapter
does not exist yet and is not documented here as available.

**Transitive dependency: `Illuminate\Support\Collection`.** It is a framework
type in a GateTrail public return, so GateTrail's compatibility surface includes
Laravel's collection API for these two methods.

### 10.4 The nine value types

| Type | Constructor | Factories / public members | Immutable |
|---|---|---|---|
| `AssignmentPeriod` | **private** | `immediate()`, `until(DateTimeInterface)`, `between(DateTimeInterface, ?DateTimeInterface = null)`; readers `startsAt(): ?DateTimeImmutable`, `expiresAt(): ?DateTimeImmutable`, `isImmediate(): bool` | `final readonly` |
| `AssignmentReference` | **private** | `id(int|string): self`; public field `int|string $id` | `final readonly` |
| `RoleReference` | **private** | `id(int|string): self`; public field `int|string $id` | `final readonly` |
| `PermissionReference` | **private** | `id(int|string): self`; public field `int|string $id` | `final readonly` |
| `RoleChanges` | **private** | `description(?string): self`; public field `?string $description` | `final readonly` |
| `PermissionChanges` | **private** | `description(?string): self`; public field `?string $description` | `final readonly` |
| `MutationResult<TResource of Model>` | **public** | fields `Model $resource`, `MutationOutcome $outcome`, `int|string|null $auditId`; method `changed(): bool` | `final readonly` |
| `MutationOutcome` | — | backed enum `string`: `Created`, `Updated`, `Deactivated`, `Restored`, `Revoked`, `Unchanged` | enum |
| `SystemActor` | **private** | const `TYPE = 'gatetrail.system'`; `named(string): self`; public field `string $name` | `final readonly` |

Observable validation, all of which a consumer can trigger:

- `AssignmentPeriod::between()` throws `InvalidAssignmentPeriod` when the expiry
  is not strictly after the start. All inputs normalize to UTC whole seconds.
- The three reference types throw **`InvalidArgumentException`** (PHP's, not a
  GateTrail type) for an empty string ID or one longer than 191 characters.
- `RoleChanges` / `PermissionChanges` throw `InvalidArgumentException` for
  non-UTF-8 input. Passing `null` clears the description; passing `''` preserves
  it — a semantic a consumer must know and which is documented only in the
  class docblock.
- `SystemActor::named()` throws `InvalidActor` (`emptySystemName`,
  `valueTooLong`), 191-character limit.
- `MutationResult::__construct()` throws `InvalidArgumentException` when
  `Unchanged` is paired with a non-null `$auditId`.

**`MutationResult` is the one public constructor among the nine.** It is public
because it is a generic result envelope, but nothing documents whether consumers
may construct one. See open question 8.

### 10.5 `Events\MutationCommitted`

`final readonly`, implements `Contracts\GateTrailEvent`. Constructor **private**;
`static record(...)` is annotated `@internal`; the docblock states "Consumers
receive instances and never construct them."

Eleven readable fields: `string $mutation`, `MutationOutcome $outcome`,
`string $targetType`, `int|string $targetId`, `?string $subjectType`,
`int|string|null $subjectId`, `string $actorType`, `string $actorId`,
`int|string|null $auditId`, `DateTimeImmutable $occurredAt`,
`?string $requestId`. One public method: `context(): array<string, mixed>`,
which renders a fixed-key-order payload with numeric IDs as decimal strings.

`$mutation` is validated against `Mutations\MutationName::isKnown()`. The
vocabulary is therefore observable through this public field while the class
that defines it is `@internal` — recorded in section 16.

### 10.6 `Events\AuditPersistenceFailed`

`final readonly`, **does not implement `GateTrailEvent`** — the only GateTrail
event that does not. Its docblock explains why: it is an operational event, not
a successful-mutation domain event. A consumer listening on the
`GateTrailEvent` interface will therefore **not** receive it.

Constructor is **public** (no `@internal`). Seven readable fields:
`string $event`, `string $targetType`, `int|string $targetId`,
`?string $subjectType`, `int|string|null $subjectId`, `?string $requestId`,
`string $exceptionClass`. Public static `forRecord(PendingAuditRecord,
Throwable): self` — whose first parameter is `Audit\PendingAuditRecord`, a
class-level `@internal` type. Public method `context(): array<string, mixed>`.

Two problems, both in section 16: a public factory takes an internal type, and
the two events differ in both marker-interface membership and constructor
visibility with no stated reason.

### 10.7 `Access\EffectivePermission` and `Access\EffectiveRole`

Both `final readonly`. Constructor **private**. Named factory
`of(int|string $id, string $name, string $guardName): self` annotated
`@internal Package-controlled construction. Not supported consumer API.`

Three readable fields each: `int|string $id`, `string $name`,
`string $guardName`. No methods beyond the factory. No Eloquent model,
connection or lazy relation is retained.

This matches `PACKAGE_SPEC.md` section 22's rule exactly: the compatibility
surface is what a consumer can **read** — class name, readable fields, types —
and the `@internal` factory is excluded even though PHP makes it callable. These
two are the model the rest of the package should follow.

Source attribution — which role or direct grant produced a permission — is
documented as deliberately outside v1.

## 11. Public exception taxonomy

25 exception classes. **None carries an `@internal` tag.** All extend
`RuntimeException` or `InvalidArgumentException`; none defines a GateTrail base
class, so a consumer cannot catch "any GateTrail exception" in one clause.

### 11.1 Control-flow exceptions escaping public manager or resolver calls — 13

| Exception | Base | Factories | Raised by |
|---|---|---|---|
| `RoleNotFound` | `RuntimeException` | `forGuard`, `forReference` | role input resolution |
| `PermissionNotFound` | `RuntimeException` | `forGuard`, `forReference` | permission input resolution |
| `DuplicateRole` | `RuntimeException` | `forNamespace` | `RoleManager::create()` |
| `DuplicatePermission` | `RuntimeException` | `forNamespace` | `PermissionManager::create()` |
| `DuplicateActiveAssignment` | `RuntimeException` | `forScope` | assignment mutations |
| `AssignmentNotActive` | `RuntimeException` | `forRole`, `forPermission`, `forRolePermission` | revoke paths |
| `AssignmentScopeCollision` | `RuntimeException` | `forScope` | `RevocationCoordinator`, `AssignmentLockStore` |
| `GuardMismatch` | `RuntimeException` | `forRole`, `forPermission`, `forRolePermission` | cross-guard mutation attempts |
| `InvalidGuard` | `InvalidArgumentException` | `forSubject`, `forContext`, `forTarget` | guard resolution |
| `InvalidSubject` | `InvalidArgumentException` | `notPersisted`, `missingIdentifier`, `unsupportedIdentifier`, `mutatedIdentifier`, `invalidMorphType` | reads **and** writes |
| `InvalidActor` | `InvalidArgumentException` | `emptySystemName`, `missingModelKey`, `reservedMorphAlias`, `emptyValue`, `valueTooLong` | actor normalization |
| `ReasonRequired` | `InvalidArgumentException` | `forAssignment`, `forDefinition` | reason validation |
| `InvalidAssignmentPeriod` | `InvalidArgumentException` | `expiryNotAfterStart` | `AssignmentPeriod::between()` |

### 11.2 Audit and concurrency exceptions — 4

| Exception | Base | Notable public surface |
|---|---|---|
| `AuditWriteFailed` | `RuntimeException` | `forEvent`; strict-mode audit failure, rolls the mutation back |
| `ConcurrencyRetryExhausted` | `RuntimeException` | `afterAttempts`, `insideCallerTransaction`; **seven public readonly fields**: `$operation`, `$attempts`, `$callerOwned`, `$driver`, `$category`, `$sqlState`, `$vendorCode` |
| `UnsupportedAfterCommitBoundary` | `RuntimeException` | `forConnection`; ADR 0010 boundary failure |
| `InvalidAuditMetadata` | `InvalidArgumentException` | six factories covering shape, size, depth and reserved markers |

`ConcurrencyRetryExhausted` has the largest readable surface of any GateTrail
exception and is already fully specified by `PACKAGE_SPEC.md` section 15 and
ADR 0011, including `getPrevious()` always being `null`. It is the best-defined
exception contract in the package and a good template for the rest.

### 11.3 Setup and configuration exceptions surfacing through public calls — 3

`InvalidModelConfiguration` (`missing`, `notEloquentModel`, `missingContract`,
`missingSoftDeletes`, `missingBaseModel`) from `Support\ModelRegistry`;
`InvalidAuditConfiguration` (`forKey`) from `Audit\AuditModeResolver`;
`InvalidConcurrencyConfiguration` (`forKey`) from `Concurrency\RetryPolicy`.

These are misconfiguration reports, not domain outcomes — but they surface
through ordinary public manager calls, so a consumer can catch them. They are
proposed as A for that reason, with the taxonomy noting they are not
control-flow.

### 11.4 Operational, migration and schema-tooling exceptions — 5 (category D)

`DdlCompilerUnavailable` (`DdlCompilerFactory`), `SchemaInspectorUnavailable`
(`SchemaInspectorFactory`), `UnsupportedDatabaseDriver`
(`SchemaAdapterFactory`), `InvalidDatabaseIdentifier` (`IdentifierValidator`),
`MigrationDependencyError` (`GateTrailMigration`).

These never arise from an authorization call. `InvalidDatabaseIdentifier` is the
borderline case: `IdentifierValidator` is reached through `SchemaContext`, which
every model touches via `getTable()`, so a malformed table-name configuration
can surface it during an ordinary read. Recorded in section 16.

### 11.5 Specification and source disagreement

`PACKAGE_SPEC.md` section 15 lists 19 exception names. The source has 25.

**Listed in the spec, absent from `src/Exceptions/`:**
`InvalidMiddlewareParameter`, `UnauthorizedMutation`. Both belong to Phase 6
middleware and authorization-gate work that has not started. They must not be
documented as available.

**Present in source, absent from the spec's list:**
`AssignmentScopeCollision`, `DdlCompilerUnavailable`, `InvalidAuditConfiguration`,
`InvalidDatabaseIdentifier`, `InvalidModelConfiguration`,
`MigrationDependencyError`, `SchemaInspectorUnavailable`,
`UnsupportedDatabaseDriver` — eight types. Since section 22 makes exception
types compatibility surface, eight compatibility-surface types are currently
undocumented in the specification.

## 12. Service-container binding analysis

`GateTrailServiceProvider::register()` performs **80 bindings**; `boot()`
registers one listener, one publish tag and one conditional migration load.

### 12.1 Lifecycle split

| Lifecycle | Count | Members |
|---|---:|---|
| `scoped` | 7 | `EffectiveAccessMemo`, `EffectiveAccessResolver`, `DefaultPermissionResolver`, `DefaultRoleResolver`, `PermissionResolver`, `RoleResolver` (+ the two contract closures resolving through their concretes) |
| `singleton` | 73 | everything else, including all 14 handlers, all input resolvers, audit components, concurrency components, schema factories, `ModelRegistry`, `SchemaManifest`, `SchemaContext`, `ClockInterface` |

The scoped set is deliberate and documented in the provider: the memo holds one
scope's authorization answers, and a singleton capturing it would carry one
request's state into the next and never be flushed under Octane or a queue
worker. The two public resolver contracts are scoped one step removed, so a
singleton adapter cannot pin a stale scoped resolver. Each contract resolves
*through* its concrete class rather than being bound separately, so contract and
adapter are one instance inside a scope.

### 12.2 Contract-to-concrete bindings — 6

`AssignmentManager` → `DefaultAssignmentManager`; `RoleManager` →
`DefaultRoleManager`; `PermissionManager` → `DefaultPermissionManager`
(all singleton, class-to-class); `PermissionResolver` →
`DefaultPermissionResolver` and `RoleResolver` → `DefaultRoleResolver` (scoped,
via closure); `Psr\Clock\ClockInterface` → `Time\SystemClock` (singleton).

**The `ClockInterface` binding is an unremarked extension point.** A consumer can
rebind the PSR clock and change every time-dependent GateTrail decision. That may
be desirable for testing, but nothing documents it as supported.

### 12.3 Bindings that make internal services look supported

**This is the leakage risk of the binding list.** 73 of the 80 bindings are for
symbols this inventory proposes as E or D. An explicit `singleton()` registration
reads to most Laravel developers as "this is the service you inject". Concrete
examples: `AssignRoleHandler`, `RoleInputResolver`, `AuditContextBuilder`,
`RetryOrchestrator`, `EffectiveAccessQueryBuilder`, `SubjectIdentityResolver`,
`SchemaAdapterFactory`.

Two mitigating facts: nothing in the specification tells consumers to resolve
them, and the provider's own comments explain that most registrations exist for
the package's explicit-singleton convention rather than for consumers. Neither
fact is visible from an IDE autocomplete list.

**An asymmetry worth a decision:** `DefaultPermissionResolver` and
`DefaultRoleResolver` are bound under their own concrete class names;
`DefaultAssignmentManager`, `DefaultRoleManager` and `DefaultPermissionManager`
are not — they appear only as the concrete argument of a contract binding. The
resolver concretes are therefore container-resolvable by class name in a way the
manager concretes are not. Both remain autowirable, so the difference is one of
signal, not capability.

### 12.4 Event listener, publishing and migrations

- `boot()` registers `MutationCommitted` → `[FlushEffectiveAccessMemo::class,
  'handle']` on the application dispatcher, **before** the console guard, so
  request-local memo coherence holds in web, queue and CLI alike. This is the
  request-local memo clearing of ADR 0012 — same-process bookkeeping, and
  explicitly **not** cross-request cache invalidation, which ADR 0013 leaves as a
  reserved and empty ordering slot deferred to Phase 8.
- One publish tag: **`gatetrail-config`**, publishing `config/gatetrail.php` to
  `config_path('gatetrail.php')`. The tag name is compatibility surface — a
  documented `vendor:publish --tag=gatetrail-config` breaks if it is renamed.
- Migrations load from `database/migrations` **only when**
  `config('gatetrail.database.provisioning') === 'package'`, and only in console.

### 12.5 Configuration keys as compatibility surface

`config/gatetrail.php` declares seven top-level groups: `database`, `tables`,
`models`, `oracle`, `guards`, `assignments`, `definitions`, `audit`,
`sensitive_fields`. Section 22 makes config keys compatibility surface, so the
key names, the seven table names, the six model keys, the six Oracle sequence
and six trigger names, and the seven default `sensitive_fields` entries are all
in scope for a freeze decision. This inventory does not enumerate them further;
that belongs to the configuration half of Increment 2.

## 13. Configurable model compatibility surface

`Support\ModelRegistry` resolves `gatetrail.models` and enforces requirements
that **are themselves a compatibility promise**: every rule below is something a
consumer's custom model must satisfy today and would break on if changed.

| Config key | Default | Must be `Model` | Marker contract | Must extend default | Must use `SoftDeletes` |
|---|---|:-:|---|:-:|:-:|
| `role` | `Models\Role` | yes | `RoleContract` | **no** | **yes** |
| `permission` | `Models\Permission` | yes | `PermissionContract` | **no** | **yes** |
| `role_assignment` | `Models\RoleAssignment` | yes | `RoleAssignmentContract` | **yes** | no |
| `permission_assignment` | `Models\PermissionAssignment` | yes | `PermissionAssignmentContract` | **yes** | no |
| `role_permission` | `Models\RolePermission` | yes | `RolePermissionContract` | **yes** | no |
| `access_audit` | `Models\AccessAudit` | yes | **none** | no | no |

Three asymmetries fall out of this table, and all three are freeze-relevant:

1. **Definition models must use `SoftDeletes` but need not extend the package
   model.** A consumer may supply any Eloquent model implementing `RoleContract`
   with `SoftDeletes`. It need not extend `Models\Role`, need not extend
   `Models\GateTrailModel`, and therefore need not honour
   `SchemaContext`-driven connection and table resolution.
2. **Assignment models must extend the package model but need not use
   `SoftDeletes`.** The opposite rule, for the opposite reason: assignment
   revocation is a `revoked_at` tuple, not a soft delete.
3. **`access_audit` requires nothing but "an Eloquent model".** No marker
   contract exists for it, so a consumer can configure a model with none of the
   audit columns and receive no validation error from the registry.

The inherited surface a custom model gets, and therefore must keep working:

- from `Models\GateTrailModel`: `getConnectionName(): ?string`,
  `getTable(): string`, and the obligation to implement
  `abstract protected tableKey(): string`;
- from the assignment models: `HasAssignmentPeriodScopes`' four query scopes;
- from `Models\Role`: `subjectAssignments()`, `permissionAssignments()`; from
  `Models\Permission`: `subjectAssignments()`, `roleAssignments()`; from
  `Models\RoleAssignment` / `PermissionAssignment`: `subject()` plus `role()` /
  `permission()`; from `Models\RolePermission`: `role()`, `permission()`; from
  `Models\AccessAudit`: `subject()`;
- `$fillable` lists and `casts()` — `Models\Role` casts `created_at`,
  `updated_at`, `deleted_at` to `immutable_datetime`.

**`tableKey()` is the sharpest edge.** It is `abstract protected`, so any
consumer subclassing `GateTrailModel` directly must implement it, and its return
value must be a key `SchemaContext::table()` recognises. That couples a proposed
extension point (B) to a symbol this inventory could not classify (F).

## 14. Public API dependency graph

Transitive reach from a consumer call. Framework and PHP types are marked `[fw]`
— they are not GateTrail symbols but they are in GateTrail's surface.

```text
consumer application
│
├─▶ Contracts\AssignmentManager        (singleton)
│     ├─ in  ▶ Model [fw] · SystemActor · AssignmentPeriod
│     │        RoleContract · PermissionContract · RoleAssignmentContract
│     │        PermissionAssignmentContract · RolePermissionContract
│     │        RoleReference · PermissionReference · AssignmentReference
│     │        string · int · array<string,mixed>
│     ├─ out ▶ MutationResult<T>
│     │          ├─ Model $resource [fw]   ← MUTABLE Eloquent model (§16.8)
│     │          ├─ MutationOutcome $outcome  (6 cases)
│     │          └─ int|string|null $auditId
│     └─ throws ▶ RoleNotFound · PermissionNotFound · DuplicateActiveAssignment
│                 AssignmentNotActive · AssignmentScopeCollision · GuardMismatch
│                 InvalidGuard · InvalidSubject · InvalidActor · ReasonRequired
│                 InvalidAssignmentPeriod · InvalidAuditMetadata · AuditWriteFailed
│                 ConcurrencyRetryExhausted · UnsupportedAfterCommitBoundary
│                 InvalidModelConfiguration · InvalidAuditConfiguration
│                 InvalidConcurrencyConfiguration · InvalidArgumentException [fw]
│
├─▶ Contracts\RoleManager / PermissionManager   (singleton)
│     ├─ in  ▶ … + RoleChanges / PermissionChanges
│     ├─ out ▶ MutationResult<Role> / <Permission>      ← names CONCRETE models (§16.2)
│     └─ throws ▶ DuplicateRole · DuplicatePermission · (as above)
│
├─▶ Contracts\PermissionResolver / RoleResolver  (SCOPED)
│     ├─ in  ▶ Model [fw] · <Role|Permission>Contract · <…>Reference · string · int
│     ├─ out ▶ Collection<int, EffectivePermission> [fw]
│     │        Collection<int, EffectiveRole>       [fw]
│     │          └─ id · name · guardName   (readonly; private ctor; @internal factory)
│     └─ throws ▶ InvalidSubject · InvalidGuard
│
├─▶ events (opt-in listeners)
│     ├─ Contracts\GateTrailEvent  ──implemented by──▶ Events\MutationCommitted
│     │     └─ 11 readonly fields + context(): array
│     │          ├─ MutationOutcome                (public enum)
│     │          ├─ DateTimeImmutable [fw]
│     │          └─ $mutation validated by Mutations\MutationName  ← @internal (§16.1)
│     └─ Events\AuditPersistenceFailed   ← does NOT implement GateTrailEvent (§16.6)
│           └─ 7 readonly fields + context(); public forRecord(PendingAuditRecord …)
│                                                              ← @internal param (§16.1)
│
└─▶ configurable models  (gatetrail.models)
      ├─ Support\ModelRegistry               ← F: undecided (§19 Q11)
      │     └─ enforces Model + marker contract + SoftDeletes / base-model rules
      ├─ Models\GateTrailModel (abstract)
      │     ├─ abstract protected tableKey(): string     ← consumer obligation
      │     └─ app(Database\Schema\SchemaContext)        ← F: undecided (§19 Q12)
      ├─ Models\Role · Permission            (contract + SoftDeletes; base NOT required)
      ├─ Models\RoleAssignment · PermissionAssignment · RolePermission
      │     └─ use Models\Concerns\HasAssignmentPeriodScopes  (4 query scopes)
      └─ Models\AccessAudit                  (no marker contract at all)
```

**What the graph exposes.** A consumer who calls only `assignRole()` transitively
depends on 3 framework types, 12 GateTrail input types, 1 result envelope, 1
enum, a **mutable Eloquent model instance**, and up to 18 throwable types. The
`MutationResult.$resource` edge is the widest: it hands back a live Eloquent
model whose full API — attributes, relations, `save()`, `delete()` — is reachable
from a supported return value.

## 15. Documentation and code mismatches

Every item below was verified against the source at `cf456b2`. None is fixed by
this increment; the permitted file for Increment 1 is this report only.

| # | Claim | Source reality | Severity |
|---|---|---|---|
| M-1 | `README.md` describes the package | 2 lines: a title and a one-line description. **No installation, configuration, or usage example of any kind** | **High** — the Phase 4 item is consumer documentation, and this is the starting point |
| M-2 | Spec 12.2 lists a nine-method subject API (`hasRole`, `hasAnyRole`, `hasAllRoles`, `hasPermissionTo`, `effectivePermissions`, `roles`, `directPermissions`, `roleAssignments`, `permissionAssignments`) | `Concerns\HasGateTrail` declares **one** method, `gateTrailGuardNameFromDeclaredProperty()`, and it is `@internal`. The list is a Phase 6 surface | **High** — the spec marks it "Proposed", but it is the most quotable API list in the document |
| M-3 | Spec 15 lists `InvalidMiddlewareParameter` and `UnauthorizedMutation` | Neither class exists. Both belong to unstarted Phase 6 work | Medium |
| M-4 | Spec 15's exception list is the exception surface | Eight source exceptions are absent from it (section 11.5) | Medium — spec 22 makes exception types compatibility surface |
| M-5 | `Audit\AuditMode::Disabled` docblock: "No audit write is attempted. **Not implemented yet.**" | Disabled auditing **is** implemented: `AuditedMutationTransaction` line 175 routes `AuditMode::Disabled` to `runUnaudited()`, `AuditModeResolver` returns it, and `ROADMAP.md` records "Disabled audit behavior" as complete | Medium — a stale in-source comment contradicting shipped behavior |
| M-6 | `Contracts\RoleManager::create()` — `@return MutationResult<Role>` | `CreateRoleHandler` instantiates `new $class` where `$class = ModelRegistry->role()`, which need not extend `Models\Role` (section 13). Same for `PermissionManager::create()` | **High** — a declared generic the package's own configuration can violate |
| M-7 | Spec 18 lists six console commands | Zero `Console`/`Command` classes exist under `src/`. The spec says "planned across pre-`1.0.0` releases", so this is correctly labelled — recorded so it is not documented as available | Low |
| M-8 | Spec 12.3–12.6 describe Gate integration, policies, middleware and Blade | None exists. Phase 6 | Low — correctly scoped in the spec, listed so Increment 2 does not document it |

Consistency confirmed, not a mismatch: `ROADMAP.md` and `PACKAGE_SPEC.md` both
describe the after-commit cross-request cache invalidation step as a reserved,
currently empty ordering slot conditional on Phase 8, matching accepted ADR 0013.
No cache behavior, config key or public API exists, and none is documented here
as available.

## 16. API leakage and compatibility risks

The eleven risks below map to the analysis requested for this increment.

**16.1 Internal types in proposed public signatures.**
`Events\AuditPersistenceFailed::forRecord(PendingAuditRecord $record, Throwable
$failure)` is a public static method on a proposed-A class taking
`Audit\PendingAuditRecord`, which carries a class-level `@internal`. Separately,
`MutationCommitted::$mutation` is a public field whose legal values are defined
by `Mutations\MutationName`, also `@internal`. In both cases a consumer reading
the public type is pointed at an internal one.

**16.2 Concrete classes consumers must reference because no contract exists.**
`MutationResult` generics name `Models\Role`, `Models\Permission`,
`Models\RoleAssignment`, `Models\PermissionAssignment`, `Models\RolePermission`
directly in `create`, `revokeRole`, `revokeDirectPermission` and
`revokePermissionFromRole`, while `update`, `deactivate`, `restore` and the
three grant methods use `Model&…Contract` intersections. The same interface
speaks both dialects. Combined with the registry rules in section 13, the
`create()` annotations are satisfiable-in-practice-but-not-guaranteed: a
configured role model that implements `RoleContract` and uses `SoftDeletes`
without extending `Models\Role` breaks the declared generic while passing every
runtime check.

**16.3 Public contracts returning unclassified types.** Both resolvers return
`Illuminate\Support\Collection`. That is a framework type in a GateTrail public
return, so Laravel's collection API is inside GateTrail's compatibility surface
without GateTrail controlling it. `MutationResult.$resource` is typed
`Illuminate\Database\Eloquent\Model` for the same reason.

**16.4 Exceptions crossing public boundaries without documentation.** Eight
source exceptions are missing from `PACKAGE_SPEC.md` section 15 (11.5). Beyond
those, the three reference factories and both change objects throw **PHP's own
`InvalidArgumentException`** rather than a GateTrail type, so a consumer
catching "GateTrail errors" by namespace will miss them. `MutationResult`'s
constructor does the same.

**16.5 Configurable-model inheritance requirements as a promise.** The six rows
of section 13 — marker contract, base model, `SoftDeletes` — are enforced at
runtime by `ModelRegistry` and therefore already function as a contract.
Tightening any cell breaks existing consumer models; loosening one is safe. The
five marker interfaces being empty means **adding one method to any of them is a
breaking change for every consumer with a custom model**.

**16.6 Public events containing types that need their own stability.**
`MutationCommitted` carries `MutationOutcome` (proposed A — fine) and
`DateTimeImmutable` (PHP — fine), and validates against `MutationName`
(`@internal` — see 16.1). More structurally: `MutationCommitted` implements
`GateTrailEvent` and `AuditPersistenceFailed` does not, so "listen to every
GateTrail event" via the marker silently excludes the operational one. Their
constructor visibility also differs — private with an `@internal` factory versus
plain public — with no recorded rationale.

**16.7 Bindings that make internal services look supported.** 73 of 80 bindings
target proposed-E or proposed-D symbols (section 12.3). The
resolver-concrete-versus-manager-concrete asymmetry compounds it.

**16.8 Mutable public objects weakening the guarantee.** `MutationResult` is
`final readonly`, but `$resource` holds a **live, mutable Eloquent model**. A
consumer can call `save()`, `delete()` or mutate attributes on a value returned
from a supported API — and ADR 0009 treats direct database mutation as
unsupported and diagnostic-only. The returned model is the easiest route to
exactly that. Every other proposed-A value type is genuinely immutable.

**16.9 Constructors callable but intended package-only.** `MutationResult`
(public, no `@internal`), `AuditPersistenceFailed` (public, no `@internal`),
`Mutations\ActorIdentity` (public, `final readonly`, **no `@internal` at all**
despite living in an internal namespace), and `Subjects\SubjectIdentity`
(public constructor on a class-level `@internal` class). By contrast
`AssignmentPeriod`, the three references, both change objects, `SystemActor`,
`EffectivePermission`, `EffectiveRole`, `MutationCommitted` and
`StatementMeasurement` all use a private constructor with a controlled factory.
The package has a good convention; it is applied unevenly.

**16.10 Diagnostics and schema tooling needing a separate policy.** 43 symbols
(section 8), currently split between class-level `@internal` (the 2 diagnostics)
and no annotation at all (the 36 schema symbols plus 5 exceptions). A
DBA-managed deployment is a first-class specification mode, so whatever policy
covers it must be stated rather than inherited.

**16.11 Documentation claims that do not match code.** Section 15, M-1 to M-8.

**16.12 Phase 6–13 APIs that do not exist.** The subject API (spec 12.2), Gate
integration (12.3), policies (12.4), middleware (12.5), Blade directives (12.6),
all six console commands (18), `InvalidMiddlewareParameter`,
`UnauthorizedMutation`, and every cross-request cache surface deferred by ADR
0013 to Phase 8. **None of these exists today and none may be documented as
available.** Increment 2 must draw its boundary over implemented Phase 4 and
Phase 5 behavior only.

## 17. Recommended freeze boundary

**Proposed, not accepted.** Presented for maintainer evaluation.

### Tier 1 — Public and supported (39)

The five service contracts; the nine input/output value types; the two
effective-access value objects; the three event types (including the
`GateTrailEvent` marker); the twenty consumer-facing exception types.
Compatibility surface for a package-constructed value object is what a consumer
can **read**, per `PACKAGE_SPEC.md` section 22.

### Tier 2 — Public extension points (14)

The five marker contracts; the six configurable models plus `GateTrailModel`;
`HasGateTrail` and `HasAssignmentPeriodScopes`. The promise is the *requirements*
of section 13 and the inherited members listed there.

### Tier 3 — Operational and diagnostic, separate policy (43)

Schema inspection, manifest, DDL generation, the five operational exceptions,
and the two runtime diagnostics. Recommended posture: stable **within a schema
release**, changeable between releases with a changelog entry, and explicitly
not covered by the application-code compatibility promise.

### Tier 4 — Internal, recommended for `@internal` (116)

Handlers, commands, input resolvers, audit components, concurrency components,
guards, subject identity, schema adapters, the `Default*` implementations, and
the two event-construction helpers. **Roughly 50 of these carry no annotation
today**; annotating them is the single highest-value mechanical follow-up.

### Tier 5 — Undecided, maintainer decision required (2)

`Support\ModelRegistry` and `Database\Schema\SchemaContext`.

### Framework integration (4)

`GateTrailServiceProvider` and the three migration classes are reachable because
Laravel and Composer require it. Their compatibility surface is their **effects**
— the `gatetrail-config` publish tag, the contract bindings, the scoped
lifecycles, the listener registration — not their class APIs.

### Explicit statements this boundary carries

1. **"Public in PHP" is not "supported public API."** All 218 symbols are
   autoloadable; 39 are proposed as supported.
2. **No API is frozen by this inventory.**
3. **No existing consumer guarantee is removed by it.**
4. **Phases 6–13 may add APIs**; a boundary over today's behavior does not
   forbid additive surface later.
5. **After a later freeze, a supported API should not change silently.**
6. **Before `1.0.0`, an intentional breaking change to a supported API should
   require an ADR, a changelog entry and migration guidance** — *if* the
   eventual policy adopts that rule. `PACKAGE_SPEC.md` section 22 already
   requires changelog entries and an upgrade guide; the ADR requirement would be
   new and is recommended, not enacted.

## 18. Recommended safeguards for later increments

Assessed, **not implemented**. Each carries a cost note, because a safeguard
that costs more than it protects is not a safeguard.

| Safeguard | Recommendation | Assessment |
|---|---|---|
| `docs/PUBLIC_API.md` | **Adopt** | The freeze needs one authoritative list. Cost: a document to keep current — mitigated by the surface test below |
| Consumer guide in `README.md` | **Adopt — highest priority** | M-1 makes this the largest documentation gap. Install, configure, publish, migrate, first `assignRole()`, first `allows()`, and the Tier 1/Tier 2 boundary |
| `@internal` on the ~50 unannotated internals | **Adopt** | Mechanical, low-risk, no behavior change. Larastan already reads the tag. Cost: one review pass over 50 files |
| PHPStan internal-boundary enforcement | **Adopt if practical** | `larastan ^3.10` is already a dev dependency. PHPStan reports `@internal` usage across package boundaries, but the value appears in *consumer* analysis, not the package's own run. Worth trying at no new dependency cost; not worth a custom rule yet |
| Reflection-based public API surface test | **Adopt** | A test asserting the exact set of Tier 1/Tier 2 FQCNs, their public methods and signatures fails loudly when the surface drifts. `tests/` already uses reflection in 20+ files, so the technique is established. This is what keeps `PUBLIC_API.md` honest |
| Container-resolution smoke tests | **Adopt** | Assert the five contracts resolve, resolve to the expected concretes, and that resolver contracts are genuinely scoped — the Octane/queue leak the provider comments warn about is otherwise invisible until production |
| Executable documentation examples | **Adopt, scoped** | Every README example should exist as a test. Cost is real; scope it to the handful of examples the guide actually ships |
| Backward-compatibility tooling (e.g. an API-diff CI step) | **Defer** | Adds a dependency and CI time for a pre-`1.0.0` package that has not yet frozen anything. The reflection surface test covers most of the benefit at none of the cost. Revisit at `1.0.0` |

## 19. Open maintainer questions

Twelve questions. The ten required, plus two raised by the two F symbols. Each
carries a recommendation, an alternative and the consequence of choosing wrongly.

**Q1. Should consumers depend only on manager/resolver contracts rather than
`Default*` implementations?**
*Recommendation:* Yes. Support only `Contracts\AssignmentManager`, `RoleManager`,
`PermissionManager`, `PermissionResolver`, `RoleResolver`; mark all five
`Default*` classes `@internal`.
*Alternative:* Support the concretes as well, since they are `final readonly` and
would be typed-injectable.
*Consequence:* Supporting the concretes freezes constructor signatures — nine
injected collaborators between them, every one a proposed-E type — and makes
recomposition a breaking change. Contract-only matches spec 22 and ADR 0002.

**Q2. Are `EffectivePermission` and `EffectiveRole` supported public value
objects?**
*Recommendation:* Yes, as **read** surface: class name, `$id`, `$name`,
`$guardName`, types. The `of()` factories stay `@internal`.
*Alternative:* Treat them as internal projections and expose arrays instead.
*Consequence:* Arrays would remove a typed, self-documenting return for the sake
of a compatibility saving these two already have — private constructors,
`@internal` factories, three scalar fields. Declining also leaves the resolvers
with no supported return type at all.

**Q3. Are `MutationCommitted` and `AuditPersistenceFailed` stable public
events?**
*Recommendation:* Yes for both, with two corrections: state why
`AuditPersistenceFailed` does not implement `GateTrailEvent`, and align its
constructor visibility with `MutationCommitted`'s private-plus-`@internal`
pattern.
*Alternative:* Support only `MutationCommitted` and call the operational event
internal.
*Consequence:* Operators build alerting on `AuditPersistenceFailed` — the config
file itself advertises it as the signal that a mutation committed without audit
evidence. Calling it internal after advertising it would be a withdrawal of a
documented guarantee. Leaving the marker-interface asymmetry unexplained means
"listen to all GateTrail events" quietly misses audit failures.

**Q4. Are all 25 exception classes compatibility surface, or only those escaping
public operations?**
*Recommendation:* Split. The 20 in section 11.1–11.3 are Tier 1; the five
operational ones in 11.4 go to Tier 3 with the schema tooling. Add the eight
missing types to spec 15.
*Alternative:* All 25 in Tier 1, matching spec 22's blanket "exception types are
part of the compatibility surface".
*Consequence:* The blanket reading freezes `DdlCompilerUnavailable` and
`SchemaInspectorUnavailable` — thrown only by factories that exist to support
DBA workflows still under active development — as tightly as `RoleNotFound`.
Splitting keeps the promise where consumers actually catch.

**Q5. Are the configurable models and their marker contracts extension points?**
*Recommendation:* Yes — Tier 2, with the section 13 requirements documented
verbatim as the contract. Resolve the three asymmetries: decide whether
definition models should also require the base model, whether assignment models
should also require `SoftDeletes`, and whether `access_audit` should gain a
marker contract.
*Alternative:* Support model *configuration* but not model *extension* —
consumers may point a key at a class but get no promise about inherited members.
*Consequence:* The weaker promise is close to unusable: a custom model must
extend the default in three of six cases, so extension is already mandatory
there. The `access_audit` gap is the concrete risk — a model with no audit
columns passes registry validation today and fails at write time.

**Q6. Should `GateTrailStatementProbe` and `StatementMeasurement` be stable
diagnostics or internal test tooling?**
*Recommendation:* Tier 3 — operational, stable within a schema release, with
their existing `@internal` tags replaced by an explicit "diagnostic surface"
note so the intent is stated rather than implied by a tag that also means
"do not use".
*Alternative:* Keep them strictly internal.
*Consequence:* ADR 0012's query budget is a documented guarantee; a consumer who
wants to assert it in their own suite has no supported instrument, and will
either reimplement connection listening or depend on an `@internal` class
anyway. The probe is also the only thing that can catch an accidental
eager-load regression in consumer code.

**Q7. Are the schema and DDL components public DBA tooling or internal
implementation?**
*Recommendation:* Tier 3 for inspection, manifest and DDL generation (36
symbols); Tier 4 for `Database\Schema\Adapters\*`, `SchemaAdapter`,
`SchemaAdapterFactory` and `IdentifierValidator`, which serve package migrations
rather than DBAs.
*Alternative:* All of `Database\Schema\*` internal, with DBA needs met only by
the committed `.sql` artifacts under `database/ddl/`.
*Consequence:* Spec 3.8 requires inspection to be runnable in DBA-managed
environments; if the inspector is internal, the only supported artifact is the
generated SQL, and a platform team cannot programmatically verify conformance.
Spec 3.7 also requires the generator's output to stay byte-for-byte reproducible,
which is a stability promise about the generator whether or not it is public.

**Q8. Should constructors marked package-only become `@internal` even when
technically public?**
*Recommendation:* Yes, and apply it consistently. Annotate the public
constructors of `MutationResult` and `AuditPersistenceFailed`; annotate or
privatise `Mutations\ActorIdentity`'s.
*Alternative:* Leave them; spec 22 already says a package-controlled constructor
is outside the compatibility surface.
*Consequence:* Spec 22's rule is written for constructors "marked `@internal`" —
an unmarked public constructor is not covered by it. Leaving them unmarked means
the rule does not apply and the constructors are, by the spec's own logic,
supported surface.

**Q9. What compatibility promise should apply before `1.0.0`?**
*Recommendation:* Keep spec 22's "APIs are experimental before `1.0.0`", but
add that a **deliberate** break to a Tier 1 or Tier 2 symbol requires an ADR, a
changelog entry and migration guidance — while accidental drift is caught by the
reflection surface test.
*Alternative:* Full semantic-versioning discipline now.
*Consequence:* Full discipline before Phases 6–13 would freeze surfaces that
Laravel integration, middleware and caching are likely to reshape — the resolver
input types are explicitly awaiting a Phase 6 adapter. The lighter rule keeps
pre-`1.0.0` freedom while removing silent breakage.

**Q10. Should the freeze cover only currently implemented Phase 4/5 APIs while
allowing additive Phase 6–13 APIs?**
*Recommendation:* Yes. Cover exactly what exists at the freeze commit. Phase
6–13 surfaces are additive and get their own boundary when implemented.
*Alternative:* Freeze the specification's full intended surface, including the
subject API and middleware.
*Consequence:* Freezing unimplemented surface is a promise about code nobody has
written. Spec 12.2 already calls its subject API "Proposed", and M-2 shows that
even a labelled proposal reads as an available API — writing it into a freeze
document would remove the label entirely.

**Q11. Is `Support\ModelRegistry` public API?** *(from category F)*
*Recommendation:* Tier 1, narrowed to its six read accessors — `role()`,
`permission()`, `roleAssignment()`, `permissionAssignment()`, `rolePermission()`,
`accessAudit()`, each returning `class-string`. Mark the constructor `@internal`.
*Alternative:* Tier 4 internal.
*Consequence:* It is the only supported way to learn which model class is
configured. A consumer who extends `Models\Role` and needs to query it must
either resolve `ModelRegistry`, re-read `config('gatetrail.models.role')`
themselves and duplicate the validation, or hard-code their own class and break
the indirection the config key exists to provide. Calling it internal pushes
consumers toward the config-reading path, which silently skips every
`InvalidModelConfiguration` check.

**Q12. Is `Database\Schema\SchemaContext` public API?** *(from category F)*
*Recommendation:* Tier 4 internal — but only alongside a decision on
`GateTrailModel::tableKey()`, which is the coupling that makes the question
live.
*Alternative:* Tier 3 operational, since it resolves connection, schema, prefix
and table names that an operations team may need to inspect.
*Consequence:* A consumer subclassing `GateTrailModel` directly must implement
`abstract protected tableKey(): string` and return a key `SchemaContext::table()`
recognises — so a proposed extension point already depends on this class's
behavior. Marking it internal without documenting the valid `tableKey()` values
leaves that obligation undocumented; documenting the values without marking it
internal freezes eight public methods.

## 20. Acceptance criteria for Increment 2

Increment 2 is **not** performed here. It should be considered complete when:

1. Maintainers have answered Q1–Q12 in section 19, each answer recorded with its
   rationale.
2. Every one of the 218 symbols carries a **final** classification — every F
   resolved to a real tier, every preliminary category confirmed or overridden.
3. `docs/PUBLIC_API.md` exists and lists Tier 1 and Tier 2 exhaustively, by FQCN,
   with exact public signatures.
4. The Tier 3 stability policy is written and states explicitly how it differs
   from the Tier 1 promise.
5. M-1 is addressed: `README.md` carries installation, configuration, publishing,
   migration and a first working example of a mutation and a check.
6. M-2 through M-8 are each either corrected or explicitly recorded as accepted
   documentation debt with a reason.
7. Specifically: spec 15 gains the eight missing exception types; spec 12.2's
   subject API is unambiguously marked as unimplemented Phase 6 surface; the
   stale `AuditMode::Disabled` comment is corrected; and M-6's `create()` generic
   annotations are either corrected to intersections or the registry rules are
   tightened to require the base model.
8. No Phase 6–13 surface is documented as available anywhere in the output.
9. The freeze document states plainly that it covers only Phase 4 and Phase 5
   implemented behavior.
10. Progress accounting is unchanged by Increment 2 as it is by this one: the
    Phase 4 item stays open until documentation **and** freeze are both complete.

### Progress statement for this increment

This inventory is **evidence for** the freeze, not the freeze. Accordingly:

- The final Phase 4 item, *Consumer documentation and pre-v1 API freeze*, remains
  **unchecked**.
- Phase 4 remains **19 of 20 = 95%**, `In progress`.
- Phase 5 remains **100%**.
- Overall equal-phase progress remains **approximately 41%**.
- The deliverable tracker remains **12 of 32, approximately 38%**.
- Phase 8 remains **0%, Not started**.

No denominator, percentage, checklist or phase status was changed by this
increment, and no runtime code was touched.

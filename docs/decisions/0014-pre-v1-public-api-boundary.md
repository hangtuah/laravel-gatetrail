# ADR 0014: Define the Pre-v1 Public API Boundary

- Status: **Proposed**
- Proposed: 2026-08-19
- Decision owners: GateTrail maintainers
- **What this ADR decides: which implemented symbols receive a compatibility
  promise before `1.0.0`, and which do not.** It freezes nothing, changes no
  source, and accepts no documentation promise.
- Public API freeze status: **not frozen**
- Evidence base: [Public API Inventory 0002](../reviews/0002-public-api-inventory.md),
  218 symbols at commit `992af7b`
- Supersedes: nothing. Refines the compatibility rules of `PACKAGE_SPEC.md`
  section 22 for the pre-`1.0.0` period.

## 1. Context and motivation

Phase 4 has one item left: *consumer documentation and pre-v1 API freeze*. Both
halves are blocked on the same missing decision — **nobody has said which
symbols GateTrail supports.**

Documentation cannot be written first. A consumer guide that shows
`app(DefaultAssignmentManager::class)` teaches a dependency the package does not
intend to keep; a guide that omits a class consumers legitimately need is
incomplete. The guide's contents *are* the boundary, so the boundary has to be
decided before a word of it is written.

The freeze cannot be declared first either. Inventory 0002 found defects that a
freeze would make permanent — a generic annotation the package's own
configuration can violate, a marker interface whose stated promise is false, and
an under-validated configurable model. Freezing those is worse than not freezing:
it converts a fixable bug into a compatibility obligation.

This ADR is the missing middle. It proposes the boundary, records what must be
corrected before that boundary can be frozen, and sequences the work.

**Why now, and not at `1.0.0`.** PSR-4 makes every one of the 218 symbols
loadable today. Every day the package ships without a stated boundary is a day a
consumer can build against `EffectiveAccessQueryBuilder` in good faith. The cost
of drawing the line rises monotonically.

## 2. Evidence from the 218-symbol inventory

Inventory 0002 enumerated every declared symbol under `src/` — 218 files, 218
declarations, zero duplicate fully-qualified names, zero anonymous classes. This
ADR re-verified its load-bearing findings directly against the repository rather
than adopting them on trust. All were confirmed; two were found to be narrower or
broader than the inventory stated, and are corrected here.

### 2.1 Findings confirmed against source

| # | Finding | Verification at `992af7b` |
|---|---|---|
| E-1 | Annotation coverage is partial | 74 class-level `@internal`, 4 member-level, **140 none**. Every handler and command is annotated; no `Default*` manager, no `*InputResolver`, no `Guards\*`, no `Database\Schema\*` is |
| E-2 | `create()` generics can be violated by configuration | `ModelRegistry::role()` calls `resolve('role', Role::class, RoleContract::class, true)` — the fourth positional argument is `$requiresSoftDeletes`, so `$requiresBaseModel` stays `false`. `CreateRoleHandler:88` runs `new $class` on that result, while `RoleManager::create()` is annotated `@return MutationResult<Role>` |
| E-3 | The event marker's promise is false | `Contracts\GateTrailEvent`'s docblock: "Marks **every** event GateTrail dispatches… listening to this interface receives **every** GateTrail event." Only `MutationCommitted` implements it. `AuditFailureReporter:34,45` builds and dispatches `AuditPersistenceFailed`, which does not |
| E-4 | `AccessAudit` replacement is unvalidated | `ModelRegistry::accessAudit()` calls `resolve('access_audit', AccessAudit::class)` — no contract, no base model, no `SoftDeletes` |
| E-5 | `README.md` is 2 lines | Title plus one description line. No installation, configuration or example |
| E-6 | Spec/source exception drift | Spec section 15 lists 19 names; `src/Exceptions/` holds 25. Two spec names do not exist; eight source types are undocumented |
| E-7 | Stale comment | `Audit\AuditMode::Disabled` says "Not implemented yet"; `AuditedMutationTransaction:175` routes it to `runUnaudited()` |
| E-8 | 73 of 80 container bindings target non-public symbols | Verified against `GateTrailServiceProvider::register()` |

### 2.2 Findings this ADR sharpens

**S-1 — `HasAssignmentPeriodScopes` is not merely inherited; the resolver depends
on it.** The inventory recorded the trait's four scopes as consumer surface
acquired by inheritance. Source shows an asymmetry the inventory did not
separate:

| Scope | Call sites inside `src/` | Nature |
|---|---:|---|
| `scopeActiveAt(Builder, DateTimeInterface): Builder` | **4**, all in `Access\EffectiveAccessQueryBuilder` | Load-bearing for authorization |
| `scopeScheduledAt(Builder, DateTimeInterface): Builder` | 0 | Consumer convenience |
| `scopeExpiredAt(Builder, DateTimeInterface): Builder` | 0 | Consumer convenience |
| `scopeRevoked(Builder): Builder` | 0 | Consumer convenience |

`activeAt()` is therefore bound in **both** directions: consumers inherit it, and
`EffectiveAccessQueryBuilder` calls it on the configured assignment model's
query. Because assignment models must extend the package model, a consumer
subclass can **override `scopeActiveAt()` and silently change what "active"
means for every authorization decision** — including widening it. That is a
security-relevant inherited surface, not a convenience one, and section 12
treats it accordingly.

**S-2 — requiring the `AccessAudit` base model is supported by writer evidence;
an empty marker is not.** The inventory called the missing `access_audit`
validation an asymmetry. Reading `Audit\AccessAuditWriter::append()` shows
exactly which inherited behavior the writer relies on:

```php
$class = $this->models->accessAudit();
$audit = new $class;
$connection = $audit->getConnection();          // must be the GateTrail connection
if ($connection->transactionLevel() === 0) { throw new LogicException(...); }
$audit->setRawAttributes($record->databaseAttributes());
$audit->save();                                  // must not write updated_at
$identifier = $audit->getKey();
```

Three inherited behaviors are load-bearing, and **none is validated today**:

1. `GateTrailModel::getConnectionName()` — a model not extending the base resolves
   the application's default connection, so the `transactionLevel()` guard tests
   the wrong connection.
2. `GateTrailModel::getTable()` with `tableKey() === 'access_audits'` — a
   non-extending model uses Eloquent's default table naming and writes to the
   wrong table.
3. `AccessAudit::UPDATED_AT = null` — the `acl_access_audits` migration creates
   `created_at` **only** (`database/migrations/…_create_gatetrail_access_audits_table.php:44`).
   A model without this constant makes `save()` write `updated_at` against a
   column that does not exist.

`setRawAttributes()` bypasses `$fillable`, so `$fillable` is *not* a writer
requirement. An empty marker interface would guarantee none of the three
behaviors that matter. This is direct repository evidence that the correction is
**require the base model**, and that a symmetry-only marker would be worthless.

### 2.3 What the evidence does not show

No evidence was found that the `GateTrailEvent` marker's "every event" promise
was ever intended narrowly. ADR 0010 introduces the marker to preserve a
migration path for future per-mutation classes and states that "`MutationCommitted`
and `GateTrailEvent` are **public API**"; it never limits the marker to mutation
events, and the marker's own docblock says the opposite. Section 13 therefore
treats the asymmetry as a defect rather than an intention, as the evidence
requires.

## 3. Reachable versus supported

The boundary rests on separating six things PHP and Laravel deliberately blur.
The inventory established the distinction; this ADR makes it normative.

| Level | What it means | Does it imply support? |
|---|---|---|
| **Technically reachable** | PSR-4 autoloading can load the class; PHP can name it | **No.** True of all 218 symbols |
| **Container-resolvable** | The container can build it, by explicit binding or autowiring | **No.** True of ~all of them; 80 have explicit bindings, 73 of which this ADR classifies as internal |
| **Inherited through a supported model** | A consumer's subclass acquires the member because a supported base declares it | **Partly.** The member becomes a transitive constraint even when its declaring class is internal — section 12 |
| **Documented supported API** | Listed in Tier 1 or Tier 2, with a stated signature | **Yes.** This is the only level that carries a promise |
| **Package-controlled construction** | The type is supported to *read*, but only the package may build authentic instances | **Read yes, construct no** — section 10 |
| **Internal implementation** | Behind a supported boundary; may change in any release including a patch | **No** |

> **"Public in PHP" is not "supported public API."** PHP has no package-private
> visibility. A `public static` factory is the only way one package class can
> build another without reflection, so technical callability is an artefact of
> the language, not a promise. This is the rule ADR 0012 already applied to
> `EffectivePermission::of()`; this ADR generalizes it to the whole package.

**Nothing in this ADR is frozen.** Status is Proposed. Acceptance of this ADR
would settle the *boundary*; the freeze itself is a later, separate act that
cannot occur until the section 17 corrections land.

## 4. Compatibility tiers

Five tiers. Every one of the 218 symbols belongs to exactly one.

| Tier | Name | Promise | Count |
|---|---|---|---:|
| **1** | Supported public API | Consumers call, receive, catch or listen. Signatures, readable fields and vocabularies are compatibility surface | **39** |
| **2** | Consumer extension points | Consumers implement, extend or configure. The *requirements* are the promise | **13** |
| **FW** | Framework integration | Laravel and Composer must reach these. The **effects** are the surface, not the class APIs | **4** |
| **OPS** | Operational | Types that surface during installation and deployment, on a separate stability clock | **5** |
| **4** | Internal implementation | No promise. Recommended for `@internal` | **157** |
| | | **Total** | **218** |

### 4.1 Recalculation against the inventory

The inventory's preliminary split was A 39 / B 14 / C 4 / D 43 / E 116 / F 2. The
maintainer directions change it. **41 symbols move**, and the totals were
recomputed rather than inherited:

| Movement | Count | Cause |
|---|---:|---|
| Schema inspection, manifest and DDL classes: operational → internal | 36 | Q7 — artifact reproducibility is a promise about *outputs*, not compiler class APIs |
| `Diagnostics\GateTrailStatementProbe`, `StatementMeasurement`: operational → internal | 2 | Q6 — query-budget verification is a package test responsibility |
| `Support\ModelRegistry`: undecided → internal | 1 | Q11 |
| `Database\Schema\SchemaContext`: undecided → internal | 1 | Q12 |
| `Models\GateTrailModel`: extension point → internal, with a transitive constraint | 1 | Q5 — it is not a direct extension target |

Tier 1 is **unchanged at 39** — the directions confirmed every A candidate and
moved none in or out. The operational tier shrinks from 43 symbols to **5**,
because it now holds only the exception *types*; the artifacts and provisioning
modes it also covers are not symbols. Internal rises from 116 to **157**, or
72.0% of the package.

## 5. Tier 1 — supported public API manifest

39 symbols. Every fully-qualified name is listed. Signatures are stated where
short and referenced precisely to Inventory 0002 where long. Namespace prefix
`HangTuah\GateTrail\` is omitted in the tables.

### 5.1 Service contracts — 6

| FQCN | Binding | Lifecycle | Consumer action | Signatures |
|---|---|---|---|---|
| `Contracts\AssignmentManager` | explicit → `Managers\DefaultAssignmentManager` | singleton | **call** | 6 methods, Inventory 0002 §10.1 |
| `Contracts\RoleManager` | explicit → `Managers\DefaultRoleManager` | singleton | **call** | 4 methods, Inventory 0002 §10.2 |
| `Contracts\PermissionManager` | explicit → `Managers\DefaultPermissionManager` | singleton | **call** | 4 methods, Inventory 0002 §10.2 |
| `Contracts\PermissionResolver` | explicit → `Access\DefaultPermissionResolver` | **scoped** | **call** | 2 methods, Inventory 0002 §10.3 |
| `Contracts\RoleResolver` | explicit → `Access\DefaultRoleResolver` | **scoped** | **call** | 2 methods, Inventory 0002 §10.3 |
| `Contracts\GateTrailEvent` | none | — | **listen** | empty marker |

The five service contracts carry 18 public methods between them. Their parameter
lists, parameter **names** (named arguments are the documented calling style per
ADR 0002), types, defaults and return types are all compatibility surface.

Consumers **must not** type-hint the `Default*` concretes. Those five classes,
their constructors and their collaborator graphs are Tier 4 — see section 9.

### 5.2 Input and output value types — 9

All in the root `HangTuah\GateTrail` namespace. All `final readonly`.

| FQCN | Construction | Compatibility surface |
|---|---|---|
| `AssignmentPeriod` | **private ctor**; factories `immediate()`, `until(DateTimeInterface)`, `between(DateTimeInterface, ?DateTimeInterface = null)` | the three factories; readers `startsAt(): ?DateTimeImmutable`, `expiresAt(): ?DateTimeImmutable`, `isImmediate(): bool`; UTC whole-second normalization |
| `AssignmentReference` | **private ctor**; factory `id(int\|string): self` | factory; readable `int\|string $id`; 191-char and non-empty rules |
| `RoleReference` | **private ctor**; factory `id(int\|string): self` | as above |
| `PermissionReference` | **private ctor**; factory `id(int\|string): self` | as above |
| `RoleChanges` | **private ctor**; factory `description(?string): self` | factory; readable `?string $description`; **`null` clears, `''` preserves** |
| `PermissionChanges` | **private ctor**; factory `description(?string): self` | as above |
| `MutationResult<TResource of Model>` | **public ctor** — see §10 correction | readable `Model $resource`, `MutationOutcome $outcome`, `int\|string\|null $auditId`; method `changed(): bool`; the `Unchanged` + non-null `$auditId` rejection |
| `MutationOutcome` | backed `string` enum | all six cases and their **string values**: `created`, `updated`, `deactivated`, `restored`, `revoked`, `unchanged` |
| `SystemActor` | **private ctor**; factory `named(string): self` | factory; `const TYPE = 'gatetrail.system'`; readable `string $name` |

`MutationOutcome`'s backing values are surface, not only its case names —
consumers persist and transmit them.

### 5.3 Effective-access read values — 2

| FQCN | Construction | Compatibility surface |
|---|---|---|
| `Access\EffectivePermission` | **private ctor**; `@internal` factory `of()` | readable `int\|string $id`, `string $name`, `string $guardName` |
| `Access\EffectiveRole` | **private ctor**; `@internal` factory `of()` | readable `int\|string $id`, `string $name`, `string $guardName` |

Consumers **receive** these from `effectivePermissions()` and `effectiveRoles()`.
That is the only supported way to obtain one. Constructor parameters are not
compatibility surface, and a consumer-fabricated instance is **not authorization
evidence** — this ADR restates ADR 0012's decision without weakening it. Adding a
field later remains compatible for readers precisely because no supported
construction call exists.

### 5.4 Events — 2

| FQCN | Construction | Consumer action | Compatibility surface |
|---|---|---|---|
| `Events\MutationCommitted` | **private ctor**; `@internal` factory `record()` | **listen** | 11 readable fields (`$mutation`, `$outcome`, `$targetType`, `$targetId`, `$subjectType`, `$subjectId`, `$actorType`, `$actorId`, `$auditId`, `$occurredAt`, `$requestId`); `context(): array<string, mixed>` and its fixed key order; the 14-name mutation vocabulary |
| `Events\AuditPersistenceFailed` | **public ctor** — see §10 correction; factory `forRecord()` | **listen** | 7 readable fields (`$event`, `$targetType`, `$targetId`, `$subjectType`, `$subjectId`, `$requestId`, `$exceptionClass`); `context(): array<string, mixed>` |

**The emitted mutation vocabulary is Tier 1 even though `Mutations\MutationName`
is Tier 4.** The 14 strings are compatibility surface as *values* read from
`MutationCommitted::$mutation` and from the audit `event` column:

```text
role.assigned      role.revoked       role.created      role.updated
role.deactivated   role.restored
permission.granted_to_subject         permission.revoked_from_subject
permission.granted_to_role            permission.revoked_from_role
permission.created permission.updated permission.deactivated permission.restored
```

The *class* that holds them — its constants, `all()`, `assignments()`,
`isKnown()`, `isAssignment()` — is not. A consumer compares against the string,
not against `MutationName::ROLE_ASSIGNED`.

### 5.5 Exceptions — 20

Consumers **catch** these. Detailed taxonomy in section 14; full factory lists in
Inventory 0002 §11.1–11.3.

`Exceptions\AssignmentNotActive`, `AssignmentScopeCollision`, `AuditWriteFailed`,
`ConcurrencyRetryExhausted`, `DuplicateActiveAssignment`, `DuplicatePermission`,
`DuplicateRole`, `GuardMismatch`, `InvalidActor`, `InvalidAssignmentPeriod`,
`InvalidAuditConfiguration`, `InvalidAuditMetadata`,
`InvalidConcurrencyConfiguration`, `InvalidGuard`, `InvalidModelConfiguration`,
`InvalidSubject`, `PermissionNotFound`, `ReasonRequired`, `RoleNotFound`,
`UnsupportedAfterCommitBoundary`.

**Tier 1 total: 6 + 9 + 2 + 2 + 20 = 39.**

## 6. Tier 2 — consumer extension point manifest

13 symbols.

### 6.1 Model marker contracts — 5

`Contracts\RoleContract`, `PermissionContract`, `RoleAssignmentContract`,
`PermissionAssignmentContract`, `RolePermissionContract`.

All five are **empty interfaces — zero methods**. That is the promise: because
they declare nothing, implementing one costs a consumer nothing.

> **Adding a single method to any of these five is a breaking change for every
> consumer with a custom model.** They must stay empty, or change only in a
> major version with migration guidance.

### 6.2 Configurable concrete models — 6

Consumers point a `gatetrail.models.*` key at their own class. The **requirements
are the compatibility promise**, and section 12 states them exactly.

`Models\Role`, `Models\Permission`, `Models\RoleAssignment`,
`Models\PermissionAssignment`, `Models\RolePermission`, `Models\AccessAudit`.

Compatibility surface per model: the class name, its `$fillable` list, its
`casts()` result, and its declared relations —

| Model | Public relations |
|---|---|
| `Role` | `subjectAssignments(): HasMany`, `permissionAssignments(): HasMany` |
| `Permission` | `subjectAssignments(): HasMany`, `roleAssignments(): HasMany` |
| `RoleAssignment` | `subject(): MorphTo`, `role(): BelongsTo` |
| `PermissionAssignment` | `subject(): MorphTo`, `permission(): BelongsTo` |
| `RolePermission` | `role(): BelongsTo`, `permission(): BelongsTo` |
| `AccessAudit` | `subject(): MorphTo`; `const UPDATED_AT = null` |

### 6.3 Traits — 2

**`Concerns\HasGateTrail`** — the supported consumer subject trait. Spec 12.1
instructs consumers to `use` it. Its one member,
`gateTrailGuardNameFromDeclaredProperty()`, is `final public` and `@internal`:
the *trait* is Tier 2, that *method* is not consumer API.

What the trait actually does today: it lets `Guards\SubjectGuardResolver` read a
`protected string $gateTrailGuardName` property. A subject declaring a public
`gateTrailGuardName(): string` method **does not need the trait** — the resolver
checks `method_exists()` first. Both guard-declaration styles are Tier 2 surface;
the trait is one of two supported routes, not the only one.

**`Models\Concerns\HasAssignmentPeriodScopes`** — reached only by inheritance
through the three supported assignment models. Per finding S-1 its scopes split:

| Scope | Proposed stability |
|---|---|
| `scopeActiveAt(Builder $query, DateTimeInterface $time): Builder` | **Tier 2 surface, in both directions.** Consumers may call it; `Access\EffectiveAccessQueryBuilder` calls it on the configured model. **Overriding it changes authorization semantics** |
| `scopeScheduledAt(Builder $query, DateTimeInterface $time): Builder` | Open — question O-2 |
| `scopeExpiredAt(Builder $query, DateTimeInterface $time): Builder` | Open — question O-2 |
| `scopeRevoked(Builder $query): Builder` | Open — question O-2 |

`activeAt()`'s two-way binding is the sharpest inherited constraint in the
package and is recorded here rather than deferred.

### 6.4 Explicitly not a Tier 2 extension target

**`Models\GateTrailModel` is Tier 4**, not an extension point. Consumers extend
the six concrete models, not the abstract parent. But it cannot be treated as
freely changeable either — section 12.3 states the transitive constraint its
supported subclasses create.

## 7. Operational artifact and exception policy

DBA-managed provisioning is a first-class specification mode (spec 3.4), so the
operational boundary must be honest. The mistake to avoid is concluding that
because a *workflow* is supported, every *class* that implements it is public.

### 7.1 Stable operational surfaces — not symbols

These carry an operational promise and are what a DBA or deployment pipeline
actually consumes:

1. **Versioned generated SQL artifacts** — `database/ddl/mysql/0001/{install,rollback}.sql`
   and `database/ddl/oracle/0001/{identity,sequence-trigger}/{install,rollback}.sql`.
   Spec 3.7 requires them to stay byte-for-byte reproducible. **That is a promise
   about outputs.**
2. **Documented provisioning modes** — `gatetrail.database.provisioning` values
   `package` and DBA-managed, and their documented behavioral difference.
3. **The publish tag `gatetrail-config`** and the published file location
   `config_path('gatetrail.php')`. A documented
   `vendor:publish --tag=gatetrail-config` breaks if the tag is renamed.
4. **Package migration file names and the `database/migrations` layout**, to the
   extent already promised by the published schema release.
5. **A future explicit CLI/command surface, if and when implemented.** Spec 18
   lists six planned commands. **None exists.** No console command may be
   documented as available.

### 7.2 Operational exception types — 5

These retain a **separate stability tier** because they surface during
installation, migration and deployment rather than during authorization, and
because a deployment pipeline may legitimately match on them:

`Exceptions\DdlCompilerUnavailable` (from `Ddl\DdlCompilerFactory`),
`Exceptions\SchemaInspectorUnavailable` (from `Inspection\SchemaInspectorFactory`),
`Exceptions\UnsupportedDatabaseDriver` (from `SchemaAdapterFactory`),
`Exceptions\InvalidDatabaseIdentifier` (from `IdentifierValidator`),
`Exceptions\MigrationDependencyError` (from `Database\Migrations\GateTrailMigration`).

Promise: the class names and their catchability are stable within a schema
release and may change between schema releases with a changelog entry. They are
**not** in Tier 1 and do not inherit Tier 1's stronger commitment.

`InvalidDatabaseIdentifier` is the borderline member: `IdentifierValidator` is
reached through `SchemaContext`, which every model touches via `getTable()`, so a
malformed table-name configuration can surface it during an ordinary read. It
stays operational because its *cause* is always configuration or provisioning,
never an authorization decision.

### 7.3 What is explicitly not operational API — 38 symbols

**All schema inspection, manifest, DDL-compiler, adapter and factory classes are
Tier 4 internal**, together with both runtime diagnostics:

- `Database\Schema\Inspection\**` — 22 symbols including `SchemaInspector`,
  `SchemaInspectorFactory`, `SchemaInspection`, `SchemaIssue`,
  `InspectionSeverity`, and every MySQL and Oracle catalog reader and metadata
  record;
- `Database\Schema\Manifest\*` — 7 symbols including `SchemaManifest`;
- `Database\Schema\Ddl\**` — 7 symbols including `DdlArtifactGenerator`,
  `DdlCompiler`, `DdlCompilerFactory` and both vendor compilers;
- `Database\Schema\Adapters\*`, `SchemaAdapter`, `SchemaAdapterFactory`,
  `SchemaContext`, `IdentifierValidator`;
- `Diagnostics\GateTrailStatementProbe`, `Diagnostics\StatementMeasurement`.

> Freezing 38 implementation classes because DBA-managed provisioning is
> supported would confuse the artifact with the machinery that produces it. The
> artifact is the contract. The compiler is an implementation of it.

**On diagnostics specifically (Q6).** Query-budget verification is a **package
test responsibility**, discharged by GateTrail's own suite against ADR 0012's
budget. A consumer who wants equivalent assurance can register their own
`Connection::listen()` callback — the technique is nine lines and needs nothing
from GateTrail. Both classes keep their current `@internal` annotations; **this
ADR removes no `@internal` annotation from any symbol.** If real consumer demand
appears, a small explicit diagnostic contract should be designed then, rather
than freezing today's probe.

## 8. Framework integration policy

4 symbols. Laravel and Composer require them to be reachable; ordinary consumers
do not call them.

| FQCN | Why reachable | What is surface |
|---|---|---|
| `GateTrailServiceProvider` | `composer.json` `extra.laravel.providers`; package discovery instantiates it | its **effects**, not its class API |
| `Database\Migrations\GateTrailMigration` | abstract base of all seven package migrations | `shouldRun()` behavior under DBA-managed provisioning |
| `Database\Migrations\AssignmentTableMigration` | abstract base of the three assignment-table migrations | nothing beyond the above |
| `Database\Migrations\OracleMigrationExecutor` | executes Oracle statement batches during package-managed provisioning | nothing; internal to migration execution |

**Published resources.** The provider publishes exactly one tag — `gatetrail-config`
— and loads `database/migrations` only when
`config('gatetrail.database.provisioning') === 'package'` **and** the application
is running in console. Both conditions are operational surface per section 7.1.

**Service-container aliases and contracts.** The six contract-to-concrete
bindings are surface *as bindings*: resolving `Contracts\PermissionResolver` must
keep yielding a working `PermissionResolver`, and the **scoped** lifecycle of the
two resolver contracts is itself a promise — a consumer relying on per-request
memo coherence under Octane depends on it. The concrete classes those bindings
resolve to are not surface and may be replaced.

`Psr\Clock\ClockInterface` → `Time\SystemClock` is a seventh binding. The
interface is PSR's, not GateTrail's; whether rebinding it is a supported testing
seam is open question O-3.

## 9. Internal implementation policy

157 symbols — 72.0% of the package. No compatibility promise. Recommended for
`@internal` where not already annotated.

**Minimum internal set, confirmed against source rather than assumed:**

| Group | Count | Members |
|---|---:|---|
| `Managers\Default*` | 3 | `DefaultAssignmentManager`, `DefaultRoleManager`, `DefaultPermissionManager` |
| `Access\Default*Resolver` | 2 | `DefaultPermissionResolver`, `DefaultRoleResolver` |
| Commands | 14 | `Mutations\Commands\*` |
| Handlers | 14 | `Mutations\Handlers\*` |
| Input resolvers and normalizers | 15 | `Mutations\*InputResolver`, `*IdentifierNormalizer`, `DefinitionInputValidator`, `MutationReasonValidator`, `MutationName`, `PreparedMutation`, `ActorIdentity`, `ActorIdentityResolver` |
| Audit builders and transaction orchestration | 16 | `Audit\*`, plus `Mutations\AuditedMutationTransaction`, `StrictMutationTransaction`, `AfterCommitBoundary` |
| Concurrency classifiers and retry internals | 8 | `Concurrency\*` |
| Query builders, row mappers, memo internals | 11 | `Access\EffectiveAccess*`, `FlushEffectiveAccessMemo`, `RequestedDefinition*` |
| Assignment internals | 12 | `Assignments\*` |
| Guards, subjects, clock | 5 | `Guards\*`, `Subjects\*`, `Time\SystemClock` |
| Event construction helpers | 2 | `Events\MutationEventDispatcher`, `Events\SafeMutationContext` |
| `Support\ModelRegistry` | 1 | Q11 |
| Schema, DDL, inspection, adapters, `SchemaContext`, `IdentifierValidator` | 38 | section 7.3 |
| `Models\GateTrailModel` | 1 | Q5 — with the transitive constraint of section 12.3 |
| Remaining infrastructure | 15 | `Database\UniqueConstraintViolationClassifier`, `Assignments` enums, and other single-purpose components |

**`@internal` annotations are not the evidence for this classification — they are
its intended output.** 140 of the 218 symbols carry no annotation today,
including every `Managers\Default*`, both `Access\Default*Resolver`, all eleven
`Mutations\*InputResolver` classes and the entire `Database\Schema\*` tree. This
ADR decides where annotations *should* be applied; correction 11 in section 17
applies them.

**`Mutations\MutationName` is internal as a class while its emitted string
vocabulary is Tier 1** (section 5.4). The distinction is deliberate: the strings
are persisted in the audit `event` column and travel in events, so they are
observable data; the constants, `all()`, `assignments()`, `isKnown()` and
`isAssignment()` are a package convenience.

**`Support\ModelRegistry` is internal (Q11).** Being a container singleton does
not make it supported. Consumers use their configured model subclasses and the
Tier 1 managers and resolvers. Its six accessors return `class-string` values and
would be a plausible discovery API, but freezing today's implementation is the
wrong way to provide one — if validated model-class discovery is later needed, a
small explicit contract should be introduced. Recorded as open question O-4
because the workaround has a real cost.

**`Database\Schema\SchemaContext` is internal (Q12).** Consumers and DBAs use
documented configuration, generated artifacts and future explicit operational
commands. Its coupling to `GateTrailModel::tableKey()` is documented as an
inherited extension constraint in section 12.3 **without** exposing `SchemaContext`
itself.

## 10. Package-controlled construction policy

**Readability is supported. Construction is not.** A consumer may read every
field this ADR lists; only the package may build authentic instances of a
package-produced type.

The rule, generalizing ADR 0012:

1. A Tier 1 result, event or exception type **may** have a package-controlled
   constructor or factory.
2. Package-only construction points **should** be non-public where compatibility
   permits, and `@internal` where it does not.
3. A `public static` factory that exists only because PHP lacks package-private
   visibility is **not** consumer construction API. Its name, parameters and
   existence may change in any release, including a patch.
4. Constructor and factory **parameter lists are not compatibility surface** for
   package-controlled types. This is what buys headroom to add a field later.

### 10.1 A tension with ADR 0010 to resolve

ADR 0010's *Extension and compatibility* section states that "property names and
the internal factory's named parameters are compatibility surface once v1 is
frozen", and then explains in the same bullet that "the factory's parameter names
are **not** consumer-facing because the constructor is not public". ADR 0012
states flatly that "constructor parameters are **not** public compatibility
surface".

Rule 4 above follows ADR 0012, because the reasoning is explicit and the
consequence — headroom to add a field without breaking a supported call — is the
whole point of package-controlled construction. Read together with its own second
sentence, ADR 0010's clause is best understood as an internal-consistency note
rather than a consumer promise, and this ADR does not contradict its intent.

The wording is nonetheless ambiguous and should be reconciled when
`PACKAGE_SPEC.md` section 22 is aligned in increment 6. This ADR does not amend
ADR 0010.

### 10.2 Current state and required alignment

| Type | Constructor | Factory | Assessment |
|---|---|---|---|
| `Access\EffectivePermission` | private | `of()` `@internal` | **Correct.** The reference pattern |
| `Access\EffectiveRole` | private | `of()` `@internal` | **Correct** |
| `Events\MutationCommitted` | private | `record()` `@internal` | **Correct** |
| `Events\AuditPersistenceFailed` | **public, unannotated** | `forRecord()` public, unannotated | **Misaligned.** Correction 4 |
| `MutationResult` | **public, unannotated** | none | **Misaligned.** Correction 4 |
| `Mutations\ActorIdentity` | **public, unannotated** | none | **Misaligned**, and the class carries no `@internal` despite being Tier 4. Correction 4 |
| 13 Tier 1 exceptions | private | named factories | **Correct** |
| 7 Tier 1 exceptions | **implicit public** (no declared constructor) | named factories | **Misaligned.** `AssignmentScopeCollision`, `AuditWriteFailed`, `InvalidActor`, `InvalidAssignmentPeriod`, `InvalidAuditMetadata`, `InvalidModelConfiguration`, `ReasonRequired` inherit the SPL public constructor |

The package already proves the private-constructor form works on exception
subclasses — `RoleNotFound extends RuntimeException` declares
`private function __construct`. The 7 misaligned exceptions are an inconsistency,
not a language limitation.

**`MutationResult` deserves a specific note.** Its constructor is public and
carries no annotation, so under `PACKAGE_SPEC.md` section 22 — whose exclusion
applies to constructors "marked `@internal`" — it is currently *supported
construction surface* by default rather than by decision. Because consumer test
suites plausibly build one, correction 4 must choose between annotating it
`@internal` and deliberately keeping it public; **this ADR recommends annotating
it**, and records the risk in section 19.

## 11. Container-binding policy

> **A container binding is not a public API declaration.**

`GateTrailServiceProvider::register()` performs **80 bindings**. Under this ADR,
**73 target Tier 4 symbols**. The bindings exist for the package's own
explicit-singleton convention and for lifecycle control, not to advertise
injectable services.

Consequences:

1. Only the **six contract bindings** are supported resolution targets: the five
   service contracts plus `Psr\Clock\ClockInterface`.
2. Resolving any other bound class — `AssignRoleHandler`, `RoleInputResolver`,
   `AuditContextBuilder`, `RetryOrchestrator`, `EffectiveAccessQueryBuilder`,
   `SubjectIdentityResolver`, `SchemaAdapterFactory` and 66 others — is
   depending on unsupported surface.
3. **Lifecycle is surface where behavior depends on it.** The seven `scoped`
   registrations exist because the memo holds one scope's authorization answers;
   a singleton would leak one request's state into the next under Octane or a
   queue worker. Changing the two resolver contracts from `scoped` to `singleton`
   would be a breaking behavioral change even though no signature moves.
4. The asymmetry that `DefaultPermissionResolver` and `DefaultRoleResolver` are
   bound under their own class names while the three `Default*Manager` classes
   are not is **not** meaningful. All five are Tier 4. Correction 11's annotation
   sweep removes the misleading signal.

## 12. Configurable-model and inherited-base policy

### 12.1 Requirements as the promise

`Support\ModelRegistry` enforces the rules below. They are already a contract in
practice, so the boundary states them explicitly. **Tightening any cell breaks
existing consumer models; loosening one is safe.**

| Config key | Default | Eloquent `Model` | Marker contract | Must extend default | Must use `SoftDeletes` |
|---|---|:-:|---|:-:|:-:|
| `role` | `Models\Role` | yes | `RoleContract` | **no** | **yes** |
| `permission` | `Models\Permission` | yes | `PermissionContract` | **no** | **yes** |
| `role_assignment` | `Models\RoleAssignment` | yes | `RoleAssignmentContract` | **yes** | **no** |
| `permission_assignment` | `Models\PermissionAssignment` | yes | `PermissionAssignmentContract` | **yes** | **no** |
| `role_permission` | `Models\RolePermission` | yes | `RolePermissionContract` | **yes** | **no** |
| `access_audit` | `Models\AccessAudit` | yes | **none** | **no** | **no** |

### 12.2 Three deliberate decisions about this table

**Role and Permission keep flexible replacement.** They must implement their
marker contract and use `SoftDeletes`, and they are **not** required to extend
`Models\Role` / `Models\Permission`. A consumer may supply a wholly independent
Eloquent model. This flexibility is preserved deliberately, and specifically
**not** traded away to make a generic annotation easier to write. The annotation
is what is wrong, not the flexibility — correction 1 and 2 change
`RoleManager::create()` and `PermissionManager::create()` to
`MutationResult<Model&RoleContract>` and `MutationResult<Model&PermissionContract>`,
matching the registry's actual contract and the sibling `update`, `deactivate`
and `restore` methods that already use intersections.

The same defect exists inside `ModelRegistry` itself: `role()` is annotated
`@var class-string<Role&RoleContract>` while validating only
`Model` + `RoleContract` + `SoftDeletes`. Correction 1 covers both sites.

**Assignment models are not required to use `SoftDeletes`.** Assignment lifecycle
is expressed by `starts_at`, `expires_at` and `revoked_at`; revocation is a
tuple, not a soft delete. Adding a `SoftDeletes` requirement for symmetry with
the definition models would impose a column and a global scope that contradict
the lifecycle design in spec 9.

**`AccessAudit` validation is a correction, not a silent change.** Per finding
S-2, the writer relies on three inherited behaviors that nothing validates:
`getConnectionName()`, `getTable()` via `tableKey() === 'access_audits'`, and
`const UPDATED_AT = null` against a table with no `updated_at` column. The
recommendation is to **require a configured access-audit model to extend
`Models\AccessAudit`**. An empty `AccessAuditContract` added for symmetry is
explicitly rejected: it would guarantee none of the three. This is recorded as
correction 5 and **is not applied by this ADR**; it changes runtime validation
behavior and needs its own increment.

### 12.3 The transitive inherited surface

`Models\GateTrailModel` is Tier 4, yet its supported subclasses expose its
behavior. Pretending the parent can change freely would be false.

Members that reach consumers through supported models:

| Member | Constraint |
|---|---|
| `getConnectionName(): ?string` | resolves the GateTrail connection through `SchemaContext`; a subclass overriding it can break the audit transaction guard |
| `getTable(): string` | resolves the configured table through `SchemaContext::table($this->tableKey())` |
| `abstract protected tableKey(): string` | **any consumer subclassing `GateTrailModel` directly must implement it**, returning a key `SchemaContext::table()` recognises |

The rule this ADR proposes: **`GateTrailModel`'s internal implementation may
change; the observable behavior of the six supported subclasses may not.**
Connection resolution, table resolution and the set of valid `tableKey()` values
are a transitive constraint. `SchemaContext` stays internal — the constraint is
documented at the model boundary, where consumers meet it, not by promoting the
collaborator.

The valid `tableKey()` values are the seven `gatetrail.tables` keys: `roles`,
`permissions`, `role_assignments`, `permission_assignments`, `role_permissions`,
`assignment_locks`, `access_audits`. Documenting that list is correction 6.

## 13. Public event policy

Both `MutationCommitted` and `AuditPersistenceFailed` are Tier 1 supported public
events. `Contracts\GateTrailEvent` is the public marker for every event GateTrail
dispatches.

### 13.1 The marker asymmetry is a defect

The evidence in finding E-3 is direct. `GateTrailEvent`'s own docblock states
that it "Marks **every** event GateTrail dispatches" and that "listening to this
interface receives **every** GateTrail event". `AuditFailureReporter` dispatches
`AuditPersistenceFailed` through the application dispatcher, and that class does
not implement the marker. **The marker's stated promise is false today.**

No repository evidence supports the alternative reading. ADR 0010 introduces the
marker to preserve a migration path toward per-mutation event classes and states
that "`MutationCommitted` and `GateTrailEvent` are public API"; it nowhere limits
the marker to mutation events. `AuditPersistenceFailed`'s docblock distinguishes
itself from the *successful mutation* event, which is a different claim from
being outside the marker.

**Proposed correction (correction 3): `AuditPersistenceFailed` implements
`Contracts\GateTrailEvent`.** The alternative — rewriting the marker's docblock
to say "every mutation event" — is rejected in section 20 because it narrows a
documented promise to match an omission.

### 13.2 The consequence this correction carries

Making the operational event implement the marker **changes what existing
catch-all listeners receive**. A listener registered against `GateTrailEvent` that
assumes a mutation and reads `$event->mutation` would begin receiving an event
without that property and fail at runtime.

This is a genuine behavioral change, acceptable pre-`1.0.0`, and it must ship
with a changelog entry and migration guidance under section 15. It also raises a
question the direction does not settle — how a catch-all listener should
distinguish event kinds once both implement one empty marker — recorded as open
question O-1.

### 13.3 Event construction and payload stability

- **Consumers receive events; they do not construct authentic package events.**
  `MutationCommitted` already enforces this with a private constructor behind an
  `@internal record()`. `AuditPersistenceFailed` must be aligned — correction 4.
- The **safe public fields** listed in section 5.4 and the behavior of
  `context()` — its keys, their fixed order, numeric identifiers rendered as
  decimal strings, `request_id` present only when captured — are stable.
- Payload contents stay safe by construction: no snapshots, no consumer metadata,
  no reasons, no credentials, no Eloquent models, no exception messages or
  traces. This ADR does not relax that.
- The **14-string mutation vocabulary** is compatibility surface; the
  `MutationName` class is not (section 5.4).
- `MutationCommitted` remains queueable and serializable per ADR 0010: scalars,
  one enum and one `DateTimeImmutable`.

## 14. Public exception policy

### 14.1 The split

The exception surface is split rather than treated uniformly.

| Tier | Count | Rationale |
|---|---:|---|
| **Tier 1** | 20 | Escape public managers, resolvers, audit and concurrency operations. Consumers catch them as control flow |
| **Operational** | 5 | Surface during installation, migration and deployment. Separate stability clock — section 7.2 |

Applying `PACKAGE_SPEC.md` section 22's blanket "exception types are part of the
compatibility surface" uniformly would freeze `DdlCompilerUnavailable` and
`SchemaInspectorUnavailable` — thrown only by factories serving DBA workflows
still under development — as tightly as `RoleNotFound`. The split keeps the
strong promise where consumers actually catch.

### 14.2 What is promised

1. **The class name and its catchability**, including the parent class, since
   consumers catch `RuntimeException` and `InvalidArgumentException` too.
2. **Explicitly documented readable fields only.** The clearest case is
   `ConcurrencyRetryExhausted`, whose seven public readonly fields —
   `$operation`, `$attempts`, `$callerOwned`, `$driver`, `$category`,
   `$sqlState`, `$vendorCode` — are already specified by spec 15 and ADR 0011,
   along with `getPrevious()` always being `null`. That level of specification is
   the target for the rest; until each is documented, only the type is promised.
3. **Safe diagnostics remain safe.** No exception gains raw SQL, bindings,
   identifiers, reasons, metadata or credentials.

### 14.3 What is not promised

**Package-only constructors and factories do not automatically become consumer
construction API.** The named factories — `RoleNotFound::forGuard()`,
`InvalidActor::valueTooLong()`, and the rest — exist for the package to raise
its own errors. Consumers **catch** these types; they do not construct them.
Section 10.2 records the 7 exceptions whose constructors are implicitly public
and should be aligned.

### 14.4 Specification alignment

- **Eight implemented exceptions are missing from spec section 15** and must be
  added later (correction 9): `AssignmentScopeCollision`, `DdlCompilerUnavailable`,
  `InvalidAuditConfiguration`, `InvalidDatabaseIdentifier`,
  `InvalidModelConfiguration`, `MigrationDependencyError`,
  `SchemaInspectorUnavailable`, `UnsupportedDatabaseDriver`.
- **Two spec-listed names do not exist**: `InvalidMiddlewareParameter` and
  `UnauthorizedMutation`, both Phase 6 middleware and authorization-gate work
  that has not started. They must stay labelled as future and **must not be
  documented as currently available** (correction 10).
- Some validation still raises PHP's own `InvalidArgumentException` rather than a
  GateTrail type — the three reference factories, both change objects and
  `MutationResult`'s constructor. A consumer catching by namespace will miss
  them. Whether to convert these is open question O-5.

## 15. Pre-v1 compatibility and change policy

`PACKAGE_SPEC.md` section 22 already says "APIs are experimental before
`1.0.0`". That stays true. This ADR adds one rule on top of it, because
"experimental" has been doing two jobs at once: permitting *deliberate* change,
and excusing *accidental* drift. Only the first is legitimate.

### 15.1 The proposed rule

**APIs remain experimental before `1.0.0`.** A consumer building on Tier 1 or
Tier 2 today is building on a surface that may still change.

**A deliberate breaking change to the accepted public or extension surface
requires all three of:**

1. **an ADR** recording what changes and why;
2. **a changelog entry**;
3. **migration guidance** whenever consumers must change code or configuration.

**Accidental drift is a defect, not a change.** It is caught by the automated
surface test of section 18, not by review discipline.

### 15.2 What counts as breaking, per tier

| Tier | Breaking |
|---|---|
| Tier 1 | removing or renaming a symbol; changing a method signature, parameter **name** or return type; removing or retyping a readable field; removing an enum case or changing its backing value; removing a mutation-vocabulary string; narrowing what an exception is thrown for |
| Tier 2 | adding a method to a marker contract; tightening a `ModelRegistry` requirement; changing an inherited relation, cast or scope signature; changing `scopeActiveAt()`'s semantics |
| Framework | renaming the `gatetrail-config` publish tag; changing a contract binding's lifecycle where behavior depends on it |
| Operational | changing a generated artifact's bytes within a schema release; removing an operational exception type within a schema release |
| Internal | **nothing.** Any change is permitted in any release, including a patch |

Adding a Tier 1 symbol, adding a field to a package-constructed type, adding an
enum case, or adding a new exception type is **not** breaking.

### 15.3 What this policy deliberately does not promise

Full post-`1.0.0` semantic-versioning rigidity is **not** adopted now. Phases
6–13 will add Laravel Gate integration, middleware, subject convenience methods
and possibly a cross-request cache. The resolver contracts explicitly await a
Phase 6 adapter to narrow untyped framework subjects. Committing to full
semver before those surfaces exist would freeze types that the integration work
is likely to reshape, and the package would either break its own promise or
carry a bad design to `1.0.0`.

The lighter rule removes silent breakage without pretending the design is
finished.

## 16. Future Phase 6–13 additive API rule

**This boundary covers only currently implemented and accepted Phase 4 and Phase
5 surfaces.** It is a statement about what exists at commit `992af7b`, not a
forecast.

1. **Phases 6–13 may add new APIs.** Additive surface does not violate this
   boundary and does not require an ADR amending it.
2. **Each future phase must classify its own added public surface before that
   phase can be completed.** A phase that ships a new consumer-facing type
   without assigning it a tier has not finished.
3. **Unimplemented surfaces must not be listed as available anywhere** — not in
   `docs/PUBLIC_API.md`, not in the README, not in the consumer guide. This
   applies to:
   - the nine-method subject API of spec 12.2 (`hasRole()`, `hasAnyRole()`,
     `hasAllRoles()`, `hasPermissionTo()`, `effectivePermissions()`, `roles()`,
     `directPermissions()`, `roleAssignments()`, `permissionAssignments()`) —
     `Concerns\HasGateTrail` declares **one** method today, and it is `@internal`;
   - the Laravel `Gate::before` hook (spec 12.3) and policies (12.4);
   - middleware and its aliases (spec 12.5, ADR 0004, ADR 0007);
   - Blade directives (spec 12.6);
   - all six console commands of spec 18, including `gatetrail:doctor` and
     `gatetrail:cache-clear` — **zero `Console`/`Command` classes exist**;
   - every cross-request cache surface, which accepted ADR 0013 defers to Phase
     8 and leaves as a reserved, currently empty ordering slot;
   - `InvalidMiddlewareParameter` and `UnauthorizedMutation`.
4. **A phase that changes an existing Tier 1 or Tier 2 symbol is not additive**
   and falls under section 15.1.

## 17. Known defects that must be corrected before freeze

Fourteen items. **None is fixed by this ADR** — it changes no source, no test,
no specification and no annotation. Each becomes a post-acceptance increment in
section 22.

| # | Correction | Evidence | Blocks freeze? |
|---:|---|---|:-:|
| 1 | `RoleManager::create()` generic → `MutationResult<Model&RoleContract>`; same for `ModelRegistry::role()`'s `@var` | E-2 | **Yes** |
| 2 | `PermissionManager::create()` generic → `MutationResult<Model&PermissionContract>`; same for `ModelRegistry::permission()`'s `@var` | E-2 | **Yes** |
| 3 | `Events\AuditPersistenceFailed` implements `Contracts\GateTrailEvent` | E-3, §13.1 | **Yes** |
| 4 | Align package-only construction: annotate or privatise the constructors of `MutationResult`, `AuditPersistenceFailed`, `ActorIdentity`, and the 7 implicitly-public Tier 1 exceptions | §10.2 | **Yes** |
| 5 | Resolve `AccessAudit` model validation — require the configured model to extend `Models\AccessAudit` | S-2, E-4 | **Yes** |
| 6 | Document `GateTrailModel`'s transitive inherited surface and the seven valid `tableKey()` values | §12.3 | **Yes** |
| 7 | Decide and document `HasAssignmentPeriodScopes` scope stability — `activeAt()` settled, the other three open | S-1, O-2 | **Yes** |
| 8 | Correct the stale `Audit\AuditMode::Disabled` "Not implemented yet" comment | E-7 | No |
| 9 | Add the eight implemented-but-undocumented exceptions to `PACKAGE_SPEC.md` section 15 | E-6, §14.4 | No |
| 10 | Keep `InvalidMiddlewareParameter` and `UnauthorizedMutation` labelled as future/unimplemented | §14.4, §16 | No |
| 11 | Apply `@internal` consistently across `Default*` classes, handlers, input resolvers, query components and infrastructure — ~140 symbols currently unannotated | E-1 | **Yes** |
| 12 | Build a reflection-based public surface test | §18 | **Yes** |
| 13 | Add contract container-resolution tests where missing, including scoped-lifecycle assertions | §11 | No |
| 14 | Write `README.md` and the consumer guide **only after** the corrected surface exists | E-5 | **Yes** — for Phase 4, not for this ADR |

**Nine of the fourteen block the freeze.** Corrections 1–7 and 11–12 change what
would otherwise be frozen; 14 is the Phase 4 deliverable itself. Corrections 8,
9, 10 and 13 are documentation and test hygiene that can follow.

> **This is why the freeze cannot happen in this increment.** Freezing before
> corrections 1–5 would make a wrong generic, a false marker promise, an
> unannotated constructor and an unvalidated audit model into compatibility
> obligations.

## 18. Required automated safeguards

Review discipline does not scale to 218 symbols. Three safeguards are required
before the freeze; the rest are recommended.

### 18.1 Required

**A reflection-based public surface test.** It asserts the exact set of Tier 1
and Tier 2 fully-qualified names, and for each: public method names, parameter
names, parameter types, defaults, return types, readable field names and types,
enum cases and their backing values, and constructor visibility. A change to any
of them fails the suite.

This is what makes section 15's distinction enforceable: a **deliberate** change
updates the test's expectations in the same commit as the ADR and changelog
entry; **accidental** drift fails CI. It is also what keeps
`docs/PUBLIC_API.md` honest, since the document and the test assert the same
list. The technique is already established — 20+ files under `tests/` use
`ReflectionClass` or `ReflectionMethod`.

**Container-resolution tests.** Assert that the five service contracts resolve,
that they resolve to the expected concretes, and that `PermissionResolver` and
`RoleResolver` are genuinely **scoped** — the Octane and queue-worker leak the
provider's own comments warn about is otherwise invisible until production.

**An event-listener test for the marker.** After correction 3, assert that a
listener registered against `Contracts\GateTrailEvent` receives **both**
`MutationCommitted` and `AuditPersistenceFailed`. This is the regression test for
the defect in section 13.1 and the reason the marker's promise becomes checkable
rather than aspirational.

### 18.2 Recommended, not required

- **PHPStan internal-boundary enforcement.** `larastan ^3.10` is already a dev
  dependency and PHPStan honours `@internal` across package boundaries. The value
  mostly appears in *consumer* analysis rather than the package's own run, so it
  is worth enabling at no new dependency cost but is not worth a custom rule yet.
- **Executable documentation examples.** Every README and guide example should
  exist as a test. Scope it to the examples actually shipped.

### 18.3 Deferred

- **Backward-compatibility diff tooling.** It adds a dependency and CI time to a
  pre-`1.0.0` package that has not frozen anything. The reflection surface test
  delivers most of the benefit at none of the cost. Revisit at `1.0.0`.

## 19. Consequences

### 19.1 Positive

- **A small, intentional supported surface.** 39 Tier 1 plus 13 Tier 2 symbols —
  23.9% of the package — carry a promise. That is a surface a consumer can learn
  and a maintainer can defend.
- **Contract-first dependency.** Consumers depend on five interfaces, not on
  five concrete classes and their nine injected collaborators.
- **Internal refactoring freedom.** 157 symbols (72.0%) may be recomposed,
  renamed or deleted. The handler-per-use-case design of ADR 0002, the resolver
  internals of ADR 0012 and the retry machinery of ADR 0011 stay changeable.
- **An honest operational boundary.** DBAs get artifacts, provisioning modes and
  publish tags — the things they actually consume — instead of 38 implementation
  classes they would have to pin.
- **Future phases can add APIs deliberately.** The additive rule of section 16
  lets Phase 6–13 grow the surface without amending this boundary, provided each
  phase classifies what it adds.
- **Defects surface now rather than at `1.0.0`.** Five of the corrections are
  real bugs that a freeze would have preserved permanently.

### 19.2 Negative

- **Some technically reachable classes become unsupported.** All 218 remain
  autoloadable; 161 gain no promise. Nothing prevents a consumer from using them,
  and nothing protects that consumer afterwards.
- **Consumers already using internals may need migration guidance.** The package
  is pre-`1.0.0` and private, so the population is small — but the annotation
  sweep of correction 11 will change what IDEs and static analysers report, and
  that is visible.
- **Model inheritance creates unavoidable transitive commitments.** Supporting
  six configurable models means committing to behavior declared in an internal
  parent (§12.3) and to a trait's `activeAt()` semantics (§6.3). This cannot be
  designed away while models are configurable; it can only be documented.
- **Correction work is required before documentation can be finalized.** Nine
  blocking items stand between this ADR and the freeze. Phase 4 cannot complete
  until they land, and correction 14 explicitly sequences the README **after**
  them — writing the guide first would document a surface that is about to change.
- **Operational tooling stays artifact-driven.** Until the spec 18 commands
  exist, a DBA's supported entry points are generated SQL files and configuration.
  There is no supported programmatic inspection API, so a platform team wanting
  one must wait or use unsupported classes.
- **Correction 3 changes behavior for catch-all listeners.** Any listener bound
  to `GateTrailEvent` that assumes a mutation payload will start receiving
  `AuditPersistenceFailed` (§13.2).
- **Annotating `MutationResult`'s constructor `@internal` may break consumer test
  suites** that construct one to stub a manager. That is the intended outcome —
  it was never a decided public constructor — but it is a real cost and needs
  migration guidance.

### 19.3 Neutral

- Tier 1's count is unchanged from the inventory's preliminary A count of 39,
  but this is a recomputation that happened to agree, not an adoption (§4.1).
- The operational tier shrinks from 43 preliminary symbols to 5, because its
  substance moved to artifacts and modes, which are not symbols.
- No `@internal` annotation is removed anywhere by this ADR or its corrections.

## 20. Rejected alternatives

**A. Treat all 218 autoloadable symbols as public.** Rejected. It is what PSR-4
already implies and what this ADR exists to deny. It would freeze every handler,
command, input resolver and schema compiler, ending ADR 0002's internal-command
design and ADR 0012's resolver internals as changeable code.

**B. Treat every container binding as public API.** Rejected. 73 of the 80
bindings target internal symbols. They exist for the package's
explicit-singleton convention and for scoped-lifecycle control, not to advertise
services. A binding is a construction instruction, not a promise.

**C. Freeze all 43 preliminary operational classes.** Rejected. Spec 3.7 requires
generated artifacts to stay byte-for-byte reproducible — a promise about
**outputs**. Freezing `MySqlDdlCompiler`, `OracleSchemaInspector` and 36 siblings
would freeze the machinery instead, blocking exactly the work that will extend
PostgreSQL and SQL Server support.

**D. Make the diagnostics public because package tests use them.** Rejected.
Package tests are the package. `GateTrailStatementProbe` and
`StatementMeasurement` already carry `@internal` reading "Test and diagnostic use
only. Not consumer API." Query-budget verification is discharged by GateTrail's
own suite; a consumer needing equivalent assurance can register a
`Connection::listen()` callback without any GateTrail type.

**E. Freeze unimplemented Phase 6–13 APIs.** Rejected. It would be a promise
about code nobody has written. Spec 12.2 already calls its subject API
"Proposed", and Inventory 0002 finding M-2 shows even a labelled proposal reads
as available; writing it into a freeze document would remove the label entirely.

**F. Require Role and Permission models to extend the default base models so the
generic annotations become correct.** Rejected. It repairs a docblock by removing
a real capability. `ModelRegistry` deliberately permits an independent Eloquent
model implementing the marker contract with `SoftDeletes`. The annotation is the
error; corrections 1 and 2 fix the annotation.

**G. Add an empty `AccessAuditContract` marker for symmetry with the other five
models.** Rejected on direct evidence. Finding S-2 shows the writer depends on
`getConnectionName()`, `getTable()` via `tableKey()`, and `const UPDATED_AT = null`
against a table with no `updated_at` column. An empty marker guarantees **none**
of the three. Symmetry that guarantees nothing is worse than an acknowledged gap,
because it looks like validation. Correction 5 requires the base model instead.

**H. Declare that `GateTrailModel` may change freely because it is internal.**
Rejected. Six supported models extend it, and its `getConnectionName()`,
`getTable()` and `abstract tableKey()` reach consumers through them. The honest
position is §12.3: the implementation may change, the observable subclass
behavior may not.

**I. Document the event-marker contradiction as intentional instead of fixing
it.** Rejected. The marker's own docblock claims it marks "every event GateTrail
dispatches", and `AuditPersistenceFailed` is dispatched. No repository evidence
narrows that promise — ADR 0010 calls the marker public API without limiting it
to mutation events. Rewriting the docblock would narrow a documented promise to
match an omission; correction 3 fixes the omission instead.

**J. Promote `Support\ModelRegistry` to Tier 1 as a model-discovery API.**
Rejected for now. Its six accessors would be a plausible discovery surface, but
adopting it freezes today's implementation — including the positional-argument
validation quirk behind E-2. If discovery is needed, a small explicit contract
should be introduced. Recorded as O-4 because the workaround has a real cost.

**K. Promote `Database\Schema\SchemaContext` to the operational tier.** Rejected.
Its eight public methods are configuration resolution, not an operations
interface. The consumer-visible constraint is documented at the model boundary
(§12.3) where consumers actually meet it.

## 21. Open maintainer questions

Q1–Q12 of Inventory 0002 are **decided** by sections 5–14 and are not repeated.
Five questions genuinely remain after applying those directions.

**O-1. Once both events implement `GateTrailEvent`, how should a catch-all
listener distinguish event kinds?**
*Recommendation:* Ship correction 3 as-is and rely on `instanceof`. Add a second,
narrower marker only if a real listener needs it.
*Alternative:* Introduce `Contracts\MutationEvent` alongside `GateTrailEvent`, so
catch-all listeners can bind to the narrower one.
*Consequence:* Doing nothing leaves every catch-all listener responsible for its
own type check, and a listener that forgets will fatal on a missing `$mutation`
property. Adding a second marker now creates a two-marker vocabulary before any
consumer has asked for it, and ADR 0010 chose one marker deliberately.
*Blocks acceptance?* **No.** It refines correction 3; it does not change the
boundary.

**O-2. Are `scopeScheduledAt`, `scopeExpiredAt` and `scopeRevoked` compatibility
surface?**
*Recommendation:* Yes — include all three in Tier 2 alongside `activeAt()`. They
are inherited by every supported assignment model, tested by
`tests/Models/AssignmentPeriodScopeTest.php`, and are the natural way to query
assignment lifecycle.
*Alternative:* Support only `activeAt()`, since it is the only scope the package
itself calls, and leave the other three unsupported.
*Consequence:* Supporting all four freezes three query shapes the package does
not use, so a lifecycle redesign would need a major version. Supporting only
`activeAt()` leaves consumers with an inherited, tested, obvious-looking API that
carries no promise — the exact confusion this ADR exists to remove.
*Blocks acceptance?* **No**, but it blocks correction 7 and therefore the freeze.

**O-3. Is rebinding `Psr\Clock\ClockInterface` a supported testing seam?**
*Recommendation:* Yes, document it as supported. The interface is PSR's, the
binding already exists, and time control is the single most common authorization
test need.
*Alternative:* Leave it undocumented and internal.
*Consequence:* Supporting it commits GateTrail to resolving time through the
container rather than calling a clock directly — a real constraint, but one the
current design already accepts. Leaving it undocumented means consumers testing
scheduled and expiring assignments will rebind it anyway, unsupported.
*Blocks acceptance?* **No.** Later implementation detail.

**O-4. Should a small model-discovery contract replace `ModelRegistry` for
consumers?**
*Recommendation:* Defer until a consumer needs it. `ModelRegistry` stays internal
(§9).
*Alternative:* Introduce a `Contracts\ModelResolver` with the six accessors now.
*Consequence:* Deferring means a consumer who extends `Models\Role` and needs the
configured class either resolves an internal singleton or re-reads
`config('gatetrail.models.role')` and duplicates the validation — silently
skipping every `InvalidModelConfiguration` check. Introducing it now adds a
sixth supported contract before the need is demonstrated.
*Blocks acceptance?* **No.**

**O-5. Should the value types raise GateTrail exceptions instead of PHP's
`InvalidArgumentException`?**
*Recommendation:* Yes, eventually — `AssignmentReference::id()`,
`RoleReference::id()`, `PermissionReference::id()`, `RoleChanges::description()`,
`PermissionChanges::description()` and `MutationResult::__construct()` currently
throw PHP's own type.
*Alternative:* Leave them; they are documented as `InvalidArgumentException` and
that is catchable.
*Consequence:* Leaving them means a consumer catching GateTrail errors by
namespace misses six validation paths on Tier 1 types. Changing them is itself a
breaking change for anyone catching the PHP type, so it must happen **before**
the freeze or not until a major version.
*Blocks acceptance?* **No**, but the timing is forced: pre-freeze or post-`1.0.0`.

## 22. Ordered post-acceptance increments

Each increment is atomic, independently reviewable, and leaves the suite green.
**No implementation occurs in this ADR draft.**

| # | Increment | Contents | Corrections |
|---:|---|---|---|
| 1 | **Public signature and event corrections** | manager `create()` generics and the matching `ModelRegistry` `@var`s; `AuditPersistenceFailed implements GateTrailEvent`; package-controlled constructor alignment across `MutationResult`, `AuditPersistenceFailed`, `ActorIdentity` and the 7 implicitly-public exceptions | 1, 2, 3, 4 |
| 2 | **Configurable-model validation and inherited surface** | require the access-audit model to extend `Models\AccessAudit`; document `GateTrailModel`'s transitive surface and the seven `tableKey()` values; settle O-2 and document scope stability | 5, 6, 7 |
| 3 | **`@internal` annotation sweep** | apply `@internal` to every Tier 4 symbol currently unannotated — `Default*` managers and resolvers, input resolvers, query components, schema and DDL classes, `ModelRegistry`, `SchemaContext`, infrastructure. Remove none | 11 |
| 4 | **Reflection public-surface test** | assert the exact Tier 1 and Tier 2 manifest: names, signatures, parameter names, fields, enum cases and backing values, constructor visibility | 12 |
| 5 | **Container-resolution and event-listener tests** | five contracts resolve to expected concretes; resolver contracts genuinely scoped; a `GateTrailEvent` listener receives both events | 13 |
| 6 | **`PACKAGE_SPEC.md` alignment** | add the eight missing exceptions; keep the two Phase 6 names labelled unimplemented; correct the stale `AuditMode::Disabled` comment; reconcile section 22 with these tiers | 8, 9, 10 |
| 7 | **`docs/PUBLIC_API.md`** | the authoritative Tier 1 and Tier 2 list by FQCN with exact signatures, asserting the same set as increment 4's test | — |
| 8 | **README and consumer guide** | installation, configuration, publishing, migration, a first mutation and a first check, and the tier boundary — **written only now**, against the corrected surface | 14 |
| 9 | **Final independent freeze review** | verify every blocking correction landed; verify no Phase 6–13 surface is documented as available; verify the surface test matches `PUBLIC_API.md` | — |
| 10 | **Phase 4 acceptance and completion** | accept the freeze; check the final Phase 4 item; recompute Phase 4 to 20 of 20 | — |

Increments 1 and 2 change runtime behavior and must not be combined. Increment 3
is mechanical and touches no logic. Increment 8 depends on increments 1–7 because
documenting a surface that is about to change is the failure this ADR was written
to prevent.

## 23. Acceptance criteria

This ADR may be accepted when:

1. Maintainers agree with the five-tier model of section 4 and the
   reachable-versus-supported distinction of section 3.
2. The Tier 1 manifest of section 5 is agreed — all 39 fully-qualified names.
3. The Tier 2 manifest of section 6 is agreed — all 13 names, including the
   decision that `Models\GateTrailModel` is **not** a direct extension target.
4. The operational policy of section 7 is agreed, including that the promise
   attaches to artifacts, provisioning modes and publish tags rather than to the
   38 implementation classes.
5. The internal classification of section 9 is agreed — 157 symbols, 72.0% of the
   package.
6. The event decision of section 13 is agreed, specifically that the
   `GateTrailEvent` asymmetry is a **defect to correct** rather than an intention
   to document.
7. The exception split of section 14 is agreed — 20 Tier 1, 5 operational.
8. The model policy of section 12 is agreed, specifically: Role and Permission
   keep flexible replacement; assignment models are **not** required to use
   `SoftDeletes`; `AccessAudit` validation is a correction requiring the base
   model, not a new empty marker.
9. The pre-`1.0.0` change policy of section 15 is agreed.
10. The correction backlog of section 17 is agreed, including which nine items
    block the freeze.
11. The required safeguards of section 18 are agreed.
12. Open questions O-1 through O-5 are either answered or explicitly accepted as
    non-blocking.

### What acceptance would and would not do

**Acceptance would:** settle the boundary; authorise the increment sequence of
section 22; make the correction backlog binding.

**Acceptance would not:** freeze any API; change any source file, annotation or
signature; accept any documentation promise; complete Phase 4; or alter any
progress figure. Phase 4 remains **19 of 20 = 95%** until increment 10.

## References

- [Public API Inventory 0002](../reviews/0002-public-api-inventory.md) — the
  218-symbol evidence base
- [ADR 0002](0002-public-mutation-api.md) — simple manager methods, internal
  commands and handlers
- [ADR 0010](0010-after-commit-mutation-events.md) — `MutationCommitted`, the
  `GateTrailEvent` marker, and their public-API status
- [ADR 0011](0011-bounded-concurrency-retry.md) — `ConcurrencyRetryExhausted`'s
  documented field contract
- [ADR 0012](0012-effective-access-resolution.md) — package-controlled
  construction of `EffectivePermission` and `EffectiveRole`
- [ADR 0013](0013-after-commit-cache-invalidation.md) — cross-request caching
  deferred to Phase 8; no cache API exists
- `PACKAGE_SPEC.md` sections 3.4, 3.7, 3.8, 10.6, 10.7, 12, 13.6, 15, 16, 18, 22

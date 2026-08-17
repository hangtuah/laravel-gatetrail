# ADR 0012: Resolve Effective Access Once and Expose It Through Two Thin Contracts

- Status: **Accepted**
- Proposed: 2026-08-12
- Accepted: 2026-08-13
- Decision owners: GateTrail maintainers
- Implementation status: **In progress** — Increments 1–3 of 10 complete,
  2026-08-17. See [Implementation increments](#16-implementation-increments).
- Acceptance evidence: **partially produced.** The Increment 1, 2 and 3
  evidence exists; the resolver acceptance matrix does not — see
  [Acceptance criteria](#17-acceptance-criteria)
- Supersedes: nothing
- Blocks: Phase 5 implementation, and the Phase 6 Gate, concern and middleware
  adapters that depend on these signatures
- Review disposition: all ten review questions are resolved. See
  [Decision disposition](#19-decision-disposition)

**What "Accepted" means here.** The *architectural decision* is accepted: the
contracts, the shared snapshot, the lifecycle algorithm, the query
architecture, the memoization rules and the evidence matrix below are settled
and no longer awaiting maintainer approval.

**Implementation is separate and has only begun.** Increment 1 — the shared
subject-identity foundation — Increment 2 — the value objects and the two
public contracts — and Increment 3 — the immutable `EffectiveAccessSnapshot` —
are complete and evidenced. **No resolver implementation, authorization query,
lifecycle filter, guard resolution, memoization or cache behaviour exists.**
The snapshot is a container for values something else must still produce: it
holds an evaluated state, it does not evaluate one. The two contracts remain
deliberately unbound, and no resolver acceptance evidence has been produced.
The acceptance criteria in section 17 remain the definition of done for
Phase 5; only the subject-identity portion of that matrix has been satisfied.

## Context

Phase 4 is complete except for after-commit cache invalidation and the consumer
documentation freeze. Every managed mutation now writes history-preserving
assignment rows. Nothing yet reads them for an authorization decision.

`PACKAGE_SPEC.md` section 11 already names two resolvers and gives both an
*illustrative* contract whose subject parameter is `mixed`. Section 11.3 says
plainly that "public input types must be finalized before `0.1.0`". Phase 6
then builds five separate adapters — the subject concern, the `Gate::before`
hook, six middleware aliases, Blade through Gate, and `Gate::forUser()` — on
top of whatever Phase 5 exposes. Every one of those adapters is required by
ADR 0004 and ADR 0007 to contain no authorization algorithm of its own.

So the resolver contract is not an internal detail that can be adjusted later.
It is the load-bearing surface for the whole of Phase 6, and it has to be
decided before code is written.

`PACKAGE_SPEC.md` also defines **two** resolvers while `ROADMAP.md` Phase 5
lists only one. Section 2.4 treats that as a roadmap defect and records the
correction; it is not silently applied.

## Existing constraints

Six facts about the current codebase constrain this design more tightly than
the specification text does. Each is load-bearing for a decision below.

**1. `SubjectGuardResolver::resolve()` already takes
`Illuminate\Database\Eloquent\Model`.** Guard resolution — which every
authorization call must perform first — cannot accept a non-model subject
today. A resolver contract accepting `mixed` would have to narrow to `Model`
immediately anyway. Drives section 1.2.

**2. `RoleContract`, `PermissionContract` and the three assignment contracts
are empty marker interfaces.** They carry no accessors. A resolver cannot read
a name, a guard or an identifier *through* the contract; it must go through
Eloquent, and any class at all can implement one. Drives sections 1.4 and 4.4.

**3. All four definition relations are deliberately `withTrashed()`.**
`RoleAssignment::role()`, `PermissionAssignment::permission()`,
`RolePermission::role()` and `RolePermission::permission()` each call it, so
that assignment history and audit snapshot rebuilding can still reach a
soft-deleted definition. Drives section 5.3.

**4. `AssignmentConflictQuery` establishes the package's portability pattern**:
a portable SQL prefilter, then domain-level verification in PHP
(`AssignmentPeriodOverlap::overlaps()`). Correctness is never delegated to
engine-specific SQL semantics. Drives sections 1.5 and 6.

**5. `HasAssignmentPeriodScopes::scopeActiveAt()` already implements section
9.3's half-open rule** in portable Eloquent, including the
`starts_at ?? assigned_at` fallback. Phase 5 must reuse it rather than write a
second lifecycle predicate. Drives section 5.

**6. Subject identity is already duplicated and already drifting.**
`AssignRoleHandler::subjectIdentity()` and
`GrantDirectPermissionHandler::subjectIdentity()` are two private copies with
**different** validation — the first rejects an empty morph class, the second
does not — and both throw a generic `InvalidArgumentException` rather than a
typed exception. Drives section 3.1.

## Decision

GateTrail will compute **one immutable `EffectiveAccessSnapshot`** per
`(subject identity, guard, evaluation instant)` and expose it through **two
thin public contracts** that share it.

```text
PermissionResolver  ─┐
                     ├─▶ EffectiveAccessResolver ─▶ EffectiveAccessSnapshot
RoleResolver        ─┘        (@internal)              (@internal)
                                    │
                                    └─▶ SubjectIdentityResolver (@internal)
                                        shared with the mutation handlers
```

Neither public contract contains a lifecycle rule, a guard rule, a query or an
ordering rule. Both are membership tests and projections over the same
evaluated state.

Resolver reads are read-only, take no lock, and open no package-owned
transaction. Section 9 states the consistency this does and does not buy.

## 1. Public contracts

### 1.1 Accepted pre-v1 signatures

```php
namespace HangTuah\GateTrail\Contracts;

use HangTuah\GateTrail\Access\EffectivePermission;
use HangTuah\GateTrail\PermissionReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

interface PermissionResolver
{
    public function allows(
        Model $subject,
        PermissionContract|PermissionReference|string|int $permission,
    ): bool;

    /**
     * @return Collection<int, EffectivePermission>
     */
    public function effectivePermissions(Model $subject): Collection;
}
```

```php
namespace HangTuah\GateTrail\Contracts;

use HangTuah\GateTrail\Access\EffectiveRole;
use HangTuah\GateTrail\RoleReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

interface RoleResolver
{
    public function has(
        Model $subject,
        RoleContract|RoleReference|string|int $role,
    ): bool;

    /**
     * @return Collection<int, EffectiveRole>
     */
    public function effectiveRoles(Model $subject): Collection;
}
```

### 1.2 Subject input type

**Decided: `Illuminate\Database\Eloquent\Model`, not `mixed`.**

The specification's `mixed` was explicitly provisional. `Model` is chosen
because:

- `SubjectGuardResolver::resolve(Model $subject)` already requires it, and
  guard resolution is unavoidable on every path;
- assignment rows are polymorphic (`subject_type`, `subject_id`) and a morph
  type is only derivable from `Model::getMorphClass()`;
- it gives PHPStan at level max a real type to check at every call site,
  including the six middleware classes and the subject concern;
- it removes an entire class of runtime failure from Phase 6 adapters.

A dedicated `GateTrailSubject` interface was considered and **rejected for
v1**: ADR 0003 already decided that "consumers must not be required to
implement an additional interface merely to use the method or property
override". Requiring one to be authorized at all would be a strictly larger
imposition. `Model` keeps `HasGateTrail` optional, exactly as ADR 0003
intended.

**Reconciling this with ADR 0007.** Laravel hands `Gate::before` a `mixed`
subject and ADR 0007 requires `null` — "GateTrail does not own this check" —
for a subject GateTrail cannot manage. That narrowing is a **Phase 6 adapter
responsibility, not a contract responsibility**:

```text
Gate::before(mixed $subject, ...)
  → if (! $subject instanceof Model)        return null;   // not ours
  → if (! subject identity is resolvable)   return null;   // not ours
  → PermissionResolver::allows(Model $subject, ...)        // typed from here
```

The hook returns `null` before the resolver is ever called. The resolver itself
never sees an unsupported subject and never returns `null`.

### 1.3 Permission and role input types

`PermissionContract|PermissionReference|string|int` and
`RoleContract|RoleReference|string|int` are kept from the specification
unchanged. They are already precise, already used by the mutation input
resolvers, and contain no `mixed`.

Because the marker interfaces are empty (constraint 2), a definition-model
argument is useful **only** for its persisted identifier, and only when it is
an instance GateTrail actually owns. Section 4 defines both rules.

### 1.4 Return type and element type

**Decided: `Illuminate\Support\Collection` of immutable, package-constructed
value objects.**

```php
namespace HangTuah\GateTrail\Access;

final readonly class EffectivePermission
{
    private function __construct(
        public int|string $id,
        public string $name,
        public string $guardName,
    ) {}

    /**
     * @internal Package-controlled construction. Not supported consumer API.
     */
    public static function of(
        int|string $id,
        string $name,
        string $guardName,
    ): self {
        return new self($id, $name, $guardName);
    }
}

final readonly class EffectiveRole
{
    private function __construct(
        public int|string $id,
        public string $name,
        public string $guardName,
    ) {}

    /**
     * @internal Package-controlled construction. Not supported consumer API.
     */
    public static function of(
        int|string $id,
        string $name,
        string $guardName,
    ): self {
        return new self($id, $name, $guardName);
    }
}
```

Both classes have the identical three-field shape. The field is `guardName`,
not `guard`, matching the `guard_name` column and the `guard_name` key already
used throughout the audit snapshots and the mutation exceptions.

Four candidate element types were compared.

| Element type | Verdict |
|---|---|
| Configured Eloquent models | **Rejected.** A model is mutable, carries a live connection, and exposes lazy relations that reintroduce N+1 from consumer code. Handing memoized models to a consumer lets `$permission->name = 'x'` corrupt the snapshot. |
| `PermissionContract` / `RoleContract` | **Rejected.** Empty marker interfaces. A consumer receiving one could read nothing from it without casting back to a model. |
| Plain name strings | **Rejected.** Discards the identifier and the guard, so a consumer cannot correlate a result with a definition row, and `effectivePermissions()` could not be used to build an admin view. |
| **Immutable value objects** | **Decided.** Safe to hand out, cheap to construct, carry exactly the three fields every consumer needs, and cannot alias snapshot state. |

`Collection` is retained rather than a bespoke collection class because the
specification already promises it, consumers expect Laravel semantics
(`pluck()`, `contains()`, `map()`), and a custom collection would be public API
that has to be maintained from `0.1.0`.

#### They are output only

**`EffectivePermission` and `EffectiveRole` are output value objects. Neither
resolver accepts them as input.**

- **Package-produced** instances are returned through the resolver collections
  from `effectivePermissions()` and `effectiveRoles()`.
- Each call returns a **fresh `Collection`** — see section 1.7.
- Entries are `final readonly` and cannot be modified in place.
- **No resolver method accepts them as authorization evidence.** They are
  absent from every declared input union in section 1.1, so passing one to
  `allows()` or `has()` is outside the contract and is **rejected by PHP's type
  boundary** — a `TypeError`, not a package decision and not a `false` return.
- Authorization must be requested through the declared inputs:
  `PermissionContract|PermissionReference|string|int` for permissions, and
  `RoleContract|RoleReference|string|int` for roles.

An earlier draft said a consumer-constructed instance could be "passed back
into the resolver" and would return `false`. That was wrong in a way worth
correcting rather than softening: it implied an input path that does not exist.
The type boundary rejects it before any resolver logic runs.

#### Construction is package-controlled

**Decided: construction of `EffectivePermission` and `EffectiveRole` belongs to
the package.** An earlier draft recommended supported public consumer
construction. That recommendation is **rejected**.

- The constructors are **private**.
- A **package-internal named factory** — `of()` above — is used by the
  `EffectiveAccessResolver` and `EffectiveAccessSnapshot` implementation to
  build instances.
- The factory is marked **`@internal`** and is **not supported consumer API**.
- Consumers receive package-produced instances through `effectivePermissions()`
  and `effectiveRoles()`. That is the only supported way to obtain one.
- Consumers do not construct these objects as authorization evidence.
- Tests that need instances should use the package's test fixtures and
  builders, or exercise real resolver output. A test that fabricates an
  effective permission is asserting against its own fixture, not against
  GateTrail's resolution rules.
- **No public consumer factory is promised**, now or at `1.0.0`.
- **Constructor parameters are not public compatibility surface.**

This follows the pattern already used by `MutationCommitted`, whose constructor
is private behind an `@internal` static `record()`, and whose docblock states
that "consumers receive instances and never construct them". These value
objects are the read-side equivalent of that rule.

**PHP has no package-private visibility.** A `public static` factory is the
only way one class in the package can build another without reflection, so
`of()` is technically callable from consumer code. That technical callability
does not make it supported consumer construction:

- it is marked `@internal`, which static analysers honour and which documents
  intent at the call site;
- it is **excluded from the public API promise** of specification section 22,
  exactly as internal commands, handlers and adapters are;
- a consumer that calls it is depending on unsupported surface and gets no
  compatibility guarantee — the name, the parameters and the existence of
  `of()` may change in any release, including a patch.

The practical benefit is compatibility headroom. Because no supported consumer
construction exists, the parameter list stops being frozen surface, and a
future field can be added without breaking any supported call.

#### Compatibility of the excluded fields

**Deliberately excluded from v1: source attribution.** These objects do not say
*which* role or direct assignment produced a permission. "Explainable access"
is a Phase 14 optional feature that needs its own specification and review, and
adding it now would broaden the pre-v1 surface without evidence.

An earlier draft claimed a field "can be added later without a breaking
change". That claim is **not restored** by the move to package-controlled
construction, because the *read* side is still public:

- reading `$permission->id`, `->name` and `->guardName` is supported, so those
  three fields cannot be removed or retyped after `1.0.0`;
- **adding** a field is compatible for readers, and — with construction now
  package-controlled — no longer breaks a supported construction call;
- consumers who model an equivalent shape of their own, or use `readonly`
  clone-with patterns against these objects, are working outside the supported
  surface and are not a compatibility constraint.

If source attribution is wanted, it still requires **a separate compatibility
decision** taken at the time: either a new value-object type returned by a new
method, or an additive field on these types. This ADR does not pre-approve
either, and source attribution remains outside v1.

### 1.5 Ordering

**Decided: ascending by `name`, compared as raw bytes in PHP (`strcmp`), never
by SQL `ORDER BY`.**

This is a portability decision, not a cosmetic one. `ORDER BY name` returns
different orders on MySQL's `utf8mb4_unicode_ci`, PostgreSQL's `C` or `en_US`
collations, Oracle's `NLS_SORT`, and SQL Server's configured collation. Sorting
in PHP is the only way the supported engines can produce one documented order,
and it mirrors constraint 4's prefilter-then-decide pattern.

Ties are impossible: `(name, guard_name)` is unique and a snapshot holds
exactly one guard.

### 1.6 Uniqueness

**Decided: unique by definition primary key.**

Section 5.5 of the specification requires that a permission reachable through
two roles and a direct grant appears once. Deduplication is keyed on the
definition's primary key rather than its name, because the key is the identity
the database enforces. Within a single guard the two are equivalent, and a test
asserts that.

### 1.7 Mutation safety of returned collections

**Decided: every public call returns a fresh `Collection`, and no returned
object aliases memoized state.**

- the value objects are `final readonly` — their fields cannot be reassigned;
- `effectivePermissions()` and `effectiveRoles()` build a new `Collection`
  instance per call from the snapshot's internal list;
- a consumer may therefore call `->push()`, `->forget()`, `->sort()` or
  `->filter()` freely, and a subsequent `allows()` on the same subject is
  unaffected.

This must be asserted by test, not merely documented.

### 1.8 Methods deliberately not added

`hasAny()`, `hasAll()`, `allowsAny()` and `allowsAll()` are **not** added to the
contracts in v1, even though ADR 0004 needs all four behaviours.

ADR 0004's real requirement is "batch checks must load the effective permission
or role set once where practical rather than issuing one complete resolver
query per parameter". Adding four more public methods would not change how that
requirement is met, so it is left to the Phase 6 adapter — under the binding
constraint in section 11.2.

## 2. Shared internal resolution model

### 2.1 One snapshot, two projections

The specification's effective-permission formula (section 5.1) is:

```text
Effective permissions
= active direct permission assignments
+ permissions from active role assignments
```

The second term cannot be evaluated without first knowing the subject's active
roles, filtered by role-definition lifecycle and guard. That intermediate value
*is* the effective role set of specification section 11.5. It is the same set,
produced by the same lifecycle filter, in the same guard, at the same instant.

Three topologies are possible.

| Topology | Effective roles computed | Risk |
|---|---|---|
| A. Two independent resolvers | Twice, by two code paths | Two lifecycle algorithms drift; `hasRole('editor')` can disagree with the roles that granted a permission |
| B. `RoleResolver` calls `PermissionResolver` | Once, but the dependency is backwards | Role checks pay for permission resolution they do not need |
| C. **Both delegate to one snapshot** | Once, by one code path | None identified |

**Topology C is decided.** It is also the only one that satisfies the
specification's own requirement in section 11.5 that any and all checks "must
not implement independent lifecycle or guard rules", and section 12.2's rule
that the subject concern "does not implement separate role, permission,
lifecycle, or guard algorithms".

### 2.2 What the snapshot holds

`EffectiveAccessSnapshot` is `@internal` and immutable. It carries:

- the canonical subject identity (section 3.1);
- the resolved guard;
- the evaluation instant;
- the effective roles, unique by id, ordered by name;
- the effective permissions, unique by id, ordered by name.

It carries **no** Eloquent model, no connection, no query builder, and no
query counter (section 8).

### 2.3 What parity does and does not mean

**Parity is algorithmic parity for one evaluation state, not permanent
equality across time.** The precise claim this ADR makes is:

> For one `EffectiveAccessSnapshot`, `allows()` and `has()` membership and the
> corresponding `effectivePermissions()` and `effectiveRoles()` collections are
> exact projections of the same state.

Both contracts also share one lifecycle filter, one guard filter and one
ordering rule, so no second algorithm exists that could drift from the first.

Two separate public calls may legitimately return different answers. That is
correct behaviour, not a parity violation. It happens when:

- the evaluation instant advances across a lifecycle boundary — an assignment
  expires, or a scheduled one starts;
- a mutation commits between the calls;
- the request memo is flushed, so the second call builds a fresh snapshot;
- persisted state changes from outside the process.

Section 17 therefore verifies parity under a fixed clock, unchanged persisted
state, and one memoized snapshot or evaluation boundary. Verifying it any other
way would be testing the clock, not the algorithm.

### 2.4 The `RoleResolver` deliverable mismatch

`PACKAGE_SPEC.md` defines two resolvers; `ROADMAP.md` Phase 5 names only
`PermissionResolver` in its eight deliverables, and `RoleResolver` appears in
no phase at all — yet ADR 0004's three role middleware aliases and
specification section 12.2's `hasRole()`/`hasAnyRole()`/`hasAllRoles()` are
Phase 6 work that cannot proceed without it.

**Decided: correct the Phase 5 deliverable list to name both contracts, and
count the shared snapshot once.** Applied to `ROADMAP.md` at acceptance.

```text
[ ] PermissionResolver contract and implementation.
[ ] RoleResolver contract and implementation.
[ ] Direct permission resolution.
[ ] Role-derived permission resolution.
[ ] Effective role collection.
[ ] Subject guard resolution.
[ ] Lifecycle filtering.
[ ] Effective permission collection.
[ ] Request-level memoization.
[ ] Query-count instrumentation.
```

Ten atomic items instead of eight. `EffectiveAccessSnapshot`,
`EffectiveAccessResolver` and `SubjectIdentityResolver` are **not** separate
items: `ROADMAP.md` section 3.2 already rules that "shared infrastructure is
included in the acceptance criteria of the mutation or capability that uses it
and is not counted again as separate progress".

Two alternatives were weighed and rejected.

**Defer `RoleResolver` to Phase 6.** Rejected. Phase 6's objective is to
"expose the resolver through Laravel conventions without duplicating its
logic". Introducing a *new* resolution contract there contradicts that
objective, and it would place a core authorization contract outside the phase
whose exit criterion is "the complete resolver test matrix passes without
Laravel Gate, middleware, or cross-request caching".

**Drop `RoleResolver` and let role checks read `effectivePermissions()`.**
Rejected outright. Roles are not permissions. `hasRole('auditor')` must be
answerable for a role that grants no permissions at all, and specification
section 5.8 states that role names "are not authorization bypasses by
themselves". Collapsing the two would make an unpermissioned role invisible.

**This correction changes no `ROADMAP.md` percentage, deliverable count or
phase status.** It changes the Phase 5 denominator from eight to ten while the
numerator stays at zero, so Phase 5 remains 0% and Not started. Accepting an
architecture is not implementation evidence. See section 19.4.

## 3. Subject and guard resolution

### 3.1 One shared subject identity component

**Decided: Phase 5 begins with one internal `SubjectIdentityResolver`, and
every path that derives a subject identity uses it.**

```text
SubjectIdentityResolver  (@internal)
    ├─▶ EffectiveAccessResolver
    ├─▶ assignRole()
    ├─▶ grantDirectPermission()
    └─▶ every future managed subject-assignment path
```

This is not a tidiness preference. Constraint 6 records that two private copies
already exist with **different** validation, so GateTrail can already accept a
subject for one mutation that another would reject. Adding a third copy inside
the resolver would make a read and a write disagree about who the subject is —
the one disagreement an authorization package cannot afford.

Required behaviour, identical on every path:

| Rule | On failure |
|---|---|
| Subject is a persisted Eloquent `Model` (`exists === true`) | `InvalidSubject` |
| `getMorphClass()` returns a valid non-empty string | `InvalidSubject` |
| `getKey()` returns a supported `int\|string` identifier | `InvalidSubject` |
| The primary key is not dirty | `InvalidSubject` |
| The identifier canonicalizes to an unsigned decimal integer (section 3.1.1) | `InvalidSubject` when it cannot |

The result is an immutable internal value carrying **only** the canonical
subject type and the canonical identifier — no model, no attributes, no
connection:

```php
/** @internal */
final readonly class SubjectIdentity
{
    public string $type;       // from getMorphClass()
    public int $id;            // ONE canonical unsigned decimal value
}
```

**Public mutation signatures do not change.** `assignRole(Model $subject, …)`
and every other manager method keep their current parameters. Only the private
identity derivation inside the handlers is replaced.

#### 3.1.1 Subject identifiers are unsigned decimal in the initial runtime

This records what the runtime supports today. It does not widen it.

A subject key may **arrive** as an `int` or a `string`, but in the initial
runtime it must **represent an unsigned decimal integer**:

| Input | Result |
|---|---|
| `42` | accepted |
| `'42'` | accepted |
| `'007'` | accepted, canonicalized to `7` |
| `'-1'`, `'+1'` | `InvalidSubject` |
| `' 1'`, `'1 '` | `InvalidSubject` |
| `'1.0'`, `'1e3'`, `'0x1f'` | `InvalidSubject` |
| A UUID or ULID | `InvalidSubject` |
| `''` | `InvalidSubject` |
| Any other non-decimal value | `InvalidSubject` |

`SubjectIdentity` always represents **one canonical unsigned decimal value**.
`'007'`, `'7'` and `7` are the same subject and must produce the same identity
on every path.

**Implemented as a native `int`** (Increment 1). A `int|string` field would let
`7` and `'7'` produce identities that differ only by PHP scalar type — equal
under `==`, distinct under `===` — which is exactly the ambiguity this rule
exists to remove. A native integer makes the canonical form unrepresentable in
two ways, and the `subject_id` column is an unsigned integer on every supported
engine, so no binding fidelity is lost.

The cost is an explicit upper bound: identifiers above `PHP_INT_MAX` are
**rejected**, never saturated. That is narrower than an unsigned 64-bit column
can hold, and it is the documented boundary of the initial runtime rather than
an oversight. Widening it is part of the same future coordinated design as
UUID/ULID support below.

**The resolver and the mutation paths use this same rule**, because they are
the same component (section 3.1).

The constraint is not invented here. `AssignmentScope::forRole()` and
`forPermission()` already require `^[0-9]+$` for `subject_id` when deriving the
coordination key, and `PACKAGE_SPEC.md` section 7.2 states that "the initial
default migrations support numeric subject morph identifiers". A subject whose
key is a UUID cannot currently be assigned a role at all; making the resolver
accept one would let a check be asked about a subject no mutation could
create.

**Publishing modified migrations does not by itself provide UUID or ULID
runtime support.** Changing a column type leaves `AssignmentScope`'s
coordination-key rule, the audit identifier handling and the resolver's
bindings unchanged, so the package would fail at the first coordinated
mutation.

First-class UUID/ULID subject support requires a **future coordinated design**
spanning: schema and migration variants; `AssignmentScope` canonical keys and
hashing; the coordination-row contract of ADR 0001; audit snapshot identifier
serialization; resolver query bindings across all supported engines; and the
portability test matrix. `PACKAGE_SPEC.md` section 24 already lists it as an
open decision for before `1.0.0`. **ADR 0012 does not broaden it.**

### 3.2 Persisted identity, not in-memory attributes

Only the morph class and the primary key are read. Every other attribute is
ignored, so editing `$user->email` or `$user->name` in memory cannot change an
authorization result.

The primary key gets a stronger rule. If the key is **dirty** — the caller
assigned a new value without saving — GateTrail raises `InvalidSubject` rather
than choosing between the in-memory value and the persisted one. Silently
preferring either could authorize or grant to the wrong subject, and there is
no reading of `$user->id = 999` that GateTrail can honour safely.

Canonical normalization matters because the identifier is both **bound into a
query** by the resolver and **persisted** by the mutation handlers. If the two
canonicalize differently, a subject can be granted access it cannot then be
shown to have. One component decides it once, under the rule in section 3.1.1.

### 3.3 Morph type consistency

`getMorphClass()` is the same call the mutation handlers use, so a subject
resolves to the identical `subject_type` value that its assignments were
written with — including under a Laravel morph map, as specification section
7.3 encourages.

One operational hazard follows and is accepted rather than solved here: if an
application introduces or changes a morph map **after** assignments exist, the
persisted `subject_type` values no longer match `getMorphClass()`, and the
resolver silently finds nothing. This is a data-migration concern, it fails
closed (deny, never allow), and `gatetrail:doctor` is the right place to report
it in Phase 10. Phase 5 must not attempt to repair or guess.

### 3.4 The authenticated user is never substituted

The resolver reads no global state. It does not call `Auth::user()`, does not
read the request, and has no code path that can replace the supplied subject.
Specification section 5.6's requirement is satisfied structurally: the subject
arrives as a parameter and nothing else is consulted.

### 3.5 `InvalidSubject`

**Decided: Phase 5 introduces it.** It is already listed in `PACKAGE_SPEC.md`
section 15 and in ADR 0003's exception list, but no class exists in `src/`
today.

```php
namespace HangTuah\GateTrail\Exceptions;

use InvalidArgumentException;

final class InvalidSubject extends InvalidArgumentException
{
    private function __construct(
        public readonly string $subjectType,
        public readonly string $reason,
    ) {}

    public static function notPersisted(string $subjectType): self;
    public static function missingIdentifier(string $subjectType): self;
    public static function unsupportedIdentifier(string $subjectType): self;
    public static function mutatedIdentifier(string $subjectType): self;
    public static function invalidMorphType(): self;
}
```

`unsupportedIdentifier()` covers both an unsupported PHP type and a value that
is not an unsigned decimal integer under section 3.1.1 — including a UUID or
ULID key. It carries the reason, never the offending value.

It mirrors `InvalidGuard::forSubject()` exactly: a safe type label plus a fixed
vocabulary term.

Safe fields:

- `subjectType` — the morph class or morph alias. This value is already
  persisted in `subject_type` on every assignment row and already appears in
  `InvalidGuard`, so it discloses nothing new;
- `reason` — one of a fixed internal vocabulary
  (`not_persisted`, `missing_identifier`, `unsupported_identifier`,
  `mutated_identifier`, `invalid_morph_type`).

Never carried, in the message or in any public property:

- the raw subject identifier, persisted or in-memory;
- any other model attribute;
- the model instance, or any `Throwable` derived near it;
- SQL, bindings or connection details.

Excluding the raw identifier is deliberate. A subject key can be a customer
number, a national identifier or another externally meaningful value in a
consuming application, and an exception message is the single most likely thing
to reach a log aggregator.

`getPrevious()` is always `null`, consistent with the posture ADR 0011
established for `ConcurrencyRetryExhausted`.

### 3.6 Guard resolution

ADR 0003 is authoritative and unchanged. Applied to the resolver:

- exactly **one** guard is resolved per evaluation, through
  `SubjectGuardResolver`, using ADR 0003's five-source precedence;
- the resolved guard is part of the snapshot identity and part of the memo key,
  as ADR 0003 already requires of every memoization key;
- **name lookups occur only inside the subject guard.** A name absent from that
  guard denies; no other guard is searched;
- an explicit model, identifier or reference resolving to another guard returns
  `false` for authorization, with **no disclosure** of the other definition's
  existence, name or guard;
- an invalid authoritative guard configuration raises `InvalidGuard`. It is
  **not** converted into a denial;
- mutation `GuardMismatch` behaviour is unchanged — see section 10;
- there is no per-call guard override, per ADR 0003.

## 4. Input semantics

### 4.1 The governing rule

**The membership comparison is in memory. Obtaining the snapshot it compares
against may cost queries.**

These are two different steps, and an earlier draft wrongly collapsed them into
the claim that a check performs no SQL. A cold `allows()` or `has()` call
builds the snapshot and therefore executes the bounded snapshot queries of
section 6. What it never does is issue a *separate definition-lookup query* for
the requested name or identifier.

Accepted flow for every public check:

```text
validate subject                                  → InvalidSubject on failure
resolve subject guard                             → InvalidGuard on failure
normalize the requested role or permission input
   ├─ impossible input  → return false, no snapshot built, zero queries
   └─ otherwise         → continue
obtain or build the snapshot
   ├─ warm memo hit     → zero queries
   └─ cold              → the bounded snapshot queries of section 6
exact in-memory membership comparison             → bool
```

The precise claims this ADR makes, and the only ones it makes:

- **No separate definition lookup, ever.** Neither `allows()` nor `has()`
  queries `roles` or `permissions` to resolve the requested input. The snapshot
  already carries every effective definition's identifier, name and guard, so
  the comparison is a lookup in memory.
- **A warm check performs zero queries.** Within one memo entry, any number of
  checks costs nothing.
- **A cold valid check costs the snapshot's query budget** — section 6.3 — and
  nothing beyond it.
- **An impossible input denies with zero queries**, but only because
  normalization rejects it *before* snapshot construction is reached. This is
  ordering, not an absence of SQL from the resolver.

Two properties still follow structurally.

- **Non-disclosure.** Missing, deactivated and cross-guard definitions are all
  simply absent from the snapshot, and absence is one answer: `false`. A caller
  cannot distinguish the three cases, which is what ADR 0003 and ADR 0004
  require.
- **No malformed identifier reaches a driver binding.** A rejected identifier
  short-circuits before the snapshot is built, so it is never bound into any
  statement. Valid cold checks do execute the snapshot queries, but those bind
  only the subject identity, the guard and the evaluation instant — never the
  caller's requested definition input, which is compared in PHP. The
  PostgreSQL, Oracle and SQL Server binding requirement is satisfied on both
  paths.

### 4.2 Input matrix

`X` stands for permission or role; behaviour is identical for both resolvers.

| Input | Interpretation | Result |
|---|---|---|
| Non-empty `string` | **Always a name.** Never an identifier, whatever it looks like. | `true` when a snapshot entry's `name` matches byte-for-byte |
| `string` of only whitespace, or `''` | Invalid name | `false` — no definition can carry it (names are trimmed and non-empty at creation) |
| Numeric-looking `string`, e.g. `'42'` or `'007'` | **A name**, not an identifier | `true` only when a definition is literally named `42` or `007` |
| `int` | **Always an identifier** | `true` when a snapshot entry's `id` matches |
| `XReference::id(int)` | An identifier | Same as `int` |
| `XReference::id(string)` | An identifier, normalized per section 4.3 | `'007'` becomes `7` against an integer-keyed model; preserved verbatim against a string-keyed model |
| **Configured** definition model instance | An identifier, taken from `getKey()` | Same as `int`, after normalization |
| Configured model with `exists === false` | Cannot correspond to any row | `false` |
| Configured model whose `getKey()` is not `int\|string` | Cannot correspond to any row | `false` |
| **Non-configured** `XContract` implementation | Not a definition GateTrail owns | `false` — see section 4.4 |
| Identifier the normalizer rejects | No row can carry it | `false`, before any snapshot is built |
| Missing definition | Absent from snapshot | `false` |
| Deactivated definition | Excluded when the snapshot was built | `false` |
| Definition in another guard | Excluded when the snapshot was built | `false` |

The string-is-always-a-name rule is not new. `RoleDefinitionInputResolver`
already documents it: "Only a string input is a name, and only that path
consults the default guard." Phase 5 keeps it so that one mental model covers
mutation and authorization.

The consequence worth stating explicitly, because it is the one thing a
consumer can get wrong:

```php
$permissions->allows($subject, '007');
// → is a permission NAMED "007" effective?

$permissions->allows($subject, PermissionReference::id('007'));
// → is the permission with IDENTIFIER 7 effective?
```

A bare string is never an identifier. Wrapping it in a reference is how a
caller says "this is an identifier", and only then is it canonicalized.

### 4.3 Identifier normalization

Identifier inputs pass through the existing
`DefinitionIdentifierNormalizer::normalize()` before comparison. **This ADR
does not change that behaviour**; it records it, because an earlier draft
described it incorrectly.

Against an **integer-keyed** definition model:

| Input | Result | Note |
|---|---|---|
| `7` | `7` | Native integers pass through |
| `'7'` | `7` | Canonical decimal string |
| `'007'` | `7` | **Leading zeros are canonicalized, not rejected** |
| `'0'`, `'000'` | `0` | |
| `'9223372036854775807'` | `9223372036854775807` | `PHP_INT_MAX` is accepted |
| `'99999999999999999999'` | rejected | Overflow above `PHP_INT_MAX` |
| `'-1'`, `'+1'` | rejected | Signs |
| `' 1'`, `'1 '` | rejected | Whitespace |
| `'1.0'` | rejected | Decimal notation |
| `'1e3'` | rejected | Exponent notation |
| `'0x1f'` | rejected | Hexadecimal notation |
| `'abc'`, `''` | rejected | Not digits |

Against a **string-keyed** definition model the identifier is preserved
unchanged, with no canonicalization at all — `'007'` stays `'007'`.

Overflow rejection is not incidental. `(int) '99999999999999999999'` saturates
at `PHP_INT_MAX` in PHP, so an unguarded cast would silently compare against
the wrong identifier. The normalizer catches it by round-tripping the cast back
to a string and comparing it with the canonical digits.

A rejected identifier is a **deny**, not an exception, and it short-circuits
before snapshot construction, so it costs zero queries.

Reusing the normalizer rather than comparing loosely is what keeps
`PermissionReference::id('007')` and `PermissionReference::id(7)` the same
question in the resolver as they already are in the mutation path.

### 4.4 Non-configured definition model instances

`PermissionContract` and `RoleContract` are empty marker interfaces
(constraint 2), so any class in a consuming application can implement one. An
input that merely implements the marker is **not** evidence that GateTrail owns
the row it describes.

**Accepted rule: a definition-model input is honoured only when it is an
instance of the class `ModelRegistry` resolves, or a subclass of it. Anything
else denies.**

```text
$input instanceof $models->permission()   → honoured, by identifier only
otherwise                                 → false
```

An earlier draft allowed any `PermissionContract` to be compared by identifier.
That was wrong: an unrelated application model whose primary key happens to
collide with an effective permission's identifier would have authorized the
caller. Identifier spaces of unrelated tables collide constantly — key `1`
exists nearly everywhere — so this was a real privilege-escalation path, not a
theoretical one.

Rules:

- the class check uses `ModelRegistry`'s resolved class, so a consumer's
  configured replacement model is accepted, and so is a subclass of it;
- a valid configured model is interpreted **only** through its persisted
  identifier. Its `name`, `guard_name` and `deleted_at` attributes are never
  read, so a detached or hand-edited instance cannot steer the decision — the
  same posture ADR 0011 established for revocation scope;
- an unsaved configured model (`exists === false`) denies;
- a configured model whose `getKey()` is `null` or not `int|string` denies;
- a configured model belonging to another guard denies, with no disclosure,
  because its identifier is simply absent from the snapshot;
- **an unrelated model never throws.** `InvalidModelConfiguration` reports a
  broken *package configuration*, not a caller passing the wrong object.
  Throwing here would also let a caller probe which classes are configured,
  which section 4.1's non-disclosure rule forbids.

`InvalidModelConfiguration` continues to be thrown by `ModelRegistry` itself
when `gatetrail.models.*` is genuinely misconfigured — a missing class, a
non-Eloquent class, a class missing its contract, or a replacement model
missing `SoftDeletes`. That path is unchanged.

### 4.5 Name comparison is exact, in PHP

Because names are compared in PHP against the snapshot, a case-insensitive
database collation cannot make `Documents.Approve` satisfy
`documents.approve`. Specification section 5.7's "compared exactly" therefore
holds on MySQL with `utf8mb4_unicode_ci` exactly as it does on PostgreSQL.

This is a second reason no check may be reimplemented as a separate SQL
`WHERE name = ?` definition lookup.

## 5. Lifecycle algorithm

### 5.1 One instant per call

Every public resolution call reads `Psr\Clock\ClockInterface::now()` **exactly
once** and uses that `DateTimeImmutable` for the whole evaluation: every
snapshot query, every lifecycle filter, and the snapshot's identity.

The bound clock is `HangTuah\GateTrail\Time\SystemClock`, already registered as
a singleton and already normalizing to UTC whole seconds.

Reading the clock per query would let a role assignment expire between the
first query and the last, producing a snapshot in which a role is effective but
its permissions are not — an inconsistency no consumer could reproduce or
debug. One instant makes each snapshot a coherent view of a single moment.

Section 9 states precisely what that consistency does and does not guarantee
against concurrent writers.

### 5.2 The half-open rule

Unchanged from specification section 9.3 and ADR 0001:

```text
revoked_at IS NULL
AND COALESCE(starts_at, assigned_at) <= evaluatedAt
AND (expires_at IS NULL OR expires_at > evaluatedAt)
```

Expiry is exclusive: an assignment with `expires_at == evaluatedAt` is not
effective.

**No new predicate is written.** `HasAssignmentPeriodScopes::scopeActiveAt()`
already encodes this, is already covered by boundary tests, and is applied by
all three snapshot queries.

Specification section 11.4 excludes historical authorization queries from the
initial public API, and Phase 14 lists them as optional. No public method
accepts a timestamp. The clock is injectable so tests are deterministic — that
is its only purpose here.

### 5.3 Definition lifecycle must be filtered explicitly

**This is the correction that matters most in this ADR, because getting it
wrong fails open.**

An earlier draft claimed that the `SoftDeletes` global scope on `Role` and
`Permission` would exclude deactivated definitions from the snapshot queries.
That is wrong, for two independent reasons, and either one alone would have let
a deactivated role keep granting permissions.

**Reason 1 — a global scope applies to the root model only.** Laravel adds
`where "deleted_at" is null` through the query builder of the model the query
is *built from*. The root model of all three snapshot queries is an assignment
model — `RoleAssignment`, `PermissionAssignment`, `RolePermission` — and none
of those three uses `SoftDeletes`. Joining `roles` or `permissions` into that
query brings in the table, not the joined model's global scopes. No
`deleted_at` predicate is generated for the joined table at all.

**Reason 2 — this package's definition relations are deliberately
`withTrashed()`** (constraint 3). Escaping to a relation instead of a join does
not help. `whereHas('role', …)`, `with('role')` and `has('permission')` would
all **include** deactivated definitions, so a resolver built on those relations
would fail open just as a naive join would.

Those relations are correct and must not be changed: specification section 9.1
requires that "deactivation does not revoke or rewrite existing assignments",
and audit snapshot rebuilding depends on an assignment still being able to
reach a soft-deleted definition.

**Accepted rule.** Snapshot construction filters definition lifecycle and guard
with its own predicates, on every query:

```text
roles.deleted_at IS NULL
permissions.deleted_at IS NULL
roles.guard_name = ?
permissions.guard_name = ?
```

### 5.4 Layered filtering summary

| Layer | Excludes | Mechanism |
|---|---|---|
| `scopeActiveAt()` on the assignment root model | Revoked, not yet started, expired | Existing trait scope, applies to the root model |
| Explicit `deleted_at IS NULL` on the joined definition | Deactivated definitions | **Written by the resolver** |
| Explicit `guard_name = ?` on the joined definition | Cross-guard definitions | **Written by the resolver** |

Only the first layer is inherited. The other two are the resolver's own
responsibility on every query, and section 17 requires a captured-SQL assertion
proving each predicate is present.

Specification section 9.1's "deactivated roles do not grant permissions" now
depends on an explicit predicate rather than on framework behaviour that does
not apply.

## 6. Query architecture

### 6.1 Snapshot construction

Every predicate below is written explicitly. None is inherited from an Eloquent
global scope or from a relation definition, for the reasons in section 5.3.

```text
1. effective roles
   root model: role_assignments
     .activeAt(evaluatedAt)                        -- revoked / start / expiry
     .where(role_assignments.subject_type, subjectType)
     .where(role_assignments.subject_id,   subjectId)
     join roles on roles.id = role_assignments.role_id
     .whereNull(roles.deleted_at)                  -- EXPLICIT: not deactivated
     .where(roles.guard_name, guard)               -- EXPLICIT: in guard
     select roles.id, roles.name, roles.guard_name
   → EffectiveRole[]

2. direct permissions
   root model: permission_assignments
     .activeAt(evaluatedAt)
     .where(permission_assignments.subject_type, subjectType)
     .where(permission_assignments.subject_id,   subjectId)
     join permissions on permissions.id = permission_assignments.permission_id
     .whereNull(permissions.deleted_at)            -- EXPLICIT
     .where(permissions.guard_name, guard)         -- EXPLICIT
     select permissions.id, permissions.name, permissions.guard_name
   → EffectivePermission[]

3. role-derived permissions
   -- The step-1 ids are CANDIDATES ONLY. This query revalidates the whole
   -- path; it does not trust the IN list. See section 6.1.1.
   root model: RolePermission
     .activeAt(evaluatedAt)                        -- root scope: grant active
     .whereIn(role_permissions.role_id, candidate ids from step 1)
     join roles on roles.id = role_permissions.role_id
     .whereNull(roles.deleted_at)                  -- REVALIDATE not deactivated
     .where(roles.guard_name, guard)               -- REVALIDATE in guard
     join permissions on permissions.id = role_permissions.permission_id
     .whereNull(permissions.deleted_at)            -- EXPLICIT
     .where(permissions.guard_name, guard)         -- EXPLICIT
     whereExists(
        root model: RoleAssignment                 -- REVALIDATE the assignment
          .activeAt(evaluatedAt)                   -- subquery's own root scope
          .where(role_assignments.subject_type, subjectType)
          .where(role_assignments.subject_id,   subjectId)
          .whereColumn(
              role_assignments.role_id,            -- correlation, both sides
              role_permissions.role_id,            -- explicitly qualified
          )
     )
     select permissions.id, permissions.name, permissions.guard_name
   → EffectivePermission[]

effective permissions = unique-by-id(2 + 3), sorted by name
```

Step 3 is skipped entirely when step 1 returns no roles.

This is the N+1 answer required by Phase 5's rules: the role count drives the
size of an `IN` list and, past the chunk bound, the number of step-3 chunks —
never one query per role. Revalidation adds joins, not statements.

### 6.1.1 Why step 3 revalidates instead of trusting the `IN` list

An earlier draft claimed that "step 1's role list is already lifecycle- and
guard-filtered, so step 3 inherits that filtering through its `IN` list". **That
claim is withdrawn: it over-grants.**

Step 1 and step 3 are separate statements. Between them, another transaction
can commit:

```text
t0  query 1 observes role "reviewer" as effective
t1  another transaction revokes the subject's reviewer assignment, or
    deactivates the reviewer role, and commits
t2  query 3 runs with "reviewer" still in the IN list
    → without revalidation it returns reviewer's permissions
```

Inheriting validity from the `IN` list therefore grants permissions through a
role that was already revoked or deactivated when the permissions were read.
That is an over-grant, and no amount of care in step 1 can prevent it, because
step 1 finished before the revocation committed.

The corrected query revalidates the **complete path** — the role-permission
grant, the subject's role assignment, the role definition and the permission
definition — inside one statement. The engine evaluates a single statement
against one consistent read, so the revalidated path cannot be internally
stale.

The `IN` list keeps two jobs and loses one:

- **kept:** it narrows the scan to roles this subject plausibly holds, and it
  is the unit of chunking (section 6.3);
- **kept:** it lets step 3 be skipped entirely when step 1 found no roles;
- **lost:** it is no longer evidence that a role is still valid. Validity comes
  only from the revalidation.

Resulting semantics:

| Event | Outcome |
|---|---|
| Role assignment revoked, or role deactivated, **before** query 3 | **Excluded.** Revalidation catches it. |
| Role granted **after** query 1 | Absent from the candidate list, so absent from this snapshot. **Fails closed** — an under-grant, corrected at the next evaluation. |
| Revocation committed **after** query 3 | Still inside the unavoidable non-linearizable window of section 9.2. Not solvable without locking every authorization read. |

#### Why the assignment is revalidated by `EXISTS`, not by a join

A draft of this correction wrote `.activeAt(evaluatedAt)` against a joined
`role_assignments` table. **That is not implementable against the existing
code, and it would have been ambiguous SQL.**

`HasAssignmentPeriodScopes::scopeActiveAt()` operates on the **root** Eloquent
model and emits **unqualified** column names — `revoked_at`, `starts_at`,
`assigned_at`, `expires_at`. It cannot be aimed at an arbitrary joined table,
and Eloquent offers no supported way to do so.

Which tables carry those four columns decides the whole shape:

| Table | Carries the four lifecycle columns |
|---|---|
| `roles` | no |
| `permissions` | no |
| `role_permissions` | **yes** |
| `role_assignments` | **yes** |

So:

- joining `roles` and `permissions` into the root query is safe. Neither has a
  lifecycle column, so `RolePermission::activeAt()`'s unqualified names can
  only bind to `role_permissions`;
- joining `role_assignments` into that same scope would put **two** tables with
  all four columns in one `FROM`, making every unqualified lifecycle predicate
  ambiguous — and silently wrong on whichever engines resolve rather than
  error.

Moving the assignment check into a correlated `EXISTS` keeps each scope with at
most one lifecycle-bearing table:

- the **outer** query has `role_permissions` as its only lifecycle table, so
  `RolePermission::activeAt()` applies to the root exactly as designed;
- the **subquery** has `role_assignments` as its own root, so
  `RoleAssignment::activeAt()` applies there exactly as designed, and its
  unqualified names bind to the subquery's own table by standard innermost-scope
  resolution;
- the **correlation** columns are explicitly qualified on both sides, so the
  one predicate spanning both scopes is never left to scope resolution.

The half-open algorithm is therefore reused twice, verbatim, and **not
duplicated**. Section 17 pins the binding behaviourally: a case where the
role-permission grant is revoked but the role assignment is not, and its
mirror image, produce different answers, which a mis-bound predicate could not
reproduce.

Conceptual result:

```sql
SELECT   <qualified permission id, name, guard_name>
FROM     role_permissions
JOIN     roles       ON ...
JOIN     permissions ON ...
WHERE    <role_permissions half-open lifecycle at evaluatedAt>
  AND    role_permissions.role_id IN (<candidate ids>)
  AND    roles.deleted_at IS NULL  AND roles.guard_name = ?
  AND    permissions.deleted_at IS NULL AND permissions.guard_name = ?
  AND    EXISTS (
             SELECT 1
             FROM   role_assignments
             WHERE  <role_assignments half-open lifecycle at evaluatedAt>
               AND  role_assignments.subject_type = ?
               AND  role_assignments.subject_id   = ?
               AND  role_assignments.role_id = role_permissions.role_id
         )
```

The subquery is built from the `ModelRegistry`-resolved `RoleAssignment` class
and passed to `whereExists()` as a builder, so no raw SQL is written and the
projection inside `EXISTS` is left to the grammar.

The query can in principle return the same permission more than once — two
role-permission rows can grant it. `EXISTS` deliberately avoids the fan-out a
join to `role_assignments` would add, but the resolver does not rely on that
either: **permission results are deduplicated in PHP by permission primary
key**, per section 1.6.

### 6.2 Names come from model accessors, never literals

Column and table names are resolved from the model instances that
`ModelRegistry` returns, so a consumer's replacement model, a configured table
prefix and a configured schema qualifier all apply:

| Value | Source |
|---|---|
| Qualified definition table | `$definition->getTable()`, which `GateTrailModel` routes through `SchemaContext::table()` |
| Qualified soft-delete column | `$definition->getQualifiedDeletedAtColumn()` |
| Qualified primary key | `$definition->getQualifiedKeyName()` |
| Guard column | Qualified against the same resolved table name |

Using `getQualifiedDeletedAtColumn()` rather than the literal `deleted_at`
matters because a replacement model may redefine `DELETED_AT`, and
`ModelRegistry` permits replacement models by design.

### 6.3 Query budget

The budget is a model rather than a single number, because the large-role path
genuinely differs.

| Path | Queries |
|---|---|
| **Cold, effective roles exist** | 3 — one effective-role, one direct-permission, one role-permission |
| **Cold, no effective roles** | 2 — step 3 is skipped |
| **Cold, role count above the chunk bound** | 1 effective-role + 1 direct-permission + **one role-permission query per chunk** |
| **Warm memo hit** | 0 additional queries |

The invariant that matters is not a constant: it is that **the number of roles
never produces one query per role.** A subject with 50 roles costs the same
three queries as a subject with two.

#### Chunk size: 900

**Decided: the role-id `IN` list is chunked at 900 identifiers.**

| Constraint | Bound | Headroom at 900 |
|---|---|---|
| Oracle expression-list ceiling (`ORA-01795`) | 1000 expressions | 100 |
| SQL Server parameter ceiling per statement | 2100 parameters | ~1190 for the subject, guard, instant and framework bindings |
| MySQL, PostgreSQL, SQLite | No comparable practical ceiling at this size | — |

900 is chosen because it clears Oracle's hard limit with room to spare, leaves
generous headroom under SQL Server's overall parameter ceiling once the
subject, guard and timestamp bindings are counted, and is one fixed number on
every engine. **No driver-specific branching**: a per-engine chunk size would
mean the resolver produced a different number of statements on Oracle than on
MySQL, which would make the query-budget tests engine-dependent and the
behaviour harder to reason about.

The bound is an internal constant, not configuration, consistent with section
7.4's posture on the memo bound.

This is exactly the kind of engine limit ADR 0001 was written to keep out of
the correctness argument, and it is recorded here so it is not discovered
during Oracle CI.

### 6.4 Portability constraints on the snapshot queries

- bind `DateTimeImmutable` values and let the connection's grammar format them,
  as `AssignmentConflictQuery` and `scopeActiveAt()` already do;
- no `EXTRACT`, `DATE_ADD`, `NVL`, `IIF`, `LIMIT`/`OFFSET` pagination, window
  function, lateral join or CTE;
- no `ORDER BY` on a user-supplied text column — ordering happens in PHP;
- no `DISTINCT` on a text column — deduplication happens in PHP by primary key;
- resolve every table name through `SchemaContext` and every model class
  through `ModelRegistry`, per section 6.2;
- **qualify every column in a joined query.** Step 3's outer query puts three
  tables in scope, so a bare `guard_name`, `deleted_at`, `name`, `id` or
  `role_id` is ambiguous, and the supported engines disagree about which of
  them raise an error and which pick a side. Qualification also keeps the
  selected definition columns distinct from the grant columns of the same name;
- **never rely on an inherited scope across a join**, per section 5.3;
- **keep at most one lifecycle-bearing table per query scope**, per section
  6.1.1. `scopeActiveAt()` emits unqualified lifecycle columns, so two
  assignment-shaped tables must never share a `FROM` scope. Use a correlated
  `EXISTS` whose root is the second model;
- **build subqueries from `ModelRegistry`-resolved models** and pass the
  builder to `whereExists()`. Never assemble a subquery from a literal table
  name, and never write raw SQL for it — the projection inside `EXISTS` is left
  to the grammar;
- **qualify both sides of a correlation** with `whereColumn()`, so the one
  predicate that spans two scopes never depends on scope resolution;
- **the emitted shape must compile under every supported grammar** — SQLite,
  MySQL, PostgreSQL, Oracle and SQL Server. Correlated `EXISTS`, `whereColumn`
  correlation and an `IN` list of bound parameters are all plain SQL-92 and
  require no driver-specific branching. Section 17 requires this to be asserted
  by compiling the query under each grammar, not assumed;
- **no locking of any kind**, per section 9.

## 7. Request-level memoization

### 7.1 Scope

Phase 5 implements **request-level memoization only**.

It does not touch a Laravel cache store, does not read `gatetrail.cache`, does
not introduce a TTL, a tag, a fan-out or any shared-cache behaviour, and does
not implement the after-commit cache-invalidation slot reserved by ADR 0011
section 2. Cross-request caching remains disabled and undesigned until its own
ADR is accepted, exactly as specification section 14.6 requires.

### 7.2 Memo key

```text
subject morph type + canonical subject id + guard + evaluatedAt
```

Including `evaluatedAt` — a UTC whole second — is what makes memoization
**correct rather than merely fast**. Within one second, a burst of checks
shares one snapshot. When the second changes, a fresh snapshot is built, so a
memo can never outlive an expiry boundary.

The guard is part of the key because ADR 0003 already requires it of every
memoization and cache key.

An alternative was considered and rejected for v1: memoize per
`(subject, guard)` and attach a validity horizon computed from the minimum
`expires_at` in the effective set. It is strictly cheaper, but it cannot see an
assignment that *starts* during the request without a fourth query for
scheduled rows. Whole-second keying is simpler, needs no extra query, and has
no correctness gap. Horizon-based reuse should be reconsidered from Phase 8
benchmark evidence rather than assumed necessary.

### 7.3 Wholesale flush on `MutationCommitted`, within one process

**Decided: a `MutationCommitted` event observed in the current process and
scope clears that scope's entire request memo.**

*Wholesale* describes the breadth of the flush **within one scope** — every
entry, not a targeted subset. It does **not** mean global.

- the request memo is **scoped and bounded**, so clearing it is cheap;
- **correctness is preferred over targeted invalidation**;
- **definition mutations may affect an unknown number of subjects.** A
  `role.deactivated` event carries no subject at all yet suspends every
  effective path that depends on that role, so no targeted rule could be
  correct for it;
- **no managed mutation is allowed a documented sub-second stale fallback.** An
  earlier draft floated tolerating staleness until the evaluation second
  ticked. That is rejected: once the current scope observes its own
  `MutationCommitted`, a subsequent check in that scope must see the new state;
- **this is request-local memo coherence, not cross-request cache
  invalidation.** No Laravel cache store, TTL, tag, fan-out or shared-cache
  behaviour is introduced, and the reserved after-commit cache-invalidation
  point stays empty;
- cross-request cache invalidation remains reserved for a future cache ADR.

`MutationCommitted` carries a nullable subject pair, so even for assignment
mutations a targeted rule would cover only part of the fourteen. Flushing an
in-memory map of at most a few entries is provably correct and costs nothing
measurable.

#### 7.3.1 Same-process guarantee, cross-process boundary

`MutationCommitted` is an **in-process Laravel event**. It is not broadcast, not
queued to other workers, and not distributed invalidation. Being precise about
this matters, because "flush on `MutationCommitted`" reads like a global
guarantee and is not one.

| Situation | Behaviour |
|---|---|
| Managed mutation in **this** process and scope, committed | Flushes this scope's memo. **Guaranteed.** |
| Caller-owned transaction | Flushes only after a successful **outermost** commit, per ADR 0010 |
| Rollback | No event is emitted, and committed memo state is not flushed — correctly, since no committed state changed |
| Mutation in **another** process or request | Does **not** clear this process's memo |
| Snapshot already built in another request | May remain until its whole-second memo key expires or its scope ends |

The cross-process row is part of the documented **non-linearizable
concurrent-read boundary** of section 9.2, not a separate defect. Two web
workers handling two requests are two processes; a revocation committed by one
does not reach into the other's in-memory map.

The bound is tight and stated: a foreign process's memo entry cannot outlive
the shorter of its whole-second key and its scope. There is no configurable TTL
because there is no cache — the entry is a PHP array value that dies with the
request.

**Phase 5 introduces no broadcasting, pub/sub, shared cache tags or distributed
invalidation.** Cross-process invalidation belongs to the future cross-request
cache ADR, which is also where a bounded-staleness contract would have to be
written.

The guarantee Phase 5 does make is therefore narrow and strict:

> Within one process and scope, a mutate-then-check sequence observes the
> committed mutation, with no stale fallback of any duration.

### 7.4 Container-lifecycle constraint

**A singleton event listener must never capture one scoped resolver or memo
store.**

This is a concrete hazard, not a style rule. The resolver is a **scoped**
binding — a fresh instance per request and per queued job. A listener
registered once at boot and constructor-injected with the resolver would
capture the *first* scope's memo store and hold it for the process lifetime.
Under Octane or a queue worker that means:

- later requests flush a memo store nobody is reading any more;
- the captured store is never flushed and never evicted, so it both leaks and
  serves state across scope boundaries;
- authorization answers from a previous request could survive into the next.

Required properties for the future listener:

- it **resolves the current scoped memo store lazily**, at dispatch time, from
  the container — or uses another lifecycle-safe mechanism such as a
  process-local registry keyed by the active scope;
- **long-running Octane and queue workers must not retain request or job memo
  entries across scope boundaries.** Scope teardown discards the memo;
- the memo remains **bounded** with least-recently-used eviction and a fixed
  internal capacity, so even within one long-lived scope it cannot grow without
  limit. The bound is an internal constant, **not a configuration key**,
  following the posture ADR 0011 took on backoff: public configuration surface
  must be justified by evidence.

The listener itself is Phase 5 work; this ADR fixes the constraint it must
satisfy.

## 8. Query instrumentation

**Decided: `EffectiveAccessSnapshot` carries no query counter.**

An earlier draft proposed `EffectiveAccessSnapshot::queryCount(): int`,
incremented by the resolver as it ran. That is rejected. A counter the resolver
increments reports **what the algorithm believed it did**, not what the
connection executed. It would stay at 3 if a future change added an eager-load,
a relation touch, a lazy `getTable()` lookup or an accidental `count()` — which
is precisely the regression the deliverable exists to catch. A self-reported
budget cannot detect a budget violation it did not author.

**Accepted: measure the connection.**

Query-budget verification wraps snapshot construction in a listener on the
GateTrail connection and counts the statements that actually execute:

```text
register listener on the GateTrail connection
  → build the snapshot
  → count statements observed
  → assert against the section 6.3 model
```

Rules:

- it counts **statements actually executed through the GateTrail connection**,
  resolved from `SchemaContext::connectionName()` so a configured connection is
  measured and an unrelated application connection is not;
- it records **counts and safe categories only** — for example "3 statements,
  3 select". It never retains SQL text, bindings, subject identifiers,
  definition names, guards or any other value;
- it is **test and diagnostic only**. It is not registered in normal
  application runtime, adds no listener to a production request, and is not
  referenced by the resolver;
- it creates **no public API**. No contract, no configuration key, and nothing
  a consumer can call;
- a warm memo hit is verified as **zero additional statements** on the same
  connection, which is a claim only a connection-level observer can make.

Phase 8's benchmark reuses the same mechanism, so the roadmap's "reproducible
benchmark" and this phase's "query-count instrumentation" measure the same
thing by the same means.

## 9. Transaction and concurrent-read semantics

### 9.1 Resolver reads are read-only

| Rule | Statement |
|---|---|
| Read-only | The resolver issues `SELECT` statements only. It never writes. |
| No coordination row | Resolver reads **never** acquire `acl_assignment_locks`. Coordination is a mutation concern (ADR 0001) and an authorization check must not serialize against writers. |
| No pessimistic locking | Resolver reads **never** use `SELECT … FOR UPDATE`. |
| No package-owned transaction | The resolver does **not** open a transaction merely to authorize. |

Opening a transaction per authorization check would put a transaction around
the hottest read path in the package, and taking the coordination row would let
any reader block every writer on that scope. Neither is justified by the
consistency it would buy — see section 9.2.

### 9.2 What one clock instant does and does not guarantee

**A shared clock instant provides temporal predicate consistency, not a
globally serialized database snapshot.**

Every lifecycle predicate in one snapshot build evaluates against the same
`evaluatedAt`, so the queries agree about *when* they are asking. They do not
agree about *what has committed*, because they are separate statements outside
a transaction:

- a concurrent mutation may commit **between** the resolver's queries;
- a role granted after query 1 is absent from that build's candidate list.

**GateTrail does not claim linearizable authorization against concurrent
revocation.** Making that claim would require locking or a serializable
transaction around every authorization read, which section 9.1 rejects. This
ADR states the weaker, true guarantee instead of an attractive false one.

What section 6.1.1's revalidation changes is **which side of the boundary a
revocation falls on**:

| Revocation commits | Result |
|---|---|
| Before query 3 | **Excluded.** Revalidation catches it. |
| After query 3 | Inside the residual window — this build already read its state |

Revalidation does not eliminate the window; it shrinks it from "the gap between
query 1 and query 3" to "after the last statement that read this state". That
residual window is irreducible without locking every authorization read.

The practical exposure is bounded: the next evaluation second rebuilds the
snapshot, and a same-scope mutation flushes the memo immediately (section 7.3).
A consumer needing a hard revocation boundary should treat the mutation's
committed state — not a concurrently-built authorization snapshot — as the
source of truth.

### 9.3 Fail-closed under partial visibility

Where the interleaving is observable, the snapshot fails closed and must
**never synthesize access from missing intermediate state**:

- a role revoked or deactivated before query 3 contributes nothing, because
  query 3 revalidates the assignment and the definition rather than trusting
  the candidate list (section 6.1.1). This case is **excluded, not tolerated**;
- a role granted after query 1 is absent from the candidate list, so its
  permissions are absent from this build. That is an **under**-grant and is the
  correct direction to fail;
- if step 1 returns **no** roles, step 3 is skipped and no role-derived
  permission is ever invented for a role that was not observed;
- a role identifier appearing in `role_permissions` but not surviving
  revalidation contributes nothing. Membership is driven by the revalidated
  path, never by the reverse join alone;
- a definition row missing from a join contributes nothing — the row is
  dropped, never defaulted;
- no snapshot entry is ever constructed from a partial row. An entry requires
  an observed identifier, name and guard together.

The only remaining direction of error is the residual window in section 9.2,
which is a stale read of state that was valid when it was read — not a grant
synthesized from absent state.

### 9.4 Caller-owned transactions

A consumer may call the resolver inside its own transaction, typically to check
state it has just written:

```php
DB::transaction(function () use ($user, $assignments, $permissions) {
    $assignments->assignRole($user, 'reviewer', actor: $admin, reason: '…');

    // must observe the uncommitted grant
    $permissions->allows($user, 'documents.review');
});
```

**Decided behaviour:**

| Situation | Behaviour |
|---|---|
| Active transaction detected on the GateTrail connection | **Bypass the request memo entirely** |
| Reading inside that transaction | Build authorization state directly from transaction-visible data |
| Result of that build | **Never stored** in the request memo |
| Successful outermost commit | `MutationCommitted` flushes request-local memoized state (section 7.3) |
| Rollback | No `MutationCommitted` is emitted |
| Memo entries created **before** the transaction | May remain valid after rollback |
| Nested transactions | Follow ADR 0010's outermost after-commit boundary |
| Reads inside the caller's transaction | Remain read-only — no lock, no write |

Detection uses the GateTrail connection's transaction level, the same signal
`AuditedMutationTransaction` and `RetryOrchestrator` already use to decide
ownership.

#### Why bypass is safer than caching uncommitted state

Memoizing a transaction-local snapshot would store state that **may never
exist**. If the caller rolls back, the memo would hold authorization derived
from writes that were discarded — and nothing would flush it, because
`MutationCommitted` is only emitted after a *successful* outermost commit
(ADR 0010). The stale entry would then serve committed-looking answers for the
rest of the request.

Bypassing has the opposite failure mode, and it is benign: the caller pays for
a rebuild on each check inside its transaction. Correctness costs queries; the
alternative costs correctness.

The converse case is also handled: a memo entry created *before* the
transaction opened reflects committed state, and a rollback changes no
committed state, so that entry is still valid afterwards. It does not need to
be discarded, and discarding it would be a pointless cost.

## 10. Failure taxonomy

Every outcome falls into exactly one category.

| Category | Conditions | Behaviour |
|---|---|---|
| **Ordinary authorization denial** | Missing definition; deactivated definition; cross-guard definition; inactive, scheduled, expired or revoked assignment; malformed or impossible role/permission identifier; unsupported caller-supplied definition model instance; no effective source | Return `false` |
| **Typed subject / caller failure** | Unpersisted, dirty-keyed, unidentifiable or invalid-morph subject | `InvalidSubject` |
| **Typed configuration failure** | Invalid authoritative guard; misconfigured `gatetrail.models.*` | `InvalidGuard`, `InvalidModelConfiguration` |
| **Typed platform failure** | Driver GateTrail has not verified | `UnsupportedDatabaseDriver` |
| **Infrastructure / database failure** | Connectivity failure; missing table; database permission failure; unclassified query failure | The driver exception, unchanged |
| **Internal invariant failure** | Corrupted persisted identity; impossible or contradictory snapshot state | Fail closed and raise; never degrade to `false` |

Explicit rules that follow:

- **Default deny does not hide configuration, platform, infrastructure or
  internal invariant failures.** Only the first row denies. Every other row
  surfaces. ADR 0003, ADR 0004 and ADR 0007 each state this for their own
  surface; Phase 5 implements it once.
- **Authorization denial does not throw `RoleNotFound`, `PermissionNotFound`
  or `GuardMismatch`.** A check asks a question; those exceptions name a target
  a caller intended to change.
- **Mutation APIs retain their current typed behaviour.** `RoleInputResolver`
  and `PermissionInputResolver` continue to throw `GuardMismatch`,
  `RoleNotFound` and `PermissionNotFound` for the same inputs the resolver
  denies. The two correctly answer differently, and this ADR must not unify
  them.
- **Cross-guard authorization denies without disclosing the other
  definition** — no name, no identifier, no guard, in any return value or
  exception.

### 10.1 Internal invariant failures

A corrupted persisted identity, or a snapshot state that cannot occur if the
algorithm is correct, is a **package defect**, not an authorization outcome.

Accepted rules:

- **never convert the failure to `false`.** Denial would hide a defect behind
  what looks like ordinary policy, and the operator would see nothing;
- raise a safe internal invariant exception, so the failure is loud and
  attributable;
- the exception exposes **no SQL, no bindings, no subject identifiers and no
  row contents** — the same posture as `InvalidSubject` (section 3.5) and
  `ConcurrencyRetryExhausted` (ADR 0011);
- the implementation **may initially use an internal `LogicException`**, or
  introduce a typed GateTrail invariant exception, whichever proves clearer
  during Increments 3–7;
- **the final exception boundary must be decided before the public API
  freeze**, because whatever escapes the package becomes compatibility surface.

**This ADR deliberately does not invent a public exception signature for it.**
Naming a class and its constructor now would fix public API against a failure
mode with no observed instances, which is exactly the kind of speculative
surface ADR 0011 declined to add for backoff configuration.

## 11. Laravel integration boundary

Recorded so the boundary is explicit. This ADR does **not** decide Phase 6; it
constrains it.

### 11.1 Gate hook

The `Gate::before` hook needs a definition lookup that **includes** deactivated
rows (ADR 0007 step 4). That lookup is not snapshot construction and must not
reuse it. Its root model is the definition itself, so `withTrashed()` genuinely
applies there — the section 5.3 hazard runs the other way and does not affect
it. Because it matches a name in SQL, it inherits the collation hazard from
section 4.5 and must re-verify the match byte-for-byte in PHP after the query,
following constraint 4's prefilter-then-verify pattern.

The hook also performs the `mixed` → `Model` narrowing described in section
1.2, returning `null` before the resolver is called.

### 11.2 Batch checks must resolve one state

**Phase 6 adapters must not implement any/all by repeatedly calling `allows()`
or `has()`.**

Each public call re-enters the section 4.1 flow independently. Across five
iterations the loop can:

- cross a whole-second evaluation boundary and resolve two different snapshots,
  so `documents.review` is judged at `T` and `documents.publish` at `T+1s`;
- observe a mutation that commits mid-loop and flushes the memo, so later items
  are judged against newer state than earlier ones;
- produce a composite answer that was never true of any single moment.

For an `all` check that is a correctness bug: the route grants access on the
basis of a permission set the subject never simultaneously held.

Required adapter shape:

```text
obtain ONE effective collection or snapshot for the subject
normalize every requested input                    (section 4.2 rules)
evaluate every membership against that ONE state
```

With, in addition:

- ADR 0004's empty-list and cardinality behaviour preserved — a singular alias
  requires exactly one parameter, any and all require one or more, and an empty
  or malformed list still raises `InvalidMiddlewareParameter` rather than
  denying;
- short-circuiting preserved, but at the **in-memory comparison layer**: `any`
  may stop at the first match and `all` at the first miss, because the state is
  already resolved and stopping early cannot change which state was used;
- **no additional definition lookup and no additional snapshot query per
  requested item.**

### 11.3 Other adapters

- the `HasGateTrail` concern gains subject convenience methods that delegate to
  the two contracts and hold no algorithm;
- `AuthenticationException` and `AuthorizationException` remain middleware
  concerns; the resolver returns booleans and never throws for a denial.

## 12. Database portability

### 12.1 Proportional acceptance evidence

An earlier draft stated that the entire acceptance matrix runs on SQLite and
needs no live database. **That is withdrawn.** These queries join across two
tables, qualify columns, filter soft-delete state explicitly and chunk `IN`
lists — behaviour whose portability claims SQLite alone cannot support.

Evidence is proportional to what is claimed for each engine.

| Engine | Required evidence before Phase 5 completion |
|---|---|
| **SQLite** | Full resolver behavioural matrix (section 17) |
| **MySQL** | Live resolver integration on the existing four-lane matrix: Laravel 12 / 8.0.16, Laravel 12 / 8.0.45, Laravel 13 / 8.0.16, Laravel 13 / 8.0.45 |
| **Oracle** | Targeted live resolver integration through the existing cost-controlled Oracle harness |
| **PostgreSQL** | Query-compilation and schema-adapter inspection only |
| **SQL Server** | Query-compilation and schema-adapter inspection only |

**No live PostgreSQL or SQL Server evidence may be claimed before a dedicated
runtime harness exists.** Compilation inspection proves the emitted SQL is
well-formed for the grammar; it does not prove runtime behaviour, and this ADR
must not let the two be confused. Those engines remain unverified for the
resolver until their harnesses land, exactly as the roadmap already treats them
for the schema adapters.

### 12.2 What the live tests must cover

At minimum, on MySQL across all four lanes and on Oracle through the targeted
harness:

- **explicit joined-definition `deleted_at` filtering** — a deactivated role
  and a deactivated permission each grant nothing, proving the predicate is
  present and effective on a real engine;
- **qualified table and column names** — the join resolves without ambiguity
  errors, which is the failure a bare column name would produce;
- **half-open lifecycle boundaries** — at `starts_at`, `expires_at - 1s`,
  `expires_at` and `expires_at + 1s`, against real column types and real
  timestamp binding;
- **direct and role-derived permissions**, including a permission reachable
  through both;
- **role-ID chunking where practical** — at least one run crossing the
  900-identifier bound, on whichever live engine can host it economically;
- **configured prefixes and schema context** — a prefixed and, where the engine
  supports it, schema-qualified installation resolves correctly;
- **exact result parity with SQLite** — the same fixture produces the same
  ordered, deduplicated effective collections on every engine exercised.

Parity is the load-bearing item: it is what turns per-engine runs into evidence
that one algorithm behaves identically everywhere.

The chunk size remains **900** on every engine (section 6.3). No engine gets a
different bound.

## 13. Security and privacy

| Property | Guarantee |
|---|---|
| Subject substitution | The supplied subject is **never** replaced by `Auth::user()`. The resolver reads no global authentication state (section 3.4). |
| Subject identifier disclosure | `InvalidSubject` never exposes the raw subject identifier — only the morph type and a fixed reason vocabulary (section 3.5). |
| Cross-guard disclosure | A cross-guard definition's existence, name, identifier and guard are never disclosed. It denies identically to a missing definition (sections 3.6, 10). |
| Instrumentation | The query listener retains **no** SQL text, bindings, subject identifiers, definition names or guards — counts and safe categories only (section 8). |
| Model escape | Mutable Eloquent models never escape through effective collections. Only immutable value objects are returned (section 1.4). |
| Name comparison | Exact role and permission name comparison happens in PHP, so no database collation can widen a match (section 4.5). |
| Wildcards | No wildcard permission behaviour exists. Names are compared exactly (specification section 5.7). |
| Explicit deny | No deny assignment and no deny precedence exists (specification section 5.3). |
| Super-administrator | No implicit super-administrator exists. No role name bypasses a check (specification section 5.4). |
| Memo data | Request memo data is **process-local, scoped and bounded**. It is never written to a cache store, never shared between processes, and never crosses a scope boundary (sections 7.1, 7.4). |
| Inert value objects | `EffectivePermission` and `EffectiveRole` are **output only**. No resolver method accepts them, so a consumer-constructed instance is never proof of authorization and cannot be submitted as evidence — the typed input union excludes it (section 1.4). |
| Failure conversion | Infrastructure, configuration, platform and internal invariant failures are **never** converted into an authorization denial (section 10). |

The last row is the one with teeth. Converting a database failure into `false`
would look like a safe default and would in fact be the opposite: it hides an
outage behind a stream of denials that an operator cannot distinguish from
correct policy, and it trains consumers to treat denial as unremarkable.

## 14. Alternatives considered

**Resolve permissions with a single joined query.** A `UNION` of the direct and
role-derived branches, or a three-way join, would cut three queries to one.
Rejected: `UNION` deduplication semantics, `NULL` ordering and text collation
all differ across the supported engines, and the result would have to be
re-verified in PHP anyway. Three simple portable queries are easier to reason
about, easier to test on SQLite, and cheaper to keep correct on Oracle.

**Split every join into two queries so the definition is a root model.** Query
the assignment table for identifiers, then query `Role` or `Permission` by
primary key so `SoftDeletes` and the guard filter apply to a root model
naturally. This is the structurally safest answer to section 5.3 and it was
seriously considered. Rejected because it raises a cold snapshot from three
queries to five, adds a second round trip to the hot path of every request, and
buys safety that one explicit `whereNull()` per join already provides. The
residual risk — someone deleting the explicit predicate — is covered by the
captured-SQL assertions and the deactivation regression tests in section 17,
which fail loudly rather than fail open. Reconsider if Phase 8 benchmarks show
the join itself is the bottleneck.

**Filter definition lifecycle through Eloquent relations.** Rejected on
constraint 3: all four definition relations already call `withTrashed()`
deliberately, so relation-based filtering would include deactivated definitions
and fail open. Changing those relations to suit the resolver is also rejected —
it would break specification section 9.1's guarantee that deactivation does not
rewrite assignment history.

**Push the name comparison into SQL.** `where('name', $name)` for each check.
Rejected: it costs a query per check, it defeats memoization, and it makes
correctness depend on the database's collation — a case-insensitive MySQL
collation would violate specification section 5.7.

**Return Eloquent models from the effective collections.** Rejected in section
1.4: mutable, connection-bound, and an open invitation to lazy-load relations
inside a loop.

**Give the resolver a `mixed` subject.** Rejected: it pushes a runtime type
check into every one of Phase 6's five adapters, and PHPStan at level max
cannot help any of them.

**Add a `GateTrailSubject` interface and require it.** Rejected: contradicts
ADR 0003's explicit decision not to require an interface for GateTrail
participation.

**Let `RoleResolver` be a Phase 6 deliverable.** Rejected in section 2.4:
Phase 6 must not introduce a resolution contract, and role resolution is
already computed by Phase 5.

**Leave mutation subject-identity alignment as a later, unrelated concern.**
Rejected. Constraint 6 shows the two existing copies have already drifted, and
a resolver copy would make a read and a write disagree about subject identity.
Section 16's Increment 1 fixes it first.

**Memoize the snapshot built inside a caller-owned transaction.** Rejected in
section 9.4: a rollback would leave authorization derived from discarded writes
in the memo, with no event to flush it.

**Give the resolver a self-reported query counter.** Rejected in section 8: it
reports the algorithm's intent, not the connection's behaviour, so it cannot
detect the regression it exists to catch.

**Accept a per-call guard override.** Rejected — ADR 0003 already rejected it
for the whole package and noted that changing it "requires its own ADR because
it affects assignments, cache keys, resolution methods, and mutation
signatures".

**Cache the snapshot across requests now.** Rejected: specification section
14.1 requires the resolver to be correct without cross-request caching, and
section 14.6 keeps it disabled until store, TTL, fan-out and invalidation are
accepted and measured. Phase 8 owns that decision.

**Claim SQLite-only acceptance.** Rejected in section 12.1: the queries make
multi-engine claims that SQLite cannot evidence.

## 15. Consequences

### Positive

- For one snapshot, boolean checks and effective collections are exact
  projections of the same state and cannot disagree.
- Direct permissions, role-derived permissions, effective roles and effective
  permissions share exactly one lifecycle filter and one guard filter, so no
  second algorithm exists that could drift.
- One shared `SubjectIdentityResolver` means a read and a write can never
  disagree about who the subject is, and the existing drift between two handler
  copies is repaired rather than extended.
- A cold evaluation costs two or three queries on the common path and a warm
  one costs zero additional queries. The number of roles never produces one
  query per role.
- Missing, deactivated and cross-guard definitions are indistinguishable to a
  caller, satisfying non-disclosure without a disclosure-specific code path.
- No check issues a separate definition-lookup query, and the caller's
  requested definition input is never bound into any statement. A rejected
  identifier denies before a snapshot is built, so no malformed value reaches a
  PostgreSQL, Oracle or SQL Server binding.
- An unrelated model implementing `PermissionContract` or `RoleContract` cannot
  authorize anything through a colliding identifier.
- A role revoked or deactivated after the role query but before the
  role-permission query grants nothing, because the second query revalidates
  the whole path instead of trusting the candidate identifiers.
- Result value objects are output only, so there is no input path through which
  a consumer-constructed object could be mistaken for evidence of access.
- Exact, case-sensitive name comparison holds identically on every engine.
- Query budgets are verified against statements the connection actually
  executed, so a future accidental query fails the budget test instead of being
  hidden by a self-reported counter.
- Authorization reads take no lock and open no transaction, so an authorization
  check can never block a writer.
- The transaction rule lets a consumer read its own uncommitted writes without
  ever memoizing state a rollback would discard.
- Public collections are immutable value objects that cannot alias memoized
  state.
- Phase 6's five adapters receive fully typed contracts and contain no
  algorithm.
- PHPStan at level max can check every call site; no `mixed` appears in the
  public surface.

### Negative

- Two public contracts and two value-object classes are added to the pre-v1
  surface, and both contracts must be documented before the API freeze.
- `Model` as the subject type means a non-Eloquent subject cannot be
  authorized. Applications with such subjects must wrap them, and the Gate hook
  must narrow before calling.
- Increment 1 changes shipped mutation behaviour: a dirty subject primary key
  that two handlers tolerate today will raise `InvalidSubject`. This is
  deliberate, but it is a behaviour change inside Phase 4 code and needs its
  own tests.
- Whole-second memo keys rebuild the snapshot once per second per subject in a
  request that spans several seconds.
- A wholesale memo flush on any `MutationCommitted` discards unrelated
  subjects' snapshots within that scope. Correct, but coarse.
- **The memo flush is same-process only.** A mutation committed by another
  worker does not clear this process's memo, so a foreign scope's snapshot can
  survive until its whole-second key or its scope ends. Phase 5 offers no
  cross-process invalidation.
- Every check inside a caller-owned transaction rebuilds the snapshot, so a
  transaction performing many checks pays the cold budget repeatedly.
- **GateTrail does not offer linearizable authorization against a concurrent
  revocation.** Query-3 revalidation excludes a revocation committed before
  that query, but a revocation committing after it is still read as valid for
  that build. The residual window is irreducible without locking every
  authorization read, and it is stated rather than papered over.
- Step 3 carries two joins plus a correlated `EXISTS` instead of a single join,
  so its plan is more complex and more dependent on the `role_assignments` and
  `role_permissions` indexes. This is the cost of not trusting the candidate
  list, and it adds no statements.
- The `EXISTS` shape is required rather than preferred: `scopeActiveAt()` emits
  unqualified lifecycle columns, so `role_assignments` and `role_permissions`
  can never share a `FROM` scope. Any future query joining two
  assignment-shaped tables inherits the same constraint.
- Chunking the role-id `IN` list adds one role-permission query per additional
  900 active roles on the pathological path, so the query budget is a model
  rather than a single number.
- Package-controlled construction means consumers cannot build
  `EffectivePermission` or `EffectiveRole` instances through a supported API.
  Consumer tests that want one must exercise the resolver or use package
  fixtures, which is slightly more setup than calling a constructor. The trade
  is deliberate: the parameter lists stay off the compatibility surface.
- Two separate public calls may legitimately disagree when time advances or a
  mutation commits between them. Parity holds per snapshot, not across time,
  and consumers needing a consistent multi-item answer must obtain one
  collection — which is why section 11.2 forbids the `allows()` loop.
- A morph-map change after assignments exist silently denies until the data is
  migrated; the resolver cannot detect it and defers to `gatetrail:doctor`.
- Source attribution is unavailable, so "why is this allowed?" cannot be
  answered from the public API in v1, and adding it later is not
  compatibility-free.
- **Definition lifecycle and guard filtering are the resolver's own explicit
  predicates, not framework behaviour.** Deleting one of them fails *open* — a
  deactivated or cross-guard definition would start granting access — which is
  the most dangerous failure mode in this design. It is mitigated by the
  captured-SQL assertions and deactivation regression tests in section 17, but
  it remains a standing maintenance hazard.
- Phase 5 now requires live MySQL and targeted live Oracle evidence, so it
  cannot complete on a developer machine without Docker.

## 16. Implementation increments

A sequencing plan. **Accepting this ADR completed no increment below**; each
one is marked complete only when its own implementation evidence exists, which
is why every increment carries a status line and an evidence table rather than
a claim. **Completing an increment does not change `ROADMAP.md` counts or
percentages either** — the ten Phase 5 deliverables are atomic capabilities,
and shared infrastructure is counted through the capability that consumes it.

Increments 1–3 are complete. Increments 4–10 are not started.

### Increment 1 — Shared subject identity foundation and mutation alignment

**Status: Complete, 2026-08-13.**

- **Scope.** Introduce `SubjectIdentityResolver`, the immutable
  `SubjectIdentity` value, and `InvalidSubject`. Replace the two private
  `subjectIdentity()` copies in `AssignRoleHandler` and
  `GrantDirectPermissionHandler` with the shared component.
- **Acceptance evidence.** One identity rule proven for reads and writes; dirty
  key, unpersisted, missing key, unsupported key type, non-decimal identifier
  (including UUID and ULID) and invalid morph type each raise `InvalidSubject`;
  `'007'`, `'7'` and `7` resolve identically; `InvalidSubject`'s object graph
  carries no raw identifier; existing mutation suites still pass; public
  mutation signatures unchanged.
- **Non-goals.** No resolver, no snapshot, no contracts, no queries. **No
  UUID/ULID subject support** — section 3.1.1 keeps that for a future
  coordinated design.
- **Required before the resolver is considered complete.**

#### Increment 1 evidence

Delivered in `src/Subjects/SubjectIdentity.php`,
`src/Subjects/SubjectIdentityResolver.php` and
`src/Exceptions/InvalidSubject.php`, bound as a singleton and consumed by both
assignment handlers.

| Requirement | Evidence |
|---|---|
| One rule for reads and writes | `SharedSubjectIdentityTest` asserts both handlers depend on `SubjectIdentityResolver`, that neither retains a private `subjectIdentity()`, and that one shared rejection table holds for the resolver and both mutation paths |
| `'007'`, `'7'` and `7` are one subject | The canonical identifier is a **native `int`**, so the three inputs are identical by `assertSame()`, not merely equal; both mutation paths persist the same `subject_id` |
| Full rejection table | `'-1'`, `'+1'`, `' 1'`, `'1 '`, `"1\n"`, `'1.0'`, `'1e3'`, `'0x1f'`, `''`, non-ASCII digits, UUID, ULID, unsupported key types, unsaved subject, dirty key and empty or whitespace-only morph type |
| No saturation above the boundary | Digit-string comparison against `PHP_INT_MAX`, with a test asserting the same value casts to `PHP_INT_MAX` and is nonetheless rejected |
| `InvalidSubject` carries no raw identifier | `InvalidSubjectTest` reflects over the class: private constructor, exactly two `readonly string` properties, the five-term vocabulary, no factory parameter other than a safe type label, `getPrevious()` null, and sensitive identifiers absent from the message, the string cast and the object vars |
| Failure precedes all side effects | A rejected subject issues **zero database statements**, leaves the assignment, coordination-lock and audit tables empty, dispatches no `MutationCommitted`, and leaves `transactionLevel()` at zero |
| Existing behaviour unchanged | The full suite passes; public manager signatures asserted unchanged by reflection |

Two findings were recorded rather than silently absorbed:

- **`GrantDirectPermissionHandler` previously accepted an empty morph type**
  and wrote it into `subject_type`, while `AssignRoleHandler` rejected it. The
  paths now agree. This is a deliberate behaviour change, not a refactor.
- **A key rewritten to its own persisted value in a different scalar type is
  not a mutation.** Eloquent compares the primary key through its cast, so
  `$subject->id = '1'` on a subject persisted as `1` is not dirty, and
  canonicalization collapses both shapes to one identity. The dirty rule
  protects against a key pointing at a *different* subject, which is what the
  test now pins.

### Increment 2 — Value objects and public contracts

**Status: Complete, 2026-08-13.**

- **Scope.** `EffectivePermission`, `EffectiveRole`, and the
  `PermissionResolver` and `RoleResolver` interfaces. Both value objects are
  `final readonly` with a **private constructor** and an `@internal` named
  factory, per section 1.4.
- **Acceptance evidence.** Shapes and field names asserted; immutability
  asserted; the constructor asserted private by reflection; the named factory
  asserted `@internal`; PHPStan at level max clean; no `mixed` in the public
  surface.
- **Non-goals.** No implementation behind the contracts yet. No public consumer
  factory. **No container bindings** — see below.

#### Container bindings belong to Increment 7, not here

An earlier draft of this increment listed "interfaces, with container
bindings", which contradicted its own non-goal. **This is a sequencing
correction, not an architectural change**: nothing about the contracts, the
value objects or the binding strategy changes, only when the binding is
registered.

An interface has no meaningful Laravel binding without a concrete class behind
it. Binding one in this increment would require a fake resolver, a throwing
placeholder, a null object or a closure that fails on resolution — and each of
those is worse than no binding at all. A null object is the dangerous case: a
resolver that answers `false` is indistinguishable from a legitimate deny, so a
placeholder could silently satisfy `Gate::before` and look like a working
authorization system.

`PermissionResolver` and `RoleResolver` are therefore **deliberately unbound**
until Increment 7 registers `DefaultPermissionResolver` and
`DefaultRoleResolver`. Resolving either contract before then fails loudly,
which is the correct behaviour for a capability that does not exist yet.

#### Increment 2 evidence

Delivered in `src/Access/EffectivePermission.php`,
`src/Access/EffectiveRole.php`, `src/Contracts/PermissionResolver.php` and
`src/Contracts/RoleResolver.php`.

| Requirement | Evidence |
|---|---|
| Accepted value-object shape | Reflection asserts `final`, `readonly`, exactly `id` (`int\|string`), `name` (`string`), `guardName` (`string`), in that order, on both types |
| Package-controlled construction | Constructor asserted **private**; `of()` asserted public, static and carrying `@internal Package-controlled construction. Not supported consumer API.`; `of()` asserted to be the **only** public method, so no second consumer factory can appear |
| Identifiers preserved exactly | `7`, `'7'`, `'007'` and a UUID string each round-trip through `of()` unchanged — the value objects normalize nothing, because normalization belongs to the resolver |
| Immutability at runtime | Writing a declared property and writing an undeclared property both raise `Error` |
| Nothing escapes | Every held value asserted non-object and non-array, and the field list asserted to be exactly the three names |
| Accepted contract signatures | Reflection asserts `Model` (not `mixed`) first, the exact definition unions, `bool` and `Collection` returns, and the `Collection<int, EffectivePermission>` / `Collection<int, EffectiveRole>` generics |
| No `mixed` in the public surface | Asserted across every parameter, every return type **and every docblock** on both interfaces |
| Output types are not inputs | Asserted that neither `EffectivePermission` nor `EffectiveRole` appears in any parameter union of its own contract |
| Contracts carry no algorithm | Asserted interface, no properties, no constants, every method abstract |
| No premature implementation | No declared class implements either contract, and neither `DefaultPermissionResolver` nor `DefaultRoleResolver` exists |
| Bindings deferred | Both contracts asserted unbound and un-aliased, and `make()` asserted to throw `BindingResolutionException` rather than return a placeholder — contrasted against the three manager contracts, which **are** bound because they have implementations |

### Increment 3 — Immutable `EffectiveAccessSnapshot`

**Status: Complete, 2026-08-17.**

- **Scope.** The internal snapshot: identity, guard, instant, ordered unique
  role and permission lists, and its projection methods.
- **Acceptance evidence.** Ordering by `strcmp`; uniqueness by primary key;
  fresh `Collection` per projection; no model, connection or counter retained.
- **Non-goals.** No queries; the snapshot is built from in-memory fixtures.

#### Increment 3 evidence

Delivered in `src/Access/EffectiveAccessSnapshot.php`, asserted by
`tests/Access/EffectiveAccessSnapshotTest.php`.

**Construction shape.** Private constructor behind one `@internal` named
factory, matching the pattern of `EffectivePermission::of()` and
`MutationCommitted::record()`:

```php
EffectiveAccessSnapshot::of(
    SubjectIdentity $subject,
    string $guardName,
    DateTimeImmutable $evaluatedAt,
    iterable $roles,        // iterable<array-key, EffectiveRole>
    iterable $permissions,  // iterable<array-key, EffectivePermission>
): self
```

No parameter is `mixed`, none accepts a model or a raw row, and the element
generics are declared for PHPStan. `iterable` is accepted rather than `array`
so the future resolver may hand over lazily produced values; a foreign element
is rejected with an `InvalidArgumentException` carrying **type labels only** at
the boundary, rather than surfacing later as a `TypeError` inside the sort
comparator.

**Internal method names.** Ten public methods, no more:

```text
of()                    subject()               guardName()
evaluatedAt()           roles()                 permissions()
hasRoleId()             hasRoleNamed()
hasPermissionId()       hasPermissionNamed()
```

The four membership methods are what Increment 7's thin adapters will project
through. **Neither `PermissionResolver` nor `RoleResolver` was widened**, and
the snapshot implements neither.

| Requirement | Evidence |
|---|---|
| Accepted stored state | Reflection asserts exactly nine `private readonly` properties: the identity, the guard string, the instant, the two canonical `list`s and four `array<string, true>` membership indexes. The index arrays are the only addition to section 2.2's list and exist solely so a batch check is a hash lookup rather than a scan |
| Ordering | Both lists canonicalized by `usort()` with raw-byte `strcmp()`. The fixture `zulu, Apple, Émile, _underscore, apple, Zebra` orders to `Apple, Zebra, _underscore, apple, zulu, Émile` — asserted against a literal list *and* against an independent `strcmp()` sort. `strcasecmp()` and a `strtolower()` sort each produce a different order from that fixture, so passing is evidence of byte ordering and not a coincidence. Natural ordering agrees with byte ordering on alphabetic names, so it is separated by its own fixture: `report2` and `report10` order to `report10, report2`, which is `strcmp()` and the opposite of `strnatcmp()`. No `ORDER BY`, no `strcoll()`, no collation |
| Uniqueness by primary key | Three instances of the same permission — the realistic "reachable through two roles and a direct grant" case — collapse to one. Deduplication is first-occurrence-wins and `usort()` is stable, so one input list always yields one output list |
| Type-stable identity key | Index keys are prefixed `int:`, `string:` and `name:`. Without the prefix PHP would rewrite the decimal-string key `'7'` to integer `7` and silently merge two distinct definitions. Asserted: identifiers `7` and `'7'` produce **two** entries; `hasRoleId(7)` is `false` for a role keyed `'7'` and vice versa; `'007'` matches nothing keyed `'7'` |
| Separate namespaces | Role and permission indexes are separate properties, and the `name:` prefix keeps a name from ever landing in an identifier bucket — asserted with a role whose identifier is `'reviewer'` and whose name is `'7'` |
| Not deduplicated by name | Two definitions sharing a name under one guard survive as two entries. That fixture is **synthetic**: `(name, guard_name)` is unique, so it cannot represent valid persisted state. It proves only which key deduplication uses |
| Membership semantics | Identifier lookups preserve `int` versus `string` identity; name lookups are byte-for-byte and case-sensitive, so `reviewer`, `REVIEWER`, `' Reviewer'` and `'Reviewer '` all miss `Reviewer`. Nothing is normalized here — that belongs to the Increment 7 adapters — and nothing is looked up from a definition table |
| Supplied instant | `evaluatedAt()` returns the **identical** `DateTimeImmutable` instance it was constructed with, preserving timezone and microseconds. The source is asserted to contain no `new DateTimeImmutable`, `Carbon`, `now()`, `time()`, `microtime(`, `date_create` or `SystemClock` |
| Fresh `Collection` per projection | `roles()` and `permissions()` return a new `Collection` per call, asserted `assertNotSame`. `push()`, `forget()`, `pop()`, `shift()`, `transform()`, `sort()` and `filter()` on a returned collection change neither a later projection nor any membership answer. Entries are deliberately shared — they are `final readonly` — while the mutable container never is |
| Object-graph safety | The retained graph is walked by reflection, including private properties, and asserted to contain no `Model`, `ConnectionInterface`, query or Eloquent builder, `Relation`, `Collection`, container or `Closure`. `DateTimeImmutable` is treated as an opaque leaf rather than asserted against PHP's own internals |
| No query counter | Section 8 rejects a self-reported counter outright. Asserted structurally: no stored property name matches `/count\|quer\|statement/i`, and no stored value is an `int` |
| No database activity | Building and exercising a snapshot under a connection listener records **zero statements** and leaves `transactionLevel()` at zero. The listener is the same mechanism `SharedSubjectIdentityTest` already uses, and no schema, migration or database dependency was added for it |

**Deliberately not added.** No guard-consistency check between the snapshot
guard and an entry's `guardName`, and no lifecycle assertion. Guard filtering
and lifecycle filtering are Increments 4–6; validating them here would be a
second copy of a rule that does not exist yet, which is exactly the drift
topology C was chosen to prevent.

### Increment 4 — Portable lifecycle and query components

- **Scope.** The joined-definition query builders with explicit
  `deleted_at IS NULL` and `guard_name` predicates, names resolved from model
  accessors, full column qualification, `scopeActiveAt()` reuse.
- **Acceptance evidence.** Captured-SQL assertions for every explicit
  predicate; prefix and schema honoured; replacement model with an overridden
  `DELETED_AT` honoured; no inherited-scope reliance.
- **Non-goals.** No memoization, no public contract wiring.

### Increment 5 — Direct permission and effective-role resolution

- **Scope.** Snapshot queries 1 and 2.
- **Acceptance evidence.** Half-open boundary matrix; revoked, scheduled,
  expired excluded; deactivated definitions excluded; cross-guard excluded;
  two-query budget when the subject has no roles.
- **Non-goals.** No role-derived permissions.

### Increment 6 — Role-derived permission resolution with chunking

- **Scope.** Snapshot query 3 with **full path revalidation** via a correlated
  `EXISTS` over `RoleAssignment` (section 6.1.1), 900-item role-ID chunking,
  and merge with direct permissions.
- **Acceptance evidence.** Three-query budget with roles, unchanged by the
  `EXISTS`; a role assignment revoked, or a role deactivated, between query 1
  and query 3 contributes nothing; a permission via two roles and a direct
  grant appears once after PHP deduplication; chunk boundary at exactly 900 and
  901; chunked and unchunked results identical; the `EXISTS` and its inner
  lifecycle predicate asserted from captured SQL; the shape compiles under all
  five grammars.
- **Non-goals.** No engine-specific chunk sizes. No raw SQL for the subquery.
  No modification of `HasAssignmentPeriodScopes`. No attempt to close the
  residual post-query-3 window of section 9.2 — that would require locking.

### Increment 7 — Thin resolver adapters

- **Scope.** `DefaultPermissionResolver` and `DefaultRoleResolver` over the
  snapshot: input normalization, the section 4.2 matrix, the section 4.4
  configured-model rule.
- **Acceptance evidence.** Full input matrix for both resolvers; `'007'` versus
  `Reference::id('007')`; unrelated-model denial; parity under a fixed clock;
  failure taxonomy honoured.
- **Non-goals.** No Phase 6 adapters — no Gate hook, concern or middleware.

### Increment 8 — Request-local memoization and `MutationCommitted` coherence

- **Scope.** The scoped bounded memo, the whole-second key, the wholesale flush
  listener with lazy scoped resolution, and caller-transaction bypass.
- **Acceptance evidence.** Warm hit costs zero statements; second boundary
  rebuilds; same-scope flush on `MutationCommitted`; mutate-then-check sees new
  state with no stale interval; a second scope's memo is unaffected;
  transaction bypass never memoizes; pre-transaction memo survives rollback; no
  singleton capture of a scoped store; LRU eviction under the bound.
- **Non-goals.** No cache store, TTL, tag or fan-out. **No cross-process
  invalidation** — section 7.3.1 keeps that for the future cache ADR.

### Increment 9 — Query instrumentation and portability hardening

- **Scope.** The connection-level statement listener for tests and diagnostics;
  PostgreSQL and SQL Server query-compilation inspection.
- **Acceptance evidence.** Budgets asserted from real connection activity; the
  listener retains no SQL, bindings or identifiers; compiled SQL inspected for
  both unhosted engines.
- **Non-goals.** No claim of live PostgreSQL or SQL Server evidence.

### Increment 10 — Full resolver acceptance matrix

- **Scope.** The complete section 17 matrix on SQLite, live MySQL across all
  four lanes, and targeted live Oracle.
- **Acceptance evidence.** Section 12's proportional matrix satisfied and
  recorded with its CI run, in the form ADR 0011 used for its own live
  evidence.
- **Non-goals.** No Phase 6 work; no cross-request caching; no roadmap
  percentage change until this increment passes.

## 17. Acceptance criteria

Tests must prove the following. The SQLite matrix is the full behavioural
suite; section 12 defines what additionally runs live.

**Parity and shared state**

Asserted under a **fixed clock, unchanged persisted state, and one memoized
snapshot or evaluation boundary**.

- `allows()` is true for exactly the permissions in `effectivePermissions()`,
  over a generated matrix rather than a handful of examples;
- `has()` is true for exactly the roles in `effectiveRoles()`;
- one permission reachable through two roles and a direct grant appears once;
- revoking one of several sources leaves the permission effective;
- both resolvers reach the same effective role set for the same subject, guard
  and instant;
- **divergence is correct where expected**: advancing the fixed clock past an
  expiry, or committing a mutation between two calls, changes the second answer
  and the test asserts the change.

**Subject identity (Increment 1)**

- one shared component serves the resolver, `assignRole()` and
  `grantDirectPermission()`, asserted by behaviour parity across all three;
- an unsaved model raises `InvalidSubject` on every path;
- a null, missing or unsupported key raises `InvalidSubject` on every path;
- a dirty primary key raises `InvalidSubject` on every path;
- an empty morph class raises `InvalidSubject` on every path — including the
  mutation path that does not check it today;
- editing non-key attributes changes no result and no persisted identity;

*Unsigned decimal subject identifiers (section 3.1.1)*

- `42`, `'42'` and `'007'` resolve to the same subject on every path;
- `'-1'`, `'+1'`, `' 1'`, `'1 '`, `'1.0'`, `'1e3'`, `'0x1f'` and `''` each raise
  `InvalidSubject`;
- a **UUID** subject key raises `InvalidSubject`;
- a **ULID** subject key raises `InvalidSubject`;
- the resolver and both mutation paths reject the identical set, asserted
  against one shared table of cases;
- a subject rejected by the resolver could not have been assigned a role by the
  mutation path either, so no subject exists that can be granted but not
  checked;

- `InvalidSubject`'s object graph contains only `subjectType` and `reason`, and
  no raw identifier, model, attribute or driver value appears anywhere —
  including for a rejected UUID, whose value must not be echoed;
- `getPrevious()` is `null`;
- a morph map is honoured and matches the persisted `subject_type`;
- public mutation signatures are unchanged;
- an authenticated user different from the supplied subject changes nothing, in
  HTTP, queue, CLI and test contexts.

**Guard**

- the ADR 0003 precedence order is honoured by the resolver;
- an invalid authoritative guard raises `InvalidGuard` and is not a denial;
- a name check never searches another guard;
- a cross-guard model, identifier and reference each return `false`;
- no cross-guard definition's name, id or guard is disclosed;
- the guard is resolved once per evaluation.

**Input semantics**

- every row of the section 4.2 matrix, for both resolvers;
- `'42'` matches a definition named `42` and does not match id `42`;

*Strings are names; references are identifiers*

- `allows($s, '007')` matches a permission **named** `007`, not identifier `7`;
- `allows($s, PermissionReference::id('007'))` matches identifier **`7`**, not
  a permission named `007`;
- `has($s, RoleReference::id('007'))` behaves identically;
- `id('7')`, `id(7)` and `id('007')` are the same question;
- against a **string-keyed** configured model, `id('007')` is preserved as
  `'007'` and does not match `'7'`;
- `'9223372036854775807'` is accepted;
- `'99999999999999999999'` is rejected rather than saturating to `PHP_INT_MAX`;
- `'-1'`, `'+1'`, `' 1'`, `'1 '`, `'1.0'`, `'1e3'`, `'0x1f'`, `'abc'` and `''`
  are each rejected as identifiers;
- a connection-listener assertion proves a rejected identifier executes **zero**
  statements.

*Non-configured models cannot authorize*

- an unrelated `PermissionContract` implementation whose key collides with an
  effective permission returns `false`;
- an unrelated `RoleContract` implementation whose key collides with an
  effective role returns `false`;
- a **subclass** of the configured definition model is accepted;
- a **configured replacement** model class is accepted;
- an unsaved configured model returns `false`;
- a configured model with a `null` or unsupported key returns `false`;
- a configured cross-guard model returns `false` and discloses nothing;
- none of the above throws `InvalidModelConfiguration`;
- a genuinely misconfigured `gatetrail.models.*` still throws
  `InvalidModelConfiguration`;
- an edited `name`, `guard_name` or `deleted_at` on a supplied configured model
  changes no result.

**Lifecycle and time**

- boundary tests at `starts_at`, `starts_at - 1s`, `expires_at - 1s`,
  `expires_at` and `expires_at + 1s`;
- `starts_at = null` uses `assigned_at`;
- revoked, scheduled and expired assignments are excluded;
- a deactivated role grants nothing, and restoring it restores access without
  restoring revoked assignments;
- a deactivated permission is never effective, directly or through a role;
- a deactivated role whose subject-role assignment is still active and
  unexpired grants nothing — the case an inherited global scope would have
  silently allowed;
- a deactivated permission reached through an active role-permission row on an
  active role grants nothing, asserted separately because a different query
  produces it;
- a definition in another guard is absent even when its assignment row is
  perfectly active;
- one clock read per public call, asserted with a recording fake clock;
- every snapshot query receives the same instant.

**Explicit join predicates**

Asserted from captured SQL, because their absence fails open.

- each snapshot query emits a `deleted_at is null` predicate **against the
  joined definition table**;
- each emits a `guard_name = ?` predicate against the joined definition table;
- every column reference in a joined query is table-qualified;
- a replacement model overriding `DELETED_AT` produces the overridden column;
- a configured prefix and schema qualifier appear in the join clause and every
  qualified column;
- the four definition relations still resolve soft-deleted rows, so a future
  removal of `withTrashed()` fails here rather than silently breaking audit
  snapshot rebuilding;
- the resolver never uses `whereHas`, `with` or `has` against a definition
  relation for lifecycle filtering.

**Query budget and memoization**

All counts asserted with the section 8 connection listener.

- cold with roles executes **3** statements; cold without roles **2**;
- ten `allows()` calls within one evaluation second execute **3** in total;
- a warm memo hit executes **0** additional statements;
- growing from 2 to 50 roles does not change the count;
- more than 900 roles executes `2 + ceil(roles / 900)` and returns the same
  effective permissions as the unchunked case;
- exactly 900 and exactly 901 roles are asserted as the chunk boundary;
- the chunk size is identical on every engine;
- the listener retains no SQL text, bindings, subject identifiers or definition
  names, asserted by inspecting what it captured;
- the listener is not registered outside tests and diagnostics;
- a second boundary rebuilds the snapshot;
- `MutationCommitted` discards the memo, and mutate-then-check in one request
  sees the new state;
- the memo evicts under its bound and does not grow without limit;
- **no singleton listener captures a scoped memo store**: two successive scopes
  use two distinct stores, and the first is not retained;
- a returned `Collection` may be mutated without affecting a later `allows()`.

**Transaction and concurrency**

- the resolver issues no `INSERT`, `UPDATE` or `DELETE`;
- the resolver never touches `acl_assignment_locks`;
- no snapshot query emits `FOR UPDATE`, asserted from captured SQL;
- the resolver opens no transaction when none is active;
- inside a caller-owned transaction, a check observes uncommitted writes;
- inside a caller-owned transaction, no snapshot is written to the memo;
- after rollback, no `MutationCommitted` is emitted;
- a memo entry created before the transaction is still valid after rollback;
- nested transactions honour ADR 0010's outermost boundary;
- a role identifier present in `role_permissions` but not surviving query 3's
  revalidation contributes nothing;
- a snapshot entry is never constructed from a partial row.

*Query-3 revalidation (section 6.1.1)*

Behavioural:

- with the subject's role assignment **revoked between** query 1 and query 3,
  that role's permissions are absent from the snapshot — the over-grant the
  earlier design would have produced;
- with the role **deactivated between** query 1 and query 3, likewise absent;
- with the role moved to another guard between the queries, likewise absent;
- interleaving is driven deterministically, by committing the revocation from a
  second connection inside a hook between the two statements, not by timing;
- a role granted **after** query 1 is absent from that build and present in the
  next evaluation — asserted as an intentional under-grant;
- a permission reachable through two role-permission rows appears once, proving
  PHP deduplication by permission primary key.

Predicate binding — these pin that each `activeAt()` bound to its own table:

- a **revoked role-permission grant** with an active role assignment yields
  nothing;
- an **active role-permission grant** with a revoked role assignment yields
  nothing;
- an active grant with an active assignment yields the permission;
- the same three cases repeated for `expires_at` and for `starts_at` in the
  future, so each lifecycle column is pinned independently on both tables.

Captured SQL:

- query 3 contains a **correlated `EXISTS`** whose `FROM` is the resolved
  `role_assignments` table;
- the **role-assignment lifecycle predicate is inside that subquery** —
  `revoked_at`, `starts_at`/`assigned_at` and `expires_at` all appear within
  the `EXISTS`, not in the outer `WHERE`;
- the subquery filters `subject_type` and `subject_id`;
- the correlation predicate is **qualified on both sides**
  (`role_assignments.role_id = role_permissions.role_id`);
- the outer query joins `roles` and `permissions` with explicit
  `deleted_at IS NULL` and `guard_name` predicates;
- the outer query does **not** join `role_assignments`;
- root role-permission lifecycle columns are **unambiguous**: only one
  lifecycle-bearing table is in the outer `FROM` scope;
- no raw SQL fragment appears in the subquery.

Portability and budget:

- the identical shape **compiles under all five grammars** — SQLite, MySQL,
  PostgreSQL, Oracle and SQL Server — asserted by compiling the query against
  each grammar, with no driver-specific branch in the builder;
- revalidation adds **no statements**: the budget in section 6.3 is unchanged
  at 2 / 3 / `2 + ceil(roles / 900)` / 0 warm.

**Cross-process memo boundary (section 7.3.1)**

- a mutation committed in the **same** scope flushes that scope's memo, and a
  subsequent check sees the new state with no stale interval;
- a `MutationCommitted` dispatched for a mutation performed against a **second**
  container/scope does not clear the first scope's memo, asserted by building
  two scopes in one test process;
- a foreign-scope memo entry does not outlive its whole-second key or its
  scope, whichever ends first;
- no broadcast, queued or pub/sub listener is registered by Phase 5.

**Value objects**

- both expose `id`, `name` and `guardName` with identical shape;
- entries are `final readonly`;
- each call returns a distinct `Collection` instance;
- neither resolver declares `EffectivePermission` or `EffectiveRole` in any
  input union, asserted by reflecting over the contract signatures;
- both constructors are **private**, asserted by reflection;
- the named factory carries `@internal`, asserted by reading its docblock
  through reflection;
- no public consumer factory exists on either class, asserted by enumerating
  public static methods and allowing only the `@internal` one.

**Batch-check boundary (constrains Phase 6; asserted at the resolver here)**

- resolving one effective collection and testing five names executes the cold
  budget once and nothing further;
- an evaluation-second boundary crossed between two separate `allows()` calls
  produces two snapshots — the behaviour section 11.2 forbids an adapter from
  relying on.

**Ordering and determinism**

- ascending by name under binary comparison, with names differing only by case
  and by non-ASCII bytes;
- independent of insertion order, role order and primary-key order;
- two evaluations of unchanged data produce identical collections.

**Portability**

Per the section 12 matrix.

- the full behavioural matrix passes on SQLite;
- live MySQL resolver integration passes on all four lanes;
- targeted live Oracle resolver integration passes through the existing
  cost-controlled harness;
- PostgreSQL and SQL Server query compilation and schema-adapter inspection
  pass, with **no live-runtime claim recorded**;
- exact result parity between SQLite and every live engine exercised;
- no resolver query uses `ORDER BY` on a text column, `DISTINCT`, a CTE, a
  window function or an engine-specific date function, asserted from captured
  SQL.

## 18. Specification alignment at acceptance

**Applied on 2026-08-13, in the same increment that accepted this ADR.** The
list below is the record of what changed in `PACKAGE_SPEC.md` and `ROADMAP.md`,
not a list of pending work. Nothing in it claims any resolver code exists.

`PACKAGE_SPEC.md`:

- replaced the illustrative section 11.3 `PermissionResolver` contract with the
  accepted signature, changing `mixed $subject` to `Model $subject` and the
  bare `Collection` to `Collection<int, EffectivePermission>`;
- replaced the illustrative section 11.5 `RoleResolver` contract on the same
  terms, with `Collection<int, EffectiveRole>`;
- added a section 11 subsection naming `EffectiveAccessSnapshot` as the single
  internal source of truth and both contracts as thin projections over it;
- recorded the ordering, uniqueness and collection-mutation-safety rules;
- recorded the value-object shape, its **package-controlled construction**, and
  that no public consumer factory is promised;
- recorded the subject-validity rules, the shared `SubjectIdentityResolver`,
  and `InvalidSubject`'s safe fields, alongside the existing section 15 entry;
- recorded in section 11.2 that a check issues no separate definition-lookup
  query, that the caller's requested input is never bound into a statement, and
  that a cold check builds the snapshot while a warm one performs zero queries;
- recorded that definition lifecycle and guard filtering are explicit
  predicates on the joined definition table;
- recorded that a definition-model input is honoured only when it is an
  instance of the configured model class or a subclass, and that an unrelated
  model denies rather than throwing;
- stated the parity claim as per-snapshot algorithmic parity;
- recorded the query-budget model, including the 900-identifier chunk bound;
- recorded that role-derived permission resolution revalidates the full path
  through a correlated `EXISTS` and treats the role identifiers from the first
  query as candidates only;
- recorded that subject identifiers must canonicalize to an unsigned decimal
  integer in the initial runtime, and that UUID/ULID support remains a future
  coordinated design rather than a migration-publishing exercise;
- recorded that `EffectivePermission` and `EffectiveRole` are output-only and
  appear in no resolver input union;
- added the transaction and concurrent-read semantics of section 9, including
  the explicit non-claim of linearizable authorization and the same-process
  boundary of section 7.3.1;
- added the failure taxonomy of section 10, including the internal-invariant
  rule that such failures raise rather than deny, with the final exception
  boundary decided before the API freeze;
- clarified section 14.2 item 1 so "request-level memoization" states its key,
  its bound, its scope lifecycle and its flush rule — without implying anything
  about items 2 and 3, which remain undesigned;
- extended sections 20.1 and 20.2 with the section 17 matrix, and section 20.5
  with the proportional live-evidence matrix of section 12;
- added ADR 0012 to the section 24 accepted-decision list.

`ROADMAP.md`:

- corrected the Phase 5 deliverable list to the ten items in section 2.4,
  keeping the shared snapshot and subject-identity components uncounted as
  shared infrastructure;
- moved ADR 0012 from the proposed table to the accepted table;
- left Phase 5 at **0% / Not started**, Phase 4 at 19/21, the deliverable
  tracker at 11/32 and overall progress at approximately 34%.

**Phase 5 is recalculated from the corrected denominator only when work is
actually completed.** Ten unchecked items over ten is still zero.

## 19. Decision disposition

All ten review questions raised by the proposal are **resolved**. Maintainer
approval is not pending on any of them.

Each entry records the accepted choice, why it was chosen, and the primary
alternative that was rejected. Where the accepted choice differs from the
proposal's recommendation, that is stated explicitly.

### 19.1 Public subject type

- **Accepted:** the public subject type is
  `Illuminate\Database\Eloquent\Model`.
- **Rationale:** `SubjectGuardResolver::resolve()` already requires a `Model`,
  so a wider type would narrow immediately anyway. A concrete type lets PHPStan
  at level max verify call sites and keeps unsupported-subject handling in one
  place instead of repeating a runtime check in five Phase 6 adapters.
- **Rejected alternative:** `mixed`, matching the specification's illustrative
  contract. Section 1.2 records the full comparison.

### 19.2 Effective collection element type

- **Accepted:** immutable `EffectivePermission` and `EffectiveRole` value
  objects, each carrying `id`, `name` and `guardName`.
- **Rationale:** they are safe to hand out, cannot alias snapshot state, and
  carry exactly the fields a consumer needs to correlate a result with a
  definition row.
- **Rejected alternative:** configured Eloquent models, which are mutable,
  carry a live connection, and let `$permission->name = 'x'` corrupt a memoized
  snapshot while lazy relations reintroduce N+1 from consumer code. Section 1.4
  records the full four-way comparison.

### 19.3 Value-object construction

- **Accepted:** construction is **package-controlled**. Private constructors,
  an `@internal` named factory used by the package, and no public consumer
  factory.
- **This reverses the proposal's recommendation.** The draft recommended
  supported public construction; that recommendation is rejected.
- **Rationale:** consumers have no legitimate need to construct an
  authorization result, and a supported constructor would freeze the parameter
  list as compatibility surface from `1.0.0`. Keeping construction internal
  preserves headroom for a future field, and matches `MutationCommitted`, which
  already states that consumers "receive instances and never construct them".
- **Rejected alternative:** public constructors, justified by fixture
  convenience. Fixture convenience is served by package test builders and by
  exercising real resolver output, neither of which requires public API.
  Section 1.4 records the visibility mechanics, including why a technically
  callable `@internal` factory is still not supported consumer construction.

### 19.4 Phase 5 deliverable count

- **Accepted:** Phase 5's roadmap list changes from eight items to the ten in
  section 2.4, adding `RoleResolver` and the effective-role collection.
  `EffectiveAccessSnapshot`, `EffectiveAccessResolver` and
  `SubjectIdentityResolver` stay uncounted as shared infrastructure.
- **Rationale:** `PACKAGE_SPEC.md` already defines both resolvers, and the role
  resolver is computed by Phase 5 as an intermediate result. The eight-item
  list was a roadmap defect, not a scope decision.
- **Rejected alternative:** leave eight items and treat `RoleResolver` as Phase
  6 work, which would place a core authorization contract in the phase whose
  objective forbids introducing resolution logic.
- **Effect on progress:** none. The denominator moves from eight to ten while
  the numerator stays at zero. Phase 5 remains **0% / Not started**.

### 19.5 Query architecture

- **Accepted:** the common-path two/three-query architecture — two queries when
  the subject holds no effective role, three when it does — with explicit
  `deleted_at` and `guard_name` predicates on every joined definition table.
- **Rationale:** lifecycle filtering becomes structurally safe rather than
  test-enforced, and the joined-predicate form is the only one that survives
  the `withTrashed()` relations and the root-only global-scope behaviour
  documented in section 5.3.
- **Rejected alternative:** the five-query split-root design of section 14,
  which costs two extra round trips per cold authorization for no correctness
  gain.

### 19.6 Role-ID chunk size

- **Accepted:** a fixed 900-identifier chunk on every engine, with no driver
  branching.
- **Rationale:** it sits under Oracle's `ORA-01795` 1000-expression limit and
  well under SQL Server's 2100-parameter limit, so one constant is correct
  everywhere and the query-budget model stays engine-independent.
- **Rejected alternative:** a per-engine or configurable bound, which would
  make budget tests engine-dependent and turn a tuning constant into public
  configuration surface that must be validated and supported from `0.1.0`.

### 19.7 Request-memo invalidation granularity

- **Accepted:** any `MutationCommitted` observed in the current process clears
  the whole request-local memo.
- **Rationale:** definition-level mutations carry no subject, so there is no
  key to invalidate against. Wholesale clearing is correct for all fourteen
  mutations; targeted clearing would be silently wrong for eight of them.
- **Rejected alternative:** targeted invalidation by subject identity.

### 19.8 Caller-owned transactions

- **Accepted:** while a caller-owned transaction is active, the resolver
  bypasses memoization entirely and never stores a transaction-local snapshot.
- **Rationale:** `MutationCommitted` fires only after a successful outermost
  commit, so a rollback produces no event. Memoizing inside a transaction would
  leave authorization derived from discarded writes in the memo with nothing to
  flush it.
- **Rejected alternative:** memoize inside the transaction and flush on
  rollback, which requires a rollback signal the package does not emit.

### 19.9 Shared subject identity

- **Accepted:** one `SubjectIdentityResolver`, built in Increment 1, shared by
  authorization reads and by the subject-assignment mutation handlers. Dirty
  keys are rejected on both paths.
- **Rationale:** `AssignRoleHandler` and `GrantDirectPermissionHandler`
  currently carry two copies of `subjectIdentity()` with *different* validation
  — one rejects an empty morph class, the other does not. Adding a third rule
  in the resolver would let a read and a write disagree about who the subject
  is.
- **Rejected alternative:** leave the handler copies alone and give the
  resolver its own rule, preserving and extending the existing drift.

### 19.10 Database evidence matrix

- **Accepted:** proportional evidence — full SQLite behavioural coverage, the
  live MySQL four-lane matrix, targeted live Oracle, and compilation plus
  adapter inspection for PostgreSQL and SQL Server until live harnesses exist.
- **Rationale:** it matches evidence to risk. The engine-specific behaviour
  here is join qualification, chunk bounds and correlated-`EXISTS`
  compilation — all of which compilation inspection can pin — while the
  behavioural risk concentrates in the engines that already have harnesses.
- **Rejected alternative:** requiring live PostgreSQL and SQL Server harnesses
  first, which blocks Phase 5 on infrastructure the project does not have; or
  accepting SQLite alone, which would claim multi-engine portability with no
  evidence for the parts that actually differ.
- **Standing limit:** PostgreSQL and SQL Server carry **compilation evidence
  only**. No behavioural claim may be made for them until a live harness
  exists, and section 12.1 states that limit in the specification's terms.

## References

- [GateTrail ADR 0001: unique active assignments](0001-unique-active-assignments.md)
- [GateTrail ADR 0002: public mutation API](0002-public-mutation-api.md)
- [GateTrail ADR 0003: subject guard resolution](0003-subject-guard-resolution.md)
- [GateTrail ADR 0004: middleware any/all syntax](0004-middleware-any-all-syntax.md)
- [GateTrail ADR 0007: Laravel Gate hook boundary](0007-laravel-gate-hook-boundary.md)
- [GateTrail ADR 0008: audit snapshots and failure modes](0008-audit-snapshot-and-failure-modes.md)
- [GateTrail ADR 0010: after-commit mutation events](0010-after-commit-mutation-events.md)
- [GateTrail ADR 0011: bounded concurrency retry](0011-bounded-concurrency-retry.md)
- [PSR-20: clock](https://www.php-fig.org/psr/psr-20/)
- [Laravel: authorization](https://laravel.com/docs/13.x/authorization)
- [Laravel: polymorphic relationships and morph maps](https://laravel.com/docs/13.x/eloquent-relationships#custom-polymorphic-types)

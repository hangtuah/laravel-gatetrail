# GateTrail Independent Specification Review — Response to Brief `0001`

## 1. Reviewer and date

- **Reviewer:** Claude (Fable 5), independently prompted review tool; did not
  author any reviewed decision.
- **Review date:** 2026-07-28
- **Commit reviewed:** `df60a9c5b6f6e41436c1b18901cf70b334d45066`
- **Limitations:** Remote CI evidence (GitHub Actions runs `CI #25`,
  `Oracle compatibility #1`, run `#15`) is cited from repository documents and
  workflow configuration; the runs themselves were not independently accessed.
  Live MySQL/Oracle evidence is accepted as documented in `ROADMAP.md` §10 and
  the brief's evidence boundary, cross-checked against
  `.github/workflows/ci.yml`, `.github/workflows/oracle-ci.yml`, and committed
  DDL artifacts. All repository files listed in the brief's reading order were
  available and read in full.

## 2. Final verdict

**Accept with changes.**

No Critical finding exists. Two High findings concern one area — the Laravel
Gate hook contract — and are resolvable with small, additive contract
clarifications rather than redesign. The specification is otherwise internally
consistent, unusually explicit about its evidence boundary, and close to
freezable.

## 3. Executive summary

1. Fourteen of fifteen authorization scenarios (A01–A15) resolve
   deterministically to the brief's expected interpretation with clear
   controlling sections; A10 matches through the resolver but carries a
   Gate-hook caveat (F-02).
2. The core time semantics — `starts_at <= T`, exclusive `expires_at > T`,
   half-open overlap intervals, adjacent-boundary behavior — are unambiguous
   and consistent across spec §9.3, ADR 0001, ERD §5, and the shipped `CHECK`
   constraints.
3. The single genuinely security-relevant gap is the Gate hook: its matching
   rule for abilities invoked with arguments (policy collisions) and for
   deactivated permission definitions is underspecified, and one reading
   permits access through an unrelated Laravel Gate by fallback (F-01, F-02).
4. Spec §9.3 and ADR 0001 disagree on what a `NULL` `starts_at` means
   (always-started vs. starts at `assigned_at`) — harmless at current time,
   divergent for independent implementations (F-03).
5. Role-check semantics (`hasRole`, role middleware's "role resolver") are
   defined only inside ADR 0004; the spec's resolver section is
   permission-only (F-04).
6. Definition `update()` is accepted API (ADR 0002) but its mutable field
   set — especially rename and guard immutability — is unspecified (F-05);
   ADR 0001 mentions "rescheduling" that no public API, audit event, or
   outcome can express (F-06).
7. Documentation discipline on evidence is excellent: MySQL 8.0.16/8.0.45 and
   Oracle Free 23.26.1 claims match committed workflows and artifacts; Oracle
   minimum-version, PostgreSQL, and SQL Server are correctly flagged as
   unverified, and no document overclaims them.
8. Package-managed and DBA-managed provisioning share one manifest with
   byte-for-byte reproducibility requirements; the committed MySQL DDL matches
   the constraint plan exactly (verified against `install.sql`).
9. O01 (audit snapshot content) genuinely blocks the first vertical slice —
   the slice requires audit records, and the audit writer cannot be
   implemented without the snapshot contract. O02 does not block the slice.
10. Two independent developers would today reach the same result for every
    documented *authorization* scenario; they would diverge on the Gate hook
    edge cases and on several mutation-lifecycle details listed below.

## 4. Authorization scenario results (A01–A15)

| ID | Result | Controlling section | Agrees with expected |
|---|---|---|---|
| A01 | Deny | `PACKAGE_SPEC.md` §5.2 (default deny) | Yes |
| A02 | Allow | `PACKAGE_SPEC.md` §5.1 (effective-permission formula) | Yes |
| A03 | Allow | `PACKAGE_SPEC.md` §5.1 + §9.1 (active role path) | Yes |
| A04 | Allow | `PACKAGE_SPEC.md` §5.5 (multiple sources) | Yes |
| A05 | Deny | `PACKAGE_SPEC.md` §9.3 (`revoked_at IS NULL` fails), §9.5 | Yes |
| A06 | Deny | `PACKAGE_SPEC.md` §9.3 (`starts_at <= T` fails) | Yes |
| A07 | Deny | `PACKAGE_SPEC.md` §9.3 ("Expiry is exclusive. An assignment with `expires_at = T` is not active at `T`.") | Yes |
| A08 | Deny | `PACKAGE_SPEC.md` §9.1 ("Deactivated roles do not grant permissions"), resolver step 6 §11.2 | Yes |
| A09 | Allow | `PACKAGE_SPEC.md` §9.1 ("An assignment that remains otherwise active may become effective again when its role or permission is restored") | Yes |
| A10 | Deny | `PACKAGE_SPEC.md` §9.1 ("Deactivated permissions cannot be granted or allowed"), resolver step 3 §11.2. **Caveat:** deny is unambiguous through the resolver and middleware; through the Gate hook the "match" rule is ambiguous — see F-02 | Yes (with caveat) |
| A11 | Mutation: reject with `GuardMismatch` (ADR 0003 "Assignment behavior"). Name-based check: Deny (ADR 0003 "Authorization behavior": "checks using a name do not search other guards and therefore deny") | ADR 0003 | Yes |
| A12 | Evaluate only the supplied subject | `PACKAGE_SPEC.md` §5.6; ADR 0003 "Laravel fallback" (no `Auth::user()` inspection) | Yes |
| A13 | Do not allow through the unrelated Gate | `PACKAGE_SPEC.md` §12.5 ("Permission middleware uses GateTrail's resolver directly"); ADR 0004 "Evaluation semantics" | Yes |
| A14 | Return `null` from the hook | `PACKAGE_SPEC.md` §12.3 ("when no GateTrail permission matches the ability, return `null`") — unambiguous for a name that was never defined; ambiguous only for deactivated definitions (F-02) | Yes (with caveat) |
| A15 | At most one succeeds | `PACKAGE_SPEC.md` §9.7; ADR 0001 "Mutation algorithm" and "Idempotency and conflicts" | Yes |

No scenario result disagrees with the brief's expected interpretation.

## 5. Findings ordered by severity

```text
Finding ID: F-01
Severity: High
Area: Laravel Gate integration — hook mechanism and ability arguments
Claim: The Gate hook contract does not specify its registration mechanism
(before-hook vs. after-hook vs. ability definition), its precedence when a
GateTrail permission and an application Gate/policy share a name, or its
behavior when an ability is invoked with arguments (e.g.
$user->can('update', $post) against a model policy).
Evidence: PACKAGE_SPEC.md §12.3 ("integrates through a centralized Laravel
Gate hook"; "when an ability matches an active GateTrail permission, return
the resolver's boolean decision") and §12.4; ADR 0004 covers middleware only.
No document addresses abilities with arguments or hook ordering.
Impact: Laravel policies conventionally use short ability names ('view',
'update'). If the hook matches by name alone and runs as Gate::before, a
GateTrail permission named 'update' would preempt every model policy 'update'
check — returning true grants access globally where the policy would have
applied per-resource rules (unauthorized access), and returning false hard-
denies abilities the policy would allow. If implemented as an after-hook
instead, unrelated application Gates win, contradicting the "one source of
truth" principle. Two independent developers will produce incompatible — and
in one direction unsafe — implementations.
Recommendation: Add to §12.3: (a) the hook registration mechanism and that
its non-null return is authoritative for matching abilities; (b) whether
abilities invoked with additional arguments are excluded from GateTrail
matching (recommended: return null when arguments are present, leaving
resource checks to policies, which also preserves the §12.4 coexistence
example); (c) a required security regression test for a policy ability name
colliding with a GateTrail permission name.
Blocks first vertical slice: Yes
```

```text
Finding ID: F-02
Severity: High
Area: Laravel Gate integration — deactivated definitions and null fallback
Claim: For a permission definition that exists but is deactivated, §12.3's
two rules ("matches an active GateTrail permission" → resolver decision; "no
GateTrail permission matches" → null) leave the hook result undefined, and
the null reading grants access by fallback.
Evidence: PACKAGE_SPEC.md §12.3 vs. §9.1 ("Deactivated permissions cannot be
granted or allowed") and §19 (independence from unrelated Gates is required
only for middleware). Review brief "Authorization semantics" question 3.
Impact: If deactivation makes the hook return null, an unrelated application
Gate or policy with the same name silently regains control the moment an
administrator deactivates a GateTrail permission — the opposite of the
suspension intent in §9.1. A10 remains Deny through the resolver, but the
documented Gate path can allow. Alternatively, developers who treat
deactivated rows as a "match" produce a different (safe) result — an
incompatible implementation either way.
Recommendation: One sentence in §12.3: a permission name that exists in the
subject's resolved guard — active or deactivated — is a match, and the hook
returns the resolver's decision (deny for deactivated); only a name with no
definition record returns null. Add a security regression test.
Blocks first vertical slice: No
```

```text
Finding ID: F-03
Severity: Medium
Area: Time semantics — NULL starts_at
Claim: The active-time rule and the effective-period rule disagree about a
NULL starts_at: §9.3 treats it as unconditionally started, while ADR 0001 and
the constraint plan treat it as starting at assigned_at.
Evidence: PACKAGE_SPEC.md §9.3 ("starts_at IS NULL OR starts_at <= T");
ADR 0001 "Assignment periods" ("[starts_at ?? assigned_at, expires_at ??
+infinity)"); docs/architecture/constraints-and-indexes.md §5 ("starts_at is
nullable and means the assignment starts at assigned_at") and the shipped
CHECK (expires_at > COALESCE(starts_at, assigned_at)). PACKAGE_SPEC.md §9.4
also omits the assigned_at comparison that the constraint plan enforces.
Impact: At current-time evaluation the results coincide (assigned_at is never
in the future under the package clock), but independent implementations of
the resolver, gatetrail:doctor overlap detection, and any future tooling can
legitimately differ at the edges (cross-server clock skew, imported data,
doctor comparing resolver-active rows against ADR-0001 periods).
Recommendation: Amend §9.3 to use COALESCE(starts_at, assigned_at) <= T (or
explicitly state that the resolver deliberately ignores assigned_at and that
only ADR 0001 overlap checks use the coalesced start), and align §9.4 with
the constraint plan's expires_at > COALESCE(starts_at, assigned_at).
Blocks first vertical slice: No
```

```text
Finding ID: F-04
Severity: Medium
Area: Role-check semantics outside middleware
Claim: The role-check contract (hasRole, hasAnyRole, hasAllRoles, and the
"role resolver" ADR 0004 references) is not defined in the specification's
source-of-truth section, which covers permissions only.
Evidence: PACKAGE_SPEC.md §11.1/§11.3 (PermissionResolver only), §12.2
(methods proposed without semantics); ADR 0004 "Role syntax" defines "active
role" for middleware only ("active, non-revoked, non-expired subject-role
assignment pointing to an active role definition in the subject's resolved
guard").
Impact: Whether $user->hasRole('editor') is true when the role definition is
deactivated but the assignment is active is answerable only by transferring
middleware wording. Subject API, middleware, and future role Blade directives
could diverge — exactly the multi-adapter divergence the charter's "one
authorization source of truth" principle prohibits.
Recommendation: Add one paragraph to §11 or §12.2 adopting ADR 0004's active-
role definition as the single role-check rule for every adapter, and name the
contract that owns it.
Blocks first vertical slice: No
```

```text
Finding ID: F-05
Severity: Medium
Area: Definition update semantics
Claim: update() is accepted public API but the mutable field set is
undefined: whether name can be changed, whether guard_name is immutable, and
what RoleChanges/PermissionChanges may carry are unspecified.
Evidence: ADR 0002 "Role manager"/"Permission manager" (update signatures;
"RoleChanges is used only for update operations" defines patch semantics but
not fields); PACKAGE_SPEC.md §10.1 ("updating" listed), §13.1
(role.updated/permission.updated events exist).
Impact: Renaming a permission silently changes which ability strings resolve
everywhere (a security-relevant mutation); changing guard_name would orphan
or re-scope existing assignments in ways no document permits or forbids. Two
implementations will disagree on what update() accepts.
Recommendation: Enumerate updatable fields (recommended: description and
future metadata only for 0.1.0; declare name and guard_name immutable through
update, with rename deferred to a future decision covering cache
invalidation and audit representation).
Blocks first vertical slice: No
```

```text
Finding ID: F-06
Severity: Medium
Area: Assignment rescheduling scope
Claim: ADR 0001 treats "rescheduling" and "changing an existing period" as
managed mutations, but no public manager method, audit event, or mutation
outcome can express them, and §10.1 does not list them.
Evidence: ADR 0001 "Mutation algorithm" ("Every managed assignment,
scheduling, rescheduling, or revocation operation") and "Idempotency and
conflicts" ("Changing an existing period is a managed mutation"); ADR 0002
AssignmentManager (no reschedule method); PACKAGE_SPEC.md §10.1, §13.1,
§10.7.
Impact: A developer implementing ADR 0001 literally will invent an
unspecified public path (or an internal one without an audit event) for
period changes; another will treat period change as out of scope and require
revoke-plus-reassign. Incompatible surface and audit trails.
Recommendation: One sentence in §10.1 (and ADR 0001 cross-reference):
changing an existing assignment period is outside the initial public API;
consumers revoke and create a new assignment. Retain ADR 0001's wording as
applying to any future rescheduling feature.
Blocks first vertical slice: No
```

```text
Finding ID: F-07
Severity: Medium
Area: Creation colliding with a deactivated definition
Claim: The behavioral specification does not state that a deactivated
definition's name still blocks creation of a new definition with the same
(name, guard_name), although the constraint plan enforces exactly that.
Evidence: docs/architecture/constraints-and-indexes.md §3 ("Soft-deleted rows
remain inside the unique namespace..."); PACKAGE_SPEC.md §5.7/§5.8 ("unique
within a guard"), §10.5 ("Repeating role or permission creation with an
existing name throws a duplicate exception" — "existing" undefined for
deactivated rows), §9.1.
Impact: Laravel's default soft-delete-scoped uniqueness validation excludes
trashed rows; a developer following framework habit will let creation reach
the database constraint and surface a raw constraint violation instead of
DuplicateRole/DuplicatePermission, or worse, implement restore-on-create.
Recommendation: Add to §10.5: name uniqueness includes deactivated
definitions; creating over a deactivated name throws Duplicate*; restore is
the only path back to active for a deactivated definition.
Blocks first vertical slice: No
```

```text
Finding ID: F-08
Severity: Low
Area: Check-time explicit references from another guard
Claim: For authorization checks (not mutations) called with a Permission
model, ID, or explicit reference belonging to another guard, deny-vs-throw is
not stated; ADR 0003 assigns GuardMismatch to mutation operations and quiet
deny to name-based checks only.
Evidence: ADR 0003 "Authorization behavior"; PACKAGE_SPEC.md §11.2/§11.3
(allows() accepts Permission|string|int).
Impact: Minor divergence: one implementation throws GuardMismatch, another
denies via resolver step 3 ("find an active permission in that guard").
Recommendation: One sentence: non-name check inputs resolving outside the
subject's guard deny (consistent with name behavior), or throw — pick one.
Blocks first vertical slice: No
```

```text
Finding ID: F-09
Severity: Low
Area: Audit configuration failure modes
Claim: Behavior when strict audit mode is disabled (audit write fails but the
mutation commits — logged? silent?) and when audit is disabled entirely
('enabled' => false) is undefined, including the effect on
MutationResult.auditId and on the vertical-slice guarantee that "audit
records contain actor and reason".
Evidence: PACKAGE_SPEC.md §13.5 (config keys shown without semantics for the
false cases), §10.3 (defines only the strict path), §10.7 ("audit identifier,
when an audit record was created").
Impact: Security-sensitive failure behavior is left to implementation
preference, which §19 and the brief's audit questions explicitly disallow.
Recommendation: Two sentences: non-strict audit failure commits the mutation
and emits a documented error log/event with auditId null; 'enabled' => false
skips audit writes entirely, returns auditId null, and is documented as
weakening the charter's history guarantee.
Blocks first vertical slice: No
```

```text
Finding ID: F-10
Severity: Low
Area: Terminology consistency
Claim: The table overview describes the role/permission lifecycle as "Active,
soft-deleted, or restored", using the storage mechanism where §4 mandates the
deactivate/restore vocabulary for documentation.
Evidence: PACKAGE_SPEC.md §2.1 (lifecycle column) vs. §4 (the
deactivate/restore terminology rule); §9.1 correctly uses "Deactivated".
Impact: Editorial only; a reader skimming §2.1 could conflate deactivation
with deletion.
Recommendation: Change §2.1 to "Active, deactivated, or restored (soft
deletion is the storage mechanism)".
Blocks first vertical slice: No
```

```text
Finding ID: F-11
Severity: Low
Area: Manager metadata destination
Claim: The array $metadata parameter accepted by every manager method has no
specified persistence destination, structure, or size limit.
Evidence: ADR 0002 "Actor and metadata" ("metadata is optional and subject to
audit redaction and size limits" — limits undefined); PACKAGE_SPEC.md §8.7
(no metadata column; presumably context).
Impact: Minor: implementations may persist metadata under different context
keys or drop it, breaking audit consumers.
Recommendation: Resolve inside O01: metadata persists under a fixed context
key after sensitive-field filtering, with a documented size cap.
Blocks first vertical slice: No
```

**Areas with no findings** (reviewed and explicitly cleared):

- **Time boundaries at instants:** `starts_at <= T` inclusive /
  `expires_at > T` exclusive and adjacent-period behavior are consistent
  everywhere they appear (spec §9.3, ADR 0001, ERD §5). No double-grant or gap
  at a shared boundary.
- **Guard resolution:** spec §6 and ADR 0003 are word-for-word aligned on
  order, normalization, failure behavior, and determinism across
  HTTP/queue/CLI/tests. No conflict.
- **Actor representation:** ADR 0005, the constraint plan, and the ERD agree
  on 191-character portable strings, the reserved `gatetrail.system` type, and
  the all-null/all-non-null revocation tuple. No conflict.
- **Concurrency design (A15):** ADR 0001's coordination-row algorithm, retry
  bounds, fail-closed hash collision, and lexicographic multi-scope ordering
  are internally consistent and consistent with spec §8.8/§9.7.
- **Middleware syntax:** ADR 0004 and spec §12.5 agree on aliases,
  cardinality, normalization, missing-definition non-disclosure, and exception
  mapping. The comma-in-names limitation is honestly documented.
- **Physical schema consistency:** The committed MySQL `0001` DDL matches the
  constraint plan exactly (verified: `acl_ra_period_ck`, `acl_ra_revoke_ck`,
  `acl_audit_subject_ck`, index column orders, `CHAR`-length hash, binary
  collation on exact-match columns). The ERD, constraint plan, and spec §8
  column lists agree.
- **Non-goal leakage:** No document introduces role hierarchy, wildcards,
  explicit deny, tenancy, or a super-admin path. ADR 0003's multi-guard
  discussion correctly defers to a future ADR.
- **Evidence honesty:** Roadmap §10, spec §20.5, and the integration READMEs
  consistently distinguish advertised compatibility from verified evidence.
  Oracle minimum version, PostgreSQL, and SQL Server are nowhere claimed
  verified. The `.github/workflows` files match the claimed matrices (MySQL
  8.0.16/8.0.45 × Laravel 12/13; Oracle Free 23.26.1-slim-faststart with both
  OCI8 majors). Only `database/ddl/mysql` and `database/ddl/oracle` exist,
  consistent with disclosed status.

## 6. Priority decision O01 recommendation — Minimum audit snapshot content

**Governing principle:** snapshots contain **package-owned columns only, never
application-model attributes**. This makes sensitive-data exclusion structural
rather than filter-dependent: passwords, tokens, and application fields can
never enter `before`/`after` because subjects and actors are represented
solely by their morph pairs (already dedicated columns). The configured
`sensitive_fields` filter then only needs to police consumer-supplied
`metadata`.

**Event-by-event mandatory content:**

| Event group | `before` | `after` |
|---|---|---|
| `*.created` | `null` | `id`, `name`, `guard_name`, `description` |
| `*.updated` | changed fields only, prior values, plus `id` | the same keys, new values |
| `*.deactivated` / `*.restored` | `id`, `name`, `guard_name`, `deleted_at` (prior) | same keys, new `deleted_at` |
| `role.assigned`, `permission.granted_to_*` | `null` | `id`, definition FK(s), `subject_type`/`subject_id` where applicable, `starts_at`, `expires_at`, `assigned_at` |
| `*.revoked*` | the assignment's pre-revocation snapshot (same keys as assign, `revoked_at: null`) | same keys with `revoked_at` set |

**`context` mandatory content:** a format version key (recommend `"_v": 1`),
the resolved `guard_name`, the **denormalized natural identity of every
referenced definition at event time** (`role_name`, `permission_name`), and
consumer `metadata` under a fixed `metadata` key after filtering. The
denormalized names are the piece that satisfies the brief's "explain an access
change without replaying mutable rows": if a role is later renamed, the audit
row still states what was granted. Actor, subject, target, reason, IP, user
agent, and request ID already have dedicated columns and must not be
duplicated into `context`.

**Absent vs. redacted:** an absent key means not applicable or not captured; a
redacted value keeps its key with the reserved sentinel string
`"__gatetrail_redacted__"`. The two must never be conflated, so incident
analysis can distinguish "was never there" from "was removed by policy".
Redaction can only occur inside `context.metadata`, given the
package-columns-only rule.

**Stability:** snapshot structure is stable public data from `0.1.0`,
versioned by `_v`, with additive-only changes per version; a structural break
increments `_v`. This is required because audits outlive package upgrades.

This is the minimal set: identifiers plus the security-relevant fields that
changed, natural keys for durability, one version marker. Anything less cannot
explain a change; anything more (full-row dumps, request bodies) adds risk
without analytical value.

## 7. Priority decision O02 recommendation — Direct database mutation behavior

**Detection classification: best-effort, diagnostic-only — and the
specification should say so in those words.** No portable,
DBA-ownership-respecting mechanism can guarantee detection of schema-valid
direct writes. Any wording implying guaranteed detection would be a false
security claim; the brief is right to prohibit it.

Concretely:

1. **Runtime authorization must not attempt drift-based fail-closed behavior
   for data-level direct writes.** The resolver evaluates the rows that exist;
   it cannot distinguish a managed row from a schema-valid direct insert, and
   a heuristic that guesses (e.g., "no coordination row exists for this scope
   → deny") would turn an operational anomaly into a production outage and
   would itself be bypassable by inserting the coordination row. State
   explicitly: *authorization evaluates persisted state regardless of write
   origin; direct writes are unsupported and forfeit audit, coordination, and
   cache guarantees* (consistent with spec §10.4 and ADR 0001 "Direct database
   writes"). Keep fail-closed behavior where it already correctly lives:
   **schema-level** drift, where read-only inspection (spec §3.8) refuses to
   claim compatibility before the package operates.
2. **`gatetrail:doctor` scope (read-only, no data changes):** report
   (a) overlapping equivalent non-revoked assignments per ADR 0001's interval
   rule; (b) assignment scopes present in assignment tables with no
   coordination row, and coordination rows whose `scope_key` does not hash to
   `scope_hash`; (c) revocation-tuple and period-check violations on engines
   where a `CHECK` was absent or disabled; (d) assignment/definition guard
   mismatches (already specified, §18); (e) referenced definitions that are
   missing or deactivated. Doctor must state in its output that a clean report
   is *not* proof that no direct write occurred.
3. **Cache correctness without managed invalidation:** cross-request caching,
   when later enabled, must carry a mandatory bounded TTL cap (configurable,
   small — minutes not hours) in addition to §14.5's expiry rule, so unmanaged
   mutations converge within a documented staleness window; and
   `gatetrail:cache-clear` is the documented operational remediation after any
   known direct intervention. Until the cache ADR exists, keep cross-request
   caching off by default — request memoization cannot outlive a request and
   is inherently safe here.
4. **Runtime enforcement vs. policy:** enforcement = strict schema inspection
   before claiming compatibility, the coordination protocol for managed
   writes, and the TTL cap. Documentation/operational policy = grant the
   runtime account DML only; restrict human accounts on `acl_*` tables; route
   emergency fixes through managed services or record them via a documented
   break-glass procedure; schedule `gatetrail:doctor` in monitoring.
5. **Triggers and elevated privileges: reject.** Audit-on-write triggers or
   polling with elevated catalog rights would require the runtime or install
   path to own trigger DDL — directly violating DBA-managed ownership (§3.4:
   runtime account needs no create/alter/drop) — and would fork behavior
   across engines. At most, note that an *organization-owned* trigger regime
   is permitted as external defense-in-depth, never shipped, required, or
   relied upon by GateTrail.

## 8. Non-blocking observations

- `AssignmentManager::revokeRole()` requires an assignment reference; there is
  no `subject + role` convenience overload, so every revocation flow must
  first query assignments. Acceptable (it forces unambiguous targeting per
  ADR 0002), but expect pilot friction; note for the pilot feedback log.
- Whether `AssignmentPeriod::between()` accepts a `startsAt` in the past
  (backdating) is unspecified. Backdating interacts with ADR 0001's overlap
  rule against expired assignments; a one-line decision would help.
- `request_id` provenance (inbound header vs. package-generated) is
  unspecified in §13.4.
- Spec §3.4 requires "versioned install, upgrade, and rollback DDL" for four
  engines; only MySQL and Oracle release `0001` install/rollback artifacts
  exist, and no upgrade artifact policy exists yet (nothing to upgrade from).
  Fine now; define the upgrade-artifact convention before schema release
  `0002`.
- Oracle's advertised 12c Release 2 minimum has no evidence (only 23.26.1).
  Before public release, either obtain minimum-version evidence or narrow the
  advertised minimum — §3.2 explicitly permits narrowing.
- Cache (non-blocking per brief): keep cross-request caching disabled by
  default for `0.1.0`; decide store/TTL/fan-out only after the Phase 8
  benchmark, per the roadmap's own sequencing. Role-change fan-out (one role
  edit invalidating many subjects) is the hard case — measure before
  designing.
- UUID/ULID migration variants: defer to the pilot as planned; ADR 0005
  already makes actor columns representation-proof, and §7.2's
  publish-and-adjust path is an adequate interim answer.
- Role Blade directives: keep out of core initially; native `@can` through the
  Gate hook covers the need, and adding `@role` would require the F-04
  role-check contract first.
- `README.md` is two lines; adequate for a private pre-alpha, but it should
  gain the advertised-vs-verified compatibility disclaimer before the
  repository becomes public.

## 9. First vertical-slice blockers

1. **F-01** — the Gate hook matching/precedence contract (abilities with
   arguments, hook mechanism). The slice includes "Laravel Gate allows/denies
   the permission"; the hook cannot be implemented without this rule, and one
   available reading is unsafe. Smallest fix: the three-sentence addition to
   §12.3 recommended in F-01 (resolving F-02 in the same edit is nearly
   free).
2. **O01 — minimum audit snapshot content.** The slice requires "Audit records
   contain actor and reason" written in the mutation transaction; the audit
   writer cannot be built without the snapshot contract. Adopting the §6
   recommendation (or any equivalent decision recorded as an ADR) unblocks it.

O02 does **not** block the slice: the slice exercises only managed mutations.
It should still be recorded before Phase 7 completes, since its answer
(diagnostic-only, no runtime fail-closed on data drift) shapes
`gatetrail:doctor` and the cache ADR. All Medium findings (F-03 through F-07)
should be resolved before the specification status changes from Draft, but
none prevents a safe, spec-conformant first slice.

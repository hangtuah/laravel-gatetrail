# GateTrail Independent Specification Review Disposition

## Disposition status

| Item | Value |
|---|---|
| Review identifier | `0001` |
| Review brief | `docs/reviews/0001-specification-review-brief.md` |
| Review response | `docs/reviews/0001-specification-review-response.md` |
| Commit reviewed | `df60a9c5b6f6e41436c1b18901cf70b334d45066` |
| Reviewer verdict | Accept with changes |
| Maintainer disposition | Review accepted; remediation required |
| Specification status | Draft |
| Remediation status | In progress |

This document records the maintainer's response to the independent review. It
does not change the package contract by itself. A finding is resolved only
after its required specification or ADR change is accepted, implemented in the
authoritative document, and marked resolved here.

## Disposition vocabulary

| Decision | Meaning |
|---|---|
| Accepted | The finding is valid and the recommended contract direction is accepted |
| Accepted with modification | The finding is valid, but the maintainer changes part of the recommendation |
| Deferred | The finding is valid but does not need resolution in the current milestone |
| Rejected | The finding or recommendation is not accepted, with a recorded rationale |

Remediation statuses are:

```text
Open
In progress
Resolved
Deferred
```

## Summary

| Category | Count |
|---|---:|
| Critical findings | 0 |
| High findings | 2 |
| Medium findings | 5 |
| Low findings | 4 |
| Accepted findings | 10 |
| Accepted with modification | 1 |
| Rejected findings | 0 |

The independent review is complete and its feedback is recorded. Remediation
is not complete. The specification remains Draft.

No unresolved review finding or architecture decision currently blocks the
first vertical slice. O01, F-09, and F-11 are resolved by ADR 0008 and the
aligned audit contract in `PACKAGE_SPEC.md`.

F-01 and F-02 are resolved by ADR 0007 and the aligned Gate integration
contract in `PACKAGE_SPEC.md`. F-02 was not marked as a vertical-slice blocker
by the reviewer, but the maintainer resolved it with F-01 to avoid implementing
an incomplete Gate matching boundary.

## Finding dispositions

### F-01: Gate hook mechanism and ability arguments

| Item | Disposition |
|---|---|
| Severity | High |
| Decision | Accepted |
| Blocks first vertical slice | Yes |
| Remediation status | Resolved |
| Authoritative target | `PACKAGE_SPEC.md` section 12.3 and ADR 0007 |

The specification does not define hook registration, precedence, or matching
when Laravel passes ability arguments. This creates an unsafe interpretation in
which a broad GateTrail permission name such as `update` could pre-empt a
resource policy.

The remediation must:

- select and document the Laravel Gate hook mechanism;
- define when a GateTrail result is authoritative;
- keep resource-policy checks under consumer policy control;
- define behavior for abilities invoked with additional arguments;
- add collision and policy-coexistence security tests to the specification.

The preferred direction is for GateTrail's global permission hook to ignore
abilities with additional resource arguments. Consumer policies may call a
specific GateTrail permission without resource arguments as one part of their
policy decision.

Resolved by
[ADR 0007](../decisions/0007-laravel-gate-hook-boundary.md), which selects
`Gate::before`, excludes argument-bearing abilities, defines callback ordering,
and preserves application resource policies.

### F-02: Deactivated definitions and Gate fallback

| Item | Disposition |
|---|---|
| Severity | High |
| Decision | Accepted |
| Blocks first vertical slice | Yes, grouped with F-01 by the maintainer |
| Remediation status | Resolved |
| Authoritative target | `PACKAGE_SPEC.md` section 12.3 and ADR 0007 |

Deactivation must suspend the GateTrail permission rather than hand the same
ability name to an unrelated Laravel Gate through a `null` result.

The contract must distinguish:

- no definition exists in the resolved guard: GateTrail returns `null`;
- a definition exists and is active: GateTrail returns the resolver decision;
- a definition exists but is deactivated: GateTrail returns `false`.

A security regression test must cover a deactivated GateTrail definition that
shares its name with an unrelated Laravel Gate.

Resolved by
[ADR 0007](../decisions/0007-laravel-gate-hook-boundary.md), which makes an
existing deactivated definition an authoritative `false` result and reserves
`null` for an explicit non-match.

### F-03: Meaning of a null assignment start

| Item | Disposition |
|---|---|
| Severity | Medium |
| Decision | Accepted |
| Blocks first vertical slice | No |
| Remediation status | Open |
| Authoritative target | `PACKAGE_SPEC.md` sections 9.3 and 9.4 |

The package specification must align evaluation and validation with the
accepted half-open period:

```text
[starts_at ?? assigned_at, expires_at ?? +infinity)
```

The active-time formula must use `assigned_at` when `starts_at` is null.
Period validation must require `expires_at` to be later than
`starts_at ?? assigned_at`.

### F-04: One role-check source of truth

| Item | Disposition |
|---|---|
| Severity | Medium |
| Decision | Accepted |
| Blocks first vertical slice | No |
| Remediation status | Open |
| Authoritative target | `PACKAGE_SPEC.md` sections 11 and 12.2 |

Role checks need one documented rule shared by subject helpers and role
middleware. An active role requires:

- an active, non-revoked, non-expired subject-role assignment;
- an active role definition;
- compatibility with the subject's resolved guard.

The specification must identify the role-check contract that owns this rule.

### F-05: Mutable role and permission fields

| Item | Disposition |
|---|---|
| Severity | Medium |
| Decision | Accepted |
| Blocks first vertical slice | No |
| Remediation status | Open |
| Authoritative target | `PACKAGE_SPEC.md` section 10 and ADR 0002 |

The initial public update API must enumerate mutable fields. The preferred
initial contract permits updates to description and explicitly supported
metadata only. Names and `guard_name` remain immutable through the initial
`update()` operations.

Rename and guard migration require separate future decisions because they
affect ability names, assignments, audit snapshots, and cache invalidation.

### F-06: Assignment rescheduling

| Item | Disposition |
|---|---|
| Severity | Medium |
| Decision | Accepted with modification |
| Blocks first vertical slice | No |
| Remediation status | Open |
| Authoritative target | `PACKAGE_SPEC.md` section 10.1 and ADR 0001 |

The ambiguity is valid, but no rescheduling API will be added to the initial
release. Existing assignment periods are immutable. A consumer changes a
period by revoking the existing assignment and creating a new assignment.

ADR 0001 must stop describing rescheduling as an available managed operation.
Its coordination rules may be retained as requirements for any future
rescheduling feature.

### F-07: Creation over a deactivated definition

| Item | Disposition |
|---|---|
| Severity | Medium |
| Decision | Accepted |
| Blocks first vertical slice | No |
| Remediation status | Open |
| Authoritative target | `PACKAGE_SPEC.md` sections 9.1 and 10.5 |

Name uniqueness includes deactivated definitions. Creating a role or permission
over a deactivated `(name, guard_name)` pair throws the corresponding duplicate
domain exception. Restore is the only initial path that makes that definition
active again.

### F-08: Explicit permission references from another guard

| Item | Disposition |
|---|---|
| Severity | Low |
| Decision | Accepted |
| Blocks first vertical slice | No |
| Remediation status | Open |
| Authoritative target | `PACKAGE_SPEC.md` section 11.2 and ADR 0003 |

An authorization check using a permission model, identifier, or explicit
reference that resolves outside the subject's guard returns `false`. Mutation
operations remain different: they reject a cross-guard assignment with
`GuardMismatch`.

This keeps authorization checks non-disclosing and consistent with name-based
checks.

### F-09: Non-strict and disabled audit behavior

| Item | Disposition |
|---|---|
| Severity | Low |
| Decision | Accepted |
| Blocks first vertical slice | No |
| Remediation status | Resolved |
| Authoritative target | ADR 0008 and `PACKAGE_SPEC.md` sections 10.3, 10.7, and 13.6 |

The audit contract must define all configured modes:

- strict and enabled: an audit failure rolls back the mutation;
- non-strict and enabled: an audit failure permits commit, returns no audit
  identifier, and emits an observable operational failure signal;
- disabled: no audit write is attempted and the mutation result contains no
  audit identifier.

The exact logging or event contract for a non-strict failure must be decided in
the audit ADR. Documentation must identify non-strict and disabled modes as
weakening the package's history guarantee.

Resolved by
[ADR 0008](../decisions/0008-audit-snapshot-and-failure-modes.md). Strict mode
keeps mutation and audit atomic. Non-strict mode attempts audit persistence in
a separate post-commit transaction and reports failure through a safe
error-level log and `AuditPersistenceFailed` event. Disabled mode skips the
audit explicitly.

### F-10: Lifecycle terminology in the table overview

| Item | Disposition |
|---|---|
| Severity | Low |
| Decision | Accepted |
| Blocks first vertical slice | No |
| Remediation status | Open |
| Authoritative target | `PACKAGE_SPEC.md` section 2.1 |

The lifecycle description must use `active`, `deactivated`, and `restored`.
Soft deletion should be described as the persistence mechanism, not as the
public lifecycle term.

### F-11: Mutation metadata destination

| Item | Disposition |
|---|---|
| Severity | Low |
| Decision | Accepted |
| Blocks first vertical slice | No |
| Remediation status | Resolved |
| Authoritative target | ADR 0008 and ADR 0002 |

Consumer mutation metadata must have one documented destination inside audit
context, after sensitive-field filtering. The audit ADR must define:

- the fixed context key;
- accepted data shape;
- normalization and redaction;
- size and depth limits;
- behavior when the limits are exceeded.

Resolved by
[ADR 0008](../decisions/0008-audit-snapshot-and-failure-modes.md), which fixes
the destination at `context.metadata`, accepts JSON-safe values, defines
redaction, and sets finite default size and depth limits.

## Priority decision dispositions

### O01: Minimum audit snapshot content

| Item | Disposition |
|---|---|
| Decision | Accepted for an ADR, with refinement |
| Blocks first vertical slice | Yes |
| Remediation status | Resolved |
| Authoritative target | ADR 0008 |

The reviewer's package-columns-only principle, versioned snapshot structure,
explicit redaction marker, stable metadata destination, and denormalized
definition names are accepted as the design baseline.

The ADR must still decide:

- full security-relevant snapshots versus changed-field-only update snapshots;
- exact event-by-event `before`, `after`, and `context` schemas;
- whether assignment snapshots include actor fields already represented by
  dedicated audit columns;
- the version-key location and compatibility rules;
- metadata size, nesting, normalization, and rejection behavior;
- non-strict audit failure signaling.

The reviewer recommendation was input to the ADR rather than being adopted
verbatim.

Resolved by
[ADR 0008](../decisions/0008-audit-snapshot-and-failure-modes.md). The accepted
contract uses full security-relevant snapshots, versioned context, canonical
identifier and timestamp serialization, bounded metadata, and explicit
strict, non-strict, and disabled failure modes.

### O02: Direct database mutation behavior

| Item | Disposition |
|---|---|
| Decision | Accepted for an ADR, with modification |
| Blocks first vertical slice | No |
| Remediation status | Open |
| Authoritative target | New direct database mutation and diagnostics ADR |

GateTrail cannot portably guarantee detection of schema-valid writes that
bypass managed services. Detection will be documented as best-effort and
diagnostic-only. Direct writes forfeit audit, coordination, event, and cache
invalidation guarantees.

The accepted direction is:

- the resolver evaluates persisted state and does not guess write origin;
- schema drift remains a separate fail-closed compatibility concern;
- `gatetrail:doctor` performs read-only consistency diagnostics;
- a clean diagnostic report is not proof that no direct write occurred;
- known direct intervention requires explicit cache remediation;
- GateTrail does not require cross-database audit triggers or elevated runtime
  schema privileges.

The review recommendation is modified in one important respect:

- a missing referenced definition is an integrity issue;
- an assignment pointing to a deactivated definition is a valid,
  history-preserving lifecycle state and must not be reported as an error;
- diagnostics may report that state as informational context.

Cache TTL and role-change fan-out remain owned by the future cache ADR. This
disposition does not select a TTL before the uncached resolver is implemented
and measured.

## Non-blocking observations

The following reviewer observations are recorded for later milestones and are
not findings in the current remediation set:

- pilot feedback should evaluate assignment-reference-based revocation
  ergonomics;
- backdated assignment behavior needs a decision before scheduling is exposed;
- request ID provenance should be documented during audit implementation;
- upgrade-artifact conventions are required before schema release `0002`;
- the advertised minimum Oracle version still requires evidence or narrowing;
- cross-request caching should remain disabled until its correctness and
  invalidation strategy are accepted;
- UUID/ULID migration variants remain a pilot-driven decision;
- role-specific Blade directives should not precede the shared role-check
  contract;
- public-release documentation must distinguish advertised compatibility from
  verified evidence.

## Remediation order

Work should proceed in this order:

1. [Completed] Decide the Gate integration contract and resolve F-01 and F-02.
2. [Completed] Decide the audit snapshot and failure-mode contract, resolving
   O01, F-09, and F-11.
3. Apply the remaining specification clarifications for F-03 through F-08 and
   F-10.
4. Decide direct database mutation and diagnostic behavior for O02.
5. Recheck all A01-A15 scenarios against the amended authoritative documents.
6. Mark findings resolved only after their authoritative changes are accepted.
7. Evaluate whether the specification meets its freeze criteria.

## Completion criteria

Review remediation is complete when:

- every accepted finding is marked Resolved or explicitly Deferred with a
  milestone and rationale;
- O01 and O02 have accepted ADRs;
- no unresolved High finding remains;
- no first vertical-slice blocker remains;
- authorization scenarios A01-A15 remain deterministic;
- the roadmap and specification status reflect the resulting evidence.

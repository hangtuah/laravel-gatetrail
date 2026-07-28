# GateTrail Independent Specification Review Brief

## Review status

| Item | Value |
|---|---|
| Review identifier | `0001` |
| Review target | Charter, package specification, and accepted ADRs |
| Package status | Pre-alpha |
| Specification status | Draft |
| Brief status | Prepared; independent review not yet performed |
| Repository | `hangtuah/laravel-gatetrail` |

Creating this brief does not complete the independent review. The review is
complete only after an independent reviewer submits findings and the
maintainers resolve or explicitly record every finding.

## Purpose

The purpose of this review is to determine whether two independent developers
can read the GateTrail specification and reach the same authorization result,
mutation behavior, and lifecycle interpretation.

The review must identify contradictions, ambiguous contracts, missing
security-sensitive behavior, and decisions that block the first vertical
slice. It is not a request to redesign the package around a specific consumer
application.

## Reviewer independence

The reviewer should not have authored the reviewed decisions. A human
developer or an independently prompted review tool may perform the review.

The reviewer must:

- evaluate the repository as provided;
- distinguish specified behavior from implemented behavior;
- cite file names and sections for every finding;
- state assumptions instead of silently filling gaps;
- avoid editing repository files;
- avoid inferring features that are not documented.

## Package summary

GateTrail is a Laravel-native package for roles, permissions, and application
authorization. Its initial design includes:

- permissions inherited through roles;
- permissions assigned directly to subjects;
- default-deny authorization;
- history-preserving assignments and revocations;
- explicit human or system actors and mutation reasons;
- append-only access-mutation audits;
- Laravel Gate, middleware, and Blade integration;
- package-managed and DBA-managed database provisioning;
- portable schema contracts for supported database engines.

Authentication, application-specific policies, role hierarchy, wildcard
permissions, explicit deny rules, tenant scoping, and a super-administrator
bypass are outside the initial scope.

## Authority and reading order

Review the following documents in order:

1. `PROJECT_CHARTER.md` for product goals, principles, scope, and governance.
2. `PACKAGE_SPEC.md` for the current behavioral contract.
3. Accepted ADRs under `docs/decisions/` for detailed decisions that refine the
   specification:
   - `0001-unique-active-assignments.md`
   - `0002-public-mutation-api.md`
   - `0003-subject-guard-resolution.md`
   - `0004-middleware-any-all-syntax.md`
   - `0005-actor-identity-representation.md`
   - `0006-oracle-generated-key-strategy.md`
4. `docs/architecture/initial-erd.md` and
   `docs/architecture/constraints-and-indexes.md` for the physical data-model
   interpretation.
5. `ROADMAP.md` for progress and evidence status, not as a behavioral
   authority.

When documents appear to conflict, report the conflict. Do not silently choose
one interpretation. An accepted ADR is the authority for the decision it
explicitly covers; the package specification remains the broader behavioral
contract.

## Accepted invariants

Treat these as accepted constraints unless the repository contains
contradictory wording:

1. Access is denied unless an active source grants the permission.
2. Effective permissions are the union of active direct permissions and
   permissions inherited through active roles.
3. No explicit deny or implicit super-administrator behavior exists initially.
4. Revoking one source does not remove access granted by another active source.
5. Authorization evaluates the supplied subject, not the globally
   authenticated user.
6. Each subject resolves one stable authorization guard.
7. `revoke` applies to assignments; `deactivate` and `restore` apply to role or
   permission definitions.
8. Assignment history is retained, and reassignment creates a new record.
9. Equivalent assignments must not overlap in their effective periods.
10. Ordinary consumers use simple manager methods; commands remain internal.
11. Mutations require explicit actor handling and use one transaction for the
    state change and strict audit write.
12. GateTrail middleware evaluates GateTrail authorization independently from
    unrelated Laravel Gates with the same name.
13. Database identifiers owned by the package are no longer than 30
    characters.
14. Package-managed and DBA-managed schemas must implement the same logical
    contract.

## Implementation and evidence boundary

The repository currently contains package bootstrapping, a canonical schema
manifest, migrations, vendor DDL compilers, schema inspectors, database
artifacts, and related automated tests.

Current live database evidence includes:

- MySQL 8.0.16 and 8.0.45 across Laravel 12 and Laravel 13;
- Oracle Free 23.26.1 across Laravel 12 and Laravel 13 for both supported
  generated-key strategies and provisioning modes.

The advertised minimum Oracle version is not yet independently evidenced.
PostgreSQL and SQL Server live compatibility remain unverified. The mutation
managers, authorization resolver, Laravel Gate integration, and complete audit
runtime are later implementation phases.

Do not report a specification feature as implemented merely because it is
described in a document.

## Required review questions

### Authorization semantics

1. Can the reviewer calculate the same allow or deny result for direct,
   role-based, multiple-source, revoked, expired, scheduled, and deactivated
   cases?
2. Are time boundaries, especially `starts_at <= T` and `expires_at > T`,
   unambiguous?
3. Can any documented path accidentally grant access by absence, fallback, or
   unrelated Laravel Gate behavior?
4. Are subject and guard resolution deterministic in HTTP, queue, CLI, and test
   contexts?

### Mutation and lifecycle semantics

1. Are assign, grant, revoke, deactivate, restore, and idempotent no-op
   behaviors distinct and consistent?
2. Are transaction, audit, cache invalidation, and after-commit event boundaries
   ordered clearly?
3. Are overlapping assignment periods and concurrent equivalent mutations
   handled consistently?
4. Can a consumer understand which public API is stable and which objects are
   internal?

### Audit and actor semantics

1. Can every required audit event be reconstructed consistently from the
   specified mutation?
2. Are human actors, system actors, subject identities, and target identities
   distinguishable and portable?
3. Are strict audit failure and sensitive-data exclusion requirements
   sufficient and unambiguous?
4. Is the minimum required content of `before`, `after`, and `context`
   snapshots defined?

### Laravel integration

1. Do direct resolver calls, subject helpers, Gate, middleware, and Blade reach
   one authorization source of truth?
2. Are missing definitions, unauthenticated subjects, unauthorized subjects,
   and invalid middleware parameters observably distinct where required?
3. Are the proposed public signatures and input-resolution rules predictable,
   including numeric-looking strings and explicit references?

### Portability and operations

1. Does the logical behavior remain independent of the database engine and
   provisioning mode?
2. Are schema-qualified names, generated keys, identifiers, constraints, and
   rollback expectations sufficiently specified?
3. Does documentation distinguish advertised compatibility from verified live
   evidence?
4. Can a DBA-managed environment validate drift without granting schema-change
   authority to the application?

### Scope and consistency

1. Does any document introduce a feature listed as a non-goal?
2. Does any accepted ADR contradict the package specification, charter, ERD, or
   constraint plan?
3. Are error behavior and security-sensitive failure modes explicit rather than
   left to implementation preference?
4. Which unresolved decisions block the first vertical slice?

## Authorization scenario check

For each scenario, record `Allow`, `Deny`, or `Specification ambiguous`, then
cite the controlling section.

| ID | Scenario | Expected interpretation |
|---|---|---|
| A01 | No assignment exists | Deny |
| A02 | One active direct permission exists | Allow |
| A03 | One active role contains one active permission | Allow |
| A04 | A direct source is revoked but an active role source remains | Allow |
| A05 | The only assignment is revoked | Deny |
| A06 | The only assignment starts in the future | Deny |
| A07 | The only assignment expires exactly at evaluation time | Deny |
| A08 | The role is deactivated but its assignments remain active | Deny |
| A09 | The role is restored and its unrevoked assignment is still in period | Allow |
| A10 | The permission definition is deactivated | Deny |
| A11 | Subject and permission guards differ | Reject the mutation or deny the check as specified |
| A12 | Another authenticated user exists while a supplied subject is checked | Evaluate only the supplied subject |
| A13 | An unrelated Laravel Gate has the same name as a GateTrail permission used by GateTrail middleware | Do not allow through the unrelated Gate |
| A14 | No GateTrail permission matches a normal Laravel Gate ability | Return `null` from the GateTrail hook |
| A15 | Two concurrent requests create overlapping equivalent assignments | At most one succeeds as an effective assignment |

Any disagreement with an expected interpretation is a finding, even if the
reviewer believes an alternative design would be preferable.

## Priority open decisions

The reviewer must make a concrete recommendation on both decisions below.

### O01: Minimum audit snapshot content

Recommend the minimum event-by-event content required in `before`, `after`, and
`context`. Address:

- which fields are mandatory for create, update, deactivate, restore, assign,
  grant, and revoke events;
- whether snapshots contain identifiers only or security-relevant attributes;
- how absent values differ from redacted values;
- whether snapshot structure is stable public data;
- how sensitive and application-defined attributes are excluded;
- what is required to explain an access change without replaying mutable rows.

The recommendation should be minimal, portable, and sufficient for incident
analysis.

### O02: Direct database mutation behavior

Recommend package behavior when schema-valid changes bypass managed services.
Address:

- whether detection is guaranteed, best-effort, or diagnostic-only;
- what `gatetrail:doctor` can detect without changing data;
- whether authorization must fail closed when drift or an unmanaged mutation is
  discovered;
- how cache correctness is protected when no managed invalidation occurred;
- which behavior is runtime enforcement versus documentation and operational
  policy;
- whether database triggers or elevated privileges would violate portability or
  DBA-managed ownership.

The recommendation must not claim that all direct writes can be detected if the
proposed mechanism cannot prove that.

## Other non-blocking open decisions

The reviewer may comment on, but need not finalize:

- cache store, TTL, and role-change fan-out;
- first-class UUID/ULID migration variants before `1.0.0`;
- role-specific Blade convenience directives.

Cache recommendations must preserve correctness without assuming that
cross-request caching already exists.

## Finding severity

| Severity | Meaning |
|---|---|
| Critical | The contract can permit unauthorized access, destroy required history, or make the first vertical slice unsafe to implement |
| High | Contradictory or missing behavior is likely to produce incompatible implementations or a serious operational failure |
| Medium | Ambiguity or incompleteness should be resolved before public API or `1.0.0`, but does not block a safe first slice |
| Low | Editorial, naming, navigation, or non-blocking clarity improvement |

Design preferences without a demonstrated contract, security, portability, or
maintainability impact should be listed separately as observations.

## Required finding format

Use one block per finding:

```text
Finding ID:
Severity: Critical | High | Medium | Low
Area:
Claim:
Evidence: file name and section
Impact:
Recommendation:
Blocks first vertical slice: Yes | No
```

Recommendations should state the smallest contract change needed. If no finding
exists for an area, say so instead of omitting the area silently.

## Required review response

Return the review in this order:

1. **Reviewer and date**
   - reviewer name or tool/model;
   - review date;
   - repository commit reviewed, when available;
   - limitations or unavailable files.
2. **Final verdict**
   - `Accept`;
   - `Accept with changes`; or
   - `Rework required`.
3. **Executive summary**
   - no more than ten concise points.
4. **Authorization scenario results**
   - completed A01-A15 table.
5. **Findings**
   - ordered by severity, then finding ID.
6. **Priority decision O01 recommendation**
7. **Priority decision O02 recommendation**
8. **Non-blocking observations**
9. **First vertical-slice blockers**
   - explicit list, or `None`.

An `Accept` verdict requires no unresolved Critical or High finding and no
ambiguity that blocks the first vertical slice. The maintainers retain final
decision authority and may accept, defer, or reject recommendations with a
recorded rationale.

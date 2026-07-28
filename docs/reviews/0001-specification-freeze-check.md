# GateTrail Specification Freeze Check

## Freeze result

| Item | Value |
|---|---|
| Check identifier | `0001` |
| Check date | 2026-07-28 |
| Pre-freeze commit reviewed | `22f45c2` |
| Independent review | [Response 0001](0001-specification-review-response.md) |
| Maintainer disposition | [Disposition 0001](0001-specification-review-disposition.md) |
| Result | Passed |
| Accepted status | Accepted for implementation |
| Product status | Pre-alpha |

The baseline is established by the commit containing this record.

Acceptance freezes the decisions required to begin implementation. It does not
claim that documented runtime behavior is already implemented, that every
compatibility target is verified, or that the package is ready for public
release.

## Authority reviewed

The freeze check reviewed:

1. `PROJECT_CHARTER.md`;
2. `PACKAGE_SPEC.md`;
3. ADR 0001 through ADR 0009 under `docs/decisions/`;
4. `docs/architecture/initial-erd.md`;
5. `docs/architecture/constraints-and-indexes.md`;
6. the independent review brief, response, and disposition;
7. `ROADMAP.md`.

All nine ADRs have status `Accepted`. All independent-review findings F-01
through F-11 and priority decisions O01 and O02 have status `Resolved`.

## Freeze criteria

| Criterion | Result | Evidence |
|---|---|---|
| Owner approves the specification baseline | Pass | Approval recorded in the specification review workflow |
| Effective permission behavior is deterministic | Pass | Specification sections 5 and 11 |
| Role-check behavior has one source of truth | Pass | Specification section 11.5 |
| Assignment lifecycle and time boundaries are deterministic | Pass | Specification section 9 and ADR 0001 |
| Public mutation API direction is accepted | Pass | Specification section 10 and ADR 0002 |
| Subject guard behavior is deterministic | Pass | Specification section 6 and ADR 0003 |
| Middleware behavior is deterministic | Pass | Specification section 12.5 and ADR 0004 |
| Human and system actors are portable | Pass | Specification section 7.2 and ADR 0005 |
| Oracle generated-key ownership is explicit | Pass | Specification section 3.5 and ADR 0006 |
| Laravel Gate and policy boundary is safe | Pass | Specification section 12.3 and ADR 0007 |
| Audit snapshots and failure modes are explicit | Pass | Specification sections 10 and 13 and ADR 0008 |
| Direct mutation guarantees and diagnostics are honest | Pass | Specification sections 10.4 and 18 and ADR 0009 |
| Initial non-goals remain explicit | Pass | Charter and specification section 23 |
| First vertical-slice decisions are accepted | Pass | Specification section 26 |
| Independent review remediation is complete | Pass | Review disposition |

## Authorization scenario recheck

The A01-A15 scenarios from the independent review remain deterministic:

| ID | Result |
|---|---|
| A01 | Deny when no assignment exists |
| A02 | Allow through one active direct permission |
| A03 | Allow through one active role-permission path |
| A04 | Allow while another active source remains |
| A05 | Deny when the only assignment is revoked |
| A06 | Deny when the effective start is in the future |
| A07 | Deny exactly at the exclusive expiry boundary |
| A08 | Deny through a deactivated role |
| A09 | Allow after restore when the preserved assignment remains eligible |
| A10 | Deny through a deactivated permission definition |
| A11 | Reject cross-guard mutation and deny cross-guard checks |
| A12 | Evaluate the supplied subject only |
| A13 | Do not satisfy GateTrail middleware through an unrelated Laravel Gate |
| A14 | Return `null` only for a genuine GateTrail Gate-hook non-match |
| A15 | Serialize overlapping equivalent assignment mutations |

No remediation changed an expected scenario result.

## Compatibility evidence boundary

The specification records compatibility targets, not blanket verification
claims.

Current evidence includes:

- MySQL 8.0.16 and 8.0.45 on Laravel 12 and Laravel 13;
- Oracle Free 23.26.1 on Laravel 12 and Laravel 13 for both accepted
  generated-key strategies and both provisioning modes;
- SQLite package tests for database-neutral behavior.

The following remain incomplete:

- minimum Oracle 12c Release 2 evidence;
- live PostgreSQL evidence and vendor artifacts;
- live SQL Server evidence and vendor artifacts;
- complete behavioral authorization implementation across target databases.

Those gaps affect compatibility verification and release readiness. They do not
make the target contract ambiguous.

## Non-blocking open decisions

The following decisions remain deliberately open:

1. cache store, TTL, and role-change fan-out;
2. first-class UUID/ULID subject migration variants before `1.0.0`;
3. role-specific Blade convenience directives.

They do not block the first vertical slice:

- the resolver must be correct without cross-request caching;
- the initial published schema has an accepted numeric-ID strategy;
- Laravel's native `@can` remains the supported Blade path.

Each item requires an ADR before its implementation is finalized.

## Implementation boundary

The accepted specification is the implementation baseline for:

- mutation managers;
- role and permission models;
- authorization resolvers;
- Laravel Gate integration;
- middleware;
- audit persistence;
- diagnostic commands;
- subsequent cache design.

Design acceptance is not implementation evidence. Roadmap phases remain
incomplete until working code and required automated tests exist.

## Change control after acceptance

After this baseline:

- contradictions between code and specification must be resolved explicitly;
- security-sensitive behavioral changes require an ADR and regression tests;
- public contract changes require compatibility review;
- non-blocking open decisions remain open until their own ADR is accepted;
- accepted behavior must not be changed merely to match one consumer
  application.

## Conclusion

The GateTrail package specification meets its Phase 1 exit criteria and is
accepted for implementation. Phase 1 may be marked Complete.

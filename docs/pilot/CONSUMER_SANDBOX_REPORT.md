# Consumer sandbox pilot report

Status: **Accepted for the internal alpha**

Date: 2026-10-12

## Scope

This report records GateTrail's first independent consumer pilot. The consumer
is a fresh Laravel application used only as a sandbox; it is not the application
from which GateTrail's original design was studied, and GateTrail did not modify
or replace an existing authorization implementation.

The pilot validates `v0.1.0-alpha.2` as a usable private package for a fresh
consumer. It does not claim stable-release readiness, existing-application
parity, or live PostgreSQL and SQL Server application-runtime support.

## Environment

| Item | Observed value |
|---|---|
| Package | `hangtuah/laravel-gatetrail` `v0.1.0-alpha.2` |
| Package commit | `e3cc302` |
| Consumer | Independent local Git repository; no remote |
| Consumer baseline | `a3ae904` |
| Guided demo | `09d21cc` |
| Pilot observation tests | `2c26a4d` |
| PHP | 8.5.0 |
| Laravel | 13.33.0 |
| Database | SQLite 3.45.2 |
| Provisioning | Package-managed migrations |
| Consumer test result | 12 tests, 87 assertions |

The consumer requires PHP 8.5 even though GateTrail itself supports PHP 8.3
and later. The explicit PHP 8.5 binary was therefore used for Composer,
Artisan and PHPUnit in this application.

## Guided vertical slice

The `/gatetrail` page exposes one deliberate action at a time:

1. create the `reviewer` role and `documents.approve` permission;
2. grant the permission to the role;
3. assign the role to a persisted demo subject;
4. show resolver, `HasGateTrail` and Laravel Gate parity;
5. revoke the role assignment;
6. show that authorization returns to deny while assignment and audit history
   remain.

Every GateTrail mutation uses the public manager contracts. The consumer does
not write directly to a GateTrail table. Live browser observation produced the
expected sequence:

```text
DENY before assignment
→ ALLOW after assignment
→ DENY after revocation
```

The final persistent database state contained one role, one permission, one
active role-permission grant, one revoked role assignment and five append-only
audit records. The revoked assignment was retained rather than hard deleted.

## Test-plan results

| ID | Result | Evidence |
|---|---|---|
| P-01 — Exact private installation | Pass | `composer.lock` resolves the exact alpha.2 tag; baseline and final consumer suites pass |
| P-02 — Staged configuration | Pass for fresh consumer | Default isolated `acl_*` SQLite tables and package-managed provisioning; no legacy authorization objects existed |
| P-03 — Schema provisioning | Pass with documented limitation | Seven migrations installed; `gatetrail:matrix --json` reports ready; Doctor reports zero errors and the documented SQLite physical-inspector warning |
| P-04 — Definition and assignment lifecycle | Pass | Public-manager lifecycle, unchanged idempotent replay, revocation and retained history are automated |
| P-05 — Authorization parity | Pass | Guided page and tests prove role, permission and Gate parity; missing, deactivated and cross-guard requests fail closed |
| P-06 — Audit evidence | Pass for the controlled lifecycle | Five expected events carry the system actor and non-empty reasons; the package suite remains the evidence for strict rollback and append-only mutation rejection |
| P-07 — Existing authorization coexistence | Not applicable | This is intentionally a fresh independent consumer; coexistence remains Phase 12 work and no replacement claim is made |
| P-08 — Performance and memoization | Pass | Consumer observes three cold selects, zero warm selects and two fresh selects after revocation invalidates the request memo |
| P-09 — Operational rollback rehearsal | Pass for sandbox boundary | Stopping and restarting the application enforcement point leaves GateTrail data intact; destructive database rollback was reviewed but not run |

## Performance observations

The consumer's single-role, role-derived permission path matched the documented
query budget:

| Observation | Select statements |
|---|---:|
| Cold permission resolution with one effective role | 3 |
| Warm request-local repeat | 0 |
| First resolution after role revocation | 2 |

The post-revocation read denied access, proving the mutation event cleared the
request-local memo. The package's accepted live MySQL benchmark remains the
latency evidence: representative cold p95 6.069 ms and warm p95 0.009 ms, with
three cold selects and zero warm statements. This SQLite consumer run does not
replace or broaden that benchmark claim.

## Developer feedback

### What worked well

- Manager method names made the lifecycle readable without knowledge of the
  internal handlers or command objects.
- Actor and reason requirements made the audit trail useful without additional
  consumer plumbing.
- `HasGateTrail` and Laravel Gate produced the same result, so application code
  can use familiar authorization checks.
- Revocation semantics were visible in the UI: access disappeared while the
  historical assignment remained.
- The install, matrix and doctor commands gave actionable setup evidence.

### Friction and disposition

- The consumer's PHP 8.5 requirement differed from the non-interactive WSL
  default. This is a consumer environment issue, not a GateTrail defect.
- SQLite Doctor cannot inspect the physical catalog. It reports this as an
  explicit incomplete warning with zero errors; this is a documented boundary.
- Initial installation documentation named alpha.1. `GT-PILOT-002` corrected
  the instructions and is verified.
- GateTrail is intentionally headless. The guided page required ordinary
  consumer orchestration, but no internal package class or workaround.

## Defect disposition

No new defect was discovered by the guided lifecycle or observation tests.
The two earlier pilot findings are verified and closed. There are no open S1
or S2 findings.

## Retrospective and decision

The internal alpha goal is met for a fresh consumer: the exact private tag can
be installed, provisioned and used through public APIs for role-derived access,
Laravel Gate enforcement, revocation and auditable history. Query behavior and
request-local memo coherence match the accepted resolver design.

Decision: **proceed to release review and publication planning**. Another alpha
is not required by this pilot evidence alone. Phase 12 remains separate work for
existing-application coexistence and parity; it must not be inferred from this
fresh-consumer acceptance.


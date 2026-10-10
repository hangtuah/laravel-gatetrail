# GateTrail threat model

Status: accepted internal-alpha baseline, 2026-10-11

## Security objective

GateTrail must fail closed when it cannot establish effective access, preserve
attributable mutation history through its supported API, and avoid disclosing
authorization structure or sensitive audit data through denial paths.

## Assets and trust boundaries

Protected assets are role and permission definitions, assignments, effective
authorization answers, mutation audit records and safe operational diagnostics.

The package trusts the consuming Laravel application's authenticated subject,
policy decision, configuration deployment, database connection and clock. It
does not trust route parameters, role or permission names, model identifiers,
consumer metadata, request headers, concurrent writers or database collation.
A privileged DBA and direct SQL/query-builder writes are outside the managed
API boundary; database privileges and organizational controls govern them.

## Mutation authorization boundary

An explicit mutation `actor` records attribution only. It does not prove that
the actor may mutate authorization state. Consumer controllers, jobs, commands
or application services must authorize first:

```php
Gate::authorize('roles.assign');

$assignments->assignRole(
    subject: $user,
    role: 'reviewer',
    actor: $administrator,
    reason: 'Approved access request SEC-42',
);
```

GateTrail deliberately does not read `Auth::user()` or impose one application's
policy vocabulary inside reusable managers. Background work uses the same
rule: authorize before dispatch or in the trusted application service, then
pass a stable `SystemActor` for attribution.

## Threat disposition

| Threat | Control and evidence | Residual boundary |
|---|---|---|
| Missing, revoked, expired, future or deactivated access grants | Half-open lifecycle predicates, explicit joined-definition filters and default-deny resolver tests | Database visibility follows the configured connection's committed state |
| Cross-subject access | Subject type and canonical identifier are part of every assignment query and memo key; resolver tests prove another subject's grants do not cross | Consumer-provided subject model and morph map must be configured correctly |
| Cross-guard access | Guard is resolved independently, compared exactly in SQL and PHP, and included in memo keys; mutation mismatch throws while checks deny | Changing a deployed guard is an application migration |
| SQL injection through names or identifiers | Eloquent/query-builder bindings, strict identifier normalization before driver binding, no raw resolver SQL, and dedicated hostile-input regression tests | DBA-authored DDL artifacts are trusted release artifacts, not user input |
| Duplicate or conflicting concurrent assignments | Persistent coordination rows, row locks, exact scope collision checks and bounded driver-classified retry | Caller-owned transactions cannot be retried and fail explicitly |
| Stale cached authorization | Only bounded request-local memoization exists; transactions bypass it, lifecycle seconds key it and managed mutations clear it after commit | No cross-request cache or distributed invalidation claim exists |
| Audit omission or partial mutation | Strict mode is default and shares one transaction; non-strict/disabled modes require explicit consumer choice | Non-strict and disabled modes intentionally weaken history guarantees |
| Audit tampering | Persisted `AccessAudit` instances reject Eloquent mutation and deletion; no pruning runs by default | Direct SQL and privileged DBA activity are not cryptographically detectable; least-privilege DB roles and external database auditing are recommended |
| Sensitive audit disclosure | Package-owned snapshots, finite metadata depth/size, configured redaction, safe exceptions and diagnostics | Consumers choose metadata and sensitive paths and must not submit secrets unnecessarily |
| Middleware alias takeover | Existing unrelated aliases cause visible boot failure and are never overwritten | Installation diagnostics do not prevent a consumer intentionally replacing package registration later |
| Application Gate collision | Argument-free GateTrail abilities resolve centrally; argument-bearing abilities fall through to policies; deactivated definitions deny without fallthrough | Consumers remain responsible for their unrelated Gate and policy definitions |
| Dependency vulnerability | PHPStan level max, full automated suite and Composer advisory audit in CI | New advisories may appear after a successful build and require routine updates |

## Abuse-path evidence

The security baseline is distributed intentionally across focused suites:

- `tests/Security/MutationInputBindingTest.php` pins hostile SQL-shaped data;
- `tests/Access/*` pins default deny, cross-subject, cross-guard and lifecycle
  exclusion;
- `tests/Laravel/*` pins generic denials, policy coexistence and alias collision;
- `tests/Concurrency/*`, `AssignmentRetryIntegrationTest` and
  `RetryAcceptanceTest` pin coordination and bounded retry;
- `tests/Audit/*`, handler tests and `AccessAuditAppendOnlyTest` pin redaction,
  safe context, transaction behavior and the model append-only boundary.

## Accepted residual risks

GateTrail audit records are append-only through the supported model API, not a
cryptographic ledger. A database administrator can alter rows. The initial
package also has no explicit deny rule, hierarchy, wildcard permission,
super-administrator bypass or cross-request cache. These are deliberate product
boundaries rather than silent security behavior.

No known critical or high-severity GateTrail issue is accepted at this
baseline. Dependency advisories are re-evaluated by CI rather than frozen into
this document.

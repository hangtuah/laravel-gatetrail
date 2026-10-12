# Internal pilot defect log

Pilot version: `v0.1.0-alpha.2`

Status: **Open for pilot entries**

## Rules

Record every package failure, integration workaround, documentation ambiguity
and portability mismatch discovered by the pilot. Do not hide a workaround in
consumer code. One row represents one independently actionable finding.

### Severity

| Severity | Meaning | Release effect |
|---|---|---|
| S1 — Critical | Authorization bypass, privilege escalation, audit integrity loss, destructive schema behavior or secret disclosure | Stop the pilot; blocks every release |
| S2 — High | Incorrect deny/allow result, non-portable supported-path failure, unrecoverable installation/upgrade problem or missing required audit evidence | Blocks the next alpha and stable release |
| S3 — Medium | Supported workflow needs a workaround, diagnostics are materially misleading, or documented public API is difficult to use correctly | Must be dispositioned before pilot acceptance |
| S4 — Low | Wording, ergonomics, non-blocking diagnostics or maintainability improvement | May be scheduled with rationale |

### Status

Use `Open`, `Investigating`, `Fixed awaiting verification`, `Verified`,
`Accepted limitation` or `Rejected`. Only `Verified`, `Accepted limitation` and
`Rejected` are closed states. An accepted limitation requires maintainer
rationale and a documentation reference.

## Findings

| ID | Date | Severity | Environment | Area | Summary | Workaround | Owner | Status | Evidence / disposition |
|---|---|---|---|---|---|---|---|---|---|
| GT-PILOT-001 | 2026-10-12 | S3 | Laravel 12 consumer fixture / Oracle DBA provisioning | Installation | No public consumer command generates reviewed DBA artifacts from configured custom table/object names | None after `v0.1.0-alpha.2` | Maintainer | Verified | A consumer fixture installed `v0.1.0-alpha.2`, generated an exact custom pair without a database connection, and completed an install/catalog/trigger/rollback cycle on disposable Oracle Free 23.26.3; rollback left zero `GT_*` objects |

## GT-PILOT-001 — Custom DBA artifact generation workflow

Expected: an existing application with colliding authorization table names can
configure isolated GateTrail names and produce reproducible Oracle DBA install
and rollback artifacts through a documented public command.

Observed: package documentation correctly requires custom artifacts to be
generated from the canonical manifest, but the consumer-facing command set has
no DDL generation command. The repository-only generator reads the package's
own configuration and is not a supported consumer API.

Impact: staged installation succeeds and remains safely disabled, but an
Oracle DBA-managed pilot cannot provision the configured `gt_*` objects without
an undocumented internal workflow or manually edited generated SQL. Either
choice violates the pilot rules.

Correction: `gatetrail:ddl` now consumes the application's resolved GateTrail
configuration and writes both artifacts to an explicit output directory without
connecting to or mutating a database.

Verification: the pilot application installed `v0.1.0-alpha.2` and invoked the
command through its disabled-by-default console provisioning switch. The
resulting Oracle release 0001 pair contains all seven configured `gt_*` tables,
all six configured sequences and all six configured triggers. Every generated
`gt_*` identifier is within the 30-character Oracle limit, rollback covers the
same objects, and no canonical `acl_*` object appears in a `CREATE` statement.
No database connection was opened during generation.

The exact generated pair then completed a live cycle against a fresh disposable
Oracle Free 23.26.3 schema. Installation created seven tables, six sequences and
six enabled triggers; all 68 constraints were enabled and validated; no object
was invalid; and an insert verified sequence-trigger key generation. Rollback
completed successfully and left zero `GT_*` objects. The disposable container
was removed after verification.

## Entry template

Copy this block for details that do not fit safely in the table. Never include
passwords, access tokens, raw personal identifiers or production SQL bindings.

```text
ID:
Date discovered:
Package version and commit:
Application commit:
PHP / Laravel / driver / database server:
Provisioning mode:
Severity:
Status:

Expected:
Observed:
Minimal reproduction:
Security or data impact:
Temporary workaround:
Package-side correction:
Regression evidence:
Disposition and maintainer rationale:
```

## Summary

| State | Count |
|---|---:|
| Open release blockers (S1/S2) | 0 |
| Other open findings | 0 |
| Closed findings | 1 |

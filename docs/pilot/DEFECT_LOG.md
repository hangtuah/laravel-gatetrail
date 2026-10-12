# Internal pilot defect log

Pilot version: `v0.1.0-alpha.1`

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

No defects have been recorded yet.

| ID | Date | Severity | Environment | Area | Summary | Workaround | Owner | Status | Evidence / disposition |
|---|---|---|---|---|---|---|---|---|---|
| — | — | — | — | — | No pilot execution yet | — | — | — | — |

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
| Closed findings | 0 |

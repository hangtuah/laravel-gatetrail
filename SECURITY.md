# Security policy

## Supported versions

GateTrail is pre-release software. Security fixes are applied to the latest
commit on `main` and to the newest published pre-release only. No older
pre-release receives a backport guarantee before `1.0.0`.

## Reporting a vulnerability

Do not open a public issue for a suspected vulnerability. Use GitHub's private
security-advisory reporting for `hangtuah/laravel-gatetrail`. Include:

- the affected commit or package version;
- the Laravel, PHP and database versions;
- a minimal reproduction;
- the security impact and required privileges; and
- any suggested mitigation, if known.

Do not include production credentials, personal data, access tokens, private
keys, raw audit records or proprietary application code.

Maintainers will acknowledge a complete report as capacity permits, validate
its scope privately, coordinate a fix and tests, and publish an advisory after
affected users have a reasonable opportunity to update. This document does not
promise a fixed response deadline while the package remains maintainer-owned
pre-release software.

## Scope

Examples in scope include authorization bypass, cross-subject or cross-guard
access, unsafe SQL construction, concurrency races that grant access, audit
record disclosure or mutation through GateTrail's supported API, secret
exposure in exceptions or diagnostics, and middleware/Gate integration that
fails open.

Application policy mistakes, direct database writes outside GateTrail's
managed API, compromised DBA credentials, unsupported internal classes and
vulnerabilities already fixed by current Laravel or PHP security releases are
normally outside GateTrail's security boundary. Reports showing that GateTrail
amplifies one of those conditions remain welcome.

## Dependency hygiene

CI runs Composer's advisory audit after resolving the supported dependency
set. Maintainers must update away from a known high or critical advisory before
claiming a clean security baseline, or document why the affected code is
provably unreachable and accept the residual risk explicitly.

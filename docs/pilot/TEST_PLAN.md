# Internal pilot test plan

## Purpose

This plan validates the exact GateTrail pre-release in one controlled Laravel
application before a stable or public release claim. The pilot supplements the
package test suite; it does not replace it or silently redefine supported
database evidence.

Pilot version: `v0.1.0-alpha.1`

## Safety boundaries

- Install the exact tag from the private Composer repository.
- Use only the documented public API.
- Keep any existing authorization implementation active.
- Use isolated GateTrail tables that do not overlap existing application
  tables.
- Start with one non-critical permission and one controlled route or service.
- Do not bulk-copy production roles, permissions or assignments.
- Do not grant access solely because a comparison disagrees. A disagreement
  fails closed and is logged as a defect.
- Database removal, rollback and data cleanup require application-owned
  approval and are not performed by this plan automatically.

## Environments

| Stage | Purpose | Minimum evidence |
|---|---|---|
| Local or disposable | Prove installation, configuration and the vertical slice without shared data | Automated pilot tests pass from a clean database |
| Shared development | Prove the selected provisioning workflow and application integration | Read-only diagnostics pass and the controlled slice behaves as expected |
| Production-like, if available | Observe operational behavior without making a release claim | Query counts, logs and rollback procedure are reviewed |

Record the actual Laravel, PHP, database driver and database server versions in
the pilot evidence. Do not infer them from the package compatibility matrix.

## Entry criteria

- The application baseline test suite is recorded before installation.
- Repository access for Composer is read-only and verified.
- The package is excluded from automatic discovery until configuration and
  schema are ready when piloting an existing application.
- Connection, schema, provisioning mode and all seven physical table names are
  approved.
- A rollback owner and a defect owner are named.
- The pilot permission and participating test users are identified.

## Test sequence

### P-01 — Exact private installation

1. Register the private VCS repository.
2. Require `hangtuah/laravel-gatetrail:0.1.0-alpha.1 --prefer-source`.
3. Confirm `composer.lock` resolves exactly `v0.1.0-alpha.1`.
4. Run the pre-existing application tests.

Pass: the exact tag is locked and the baseline remains green.

### P-02 — Staged configuration

1. Keep package discovery disabled for an existing application.
2. Configure a dedicated connection/schema as required.
3. Configure seven non-conflicting physical table names.
4. Select package-managed or DBA-managed provisioning explicitly.
5. Validate identifier length and generated-key strategy for the target engine.

Pass: configuration can be loaded without activating GateTrail or changing the
existing authorization path.

### P-03 — Schema provisioning

1. Provision an empty GateTrail schema using the selected supported workflow.
2. Register the service provider only after configuration and schema are ready.
3. Run `php artisan gatetrail:doctor --json`.
4. Run `php artisan gatetrail:matrix --json`.

Pass: no error-level diagnostic is reported, and any incomplete diagnostic
coverage matches the documented engine boundary.

### P-04 — Definition and assignment lifecycle

Using public managers only:

1. create one pilot role and one pilot permission;
2. grant the permission to the role;
3. assign the role to one persisted subject with actor and reason;
4. repeat an idempotent mutation and verify the documented outcome;
5. revoke the assignment;
6. confirm history remains present rather than being hard deleted.

Pass: all outcomes, lifecycle timestamps and typed failures match the public
contract.

### P-05 — Authorization parity

1. Evaluate the permission through `PermissionResolver`.
2. Evaluate the same permission through Laravel Gate.
3. Protect one controlled route or service with GateTrail middleware or Gate.
4. Verify allow before revocation and deny after revocation.
5. Verify a missing, deactivated and cross-guard permission denies without
   disclosing internal definition details.

Pass: resolver, Gate and route/service enforcement agree for every case.

### P-06 — Audit evidence

For each managed mutation, verify:

- event type and target;
- actor identity;
- non-empty reason;
- canonical before/after snapshots where applicable;
- metadata contains no secret or raw rejected identifier;
- a failed strict audit write rolls back the mutation;
- audit rows cannot be changed or deleted through the model.

Pass: the audit trail explains the complete controlled lifecycle.

### P-07 — Existing authorization coexistence

1. Exercise representative legacy authorization checks before and after
   GateTrail activation.
2. Confirm legacy tables and relationships are unchanged.
3. Confirm the pilot permission name does not collide with an existing Gate.
4. Compare decisions in observation mode; do not replace the legacy decision
   point yet.

Pass: the existing authorization implementation remains operational and no
namespace or table collision occurs.

### P-08 — Performance and memoization

Record, without storing SQL bindings or subject identifiers:

- cold effective-role query count;
- cold effective-permission query count;
- warm request-local query count;
- mutation-to-next-read cache behavior;
- representative elapsed time and dataset size.

Pass: query counts match the documented budget and no correctness decision
depends on memoized data from a rolled-back transaction.

### P-09 — Operational rollback rehearsal

1. Disable the controlled GateTrail enforcement point.
2. Verify the application returns to the original authorization path.
3. Confirm disabling integration does not delete GateTrail history.
4. Review the separately approved database rollback procedure without running
   destructive DDL during an ordinary pilot test.

Pass: application behavior can be restored without emergency package changes.

## Exit criteria

The pilot may be accepted only when:

- P-01 through P-09 have recorded results;
- no open severity-1 or severity-2 defect remains;
- every workaround is either removed or accepted as documented package work;
- performance observations and developer feedback are attached;
- the retrospective identifies whether the package may proceed, needs another
  alpha, or must stop.

# Changelog

All notable changes to GateTrail will be documented in this file.

## Unreleased

### Changed

- `RoleManager::create()` and `PermissionManager::create()` now describe their
  result as the configured Eloquent model implementing the corresponding marker
  contract, instead of incorrectly promising the package's default model.
- `GateTrailEvent` now declares `context(): array`, and
  `AuditPersistenceFailed` implements the contract. A listener registered for
  `GateTrailEvent` therefore receives both successful mutation events and audit
  persistence failure events.
- Constructors used only by GateTrail are now private or explicitly marked
  `@internal`; consumers should obtain results and events from package APIs and
  catch exceptions produced by their named factories.
- A configured `access_audit` model must now extend `Models\AccessAudit`, which
  guarantees GateTrail connection/table resolution and the absence of an
  `updated_at` write.
- The inherited connection/table behavior of the six supported package models,
  the seven valid table keys, and all four assignment-period scopes are now
  documented and regression-tested as extension-surface constraints.
- All 157 Tier 4 implementation symbols now carry a class-level `@internal`
  annotation. This changes IDE and static-analysis visibility only; no runtime
  behavior or supported symbol is changed.

### Migration guidance

- Catch-all `GateTrailEvent` listeners that read mutation-specific fields must
  first narrow the event with `instanceof MutationCommitted`. Generic listeners
  may call `context()` directly on the contract.
- Do not construct `AuditPersistenceFailed`, `ActorIdentity`, GateTrail
  exceptions, or authentic mutation results in consumer code. Their construction
  is package-controlled and is not part of the pre-v1 compatibility promise.
- Change a custom audit model that extends Eloquent `Model` directly to extend
  `HangTuah\GateTrail\Models\AccessAudit` instead. Custom role and permission
  models remain independent Eloquent models when they satisfy their existing
  marker-contract and `SoftDeletes` requirements.

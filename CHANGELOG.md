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
- A reflection-based boundary test now rejects unclassified source symbols,
  missing Tier 4 annotations and drift in the accepted Tier 1 and Tier 2
  signatures, readable fields, constants, enum values and constructors.
- Container and event-listener safeguards now pin all five public service
  bindings, the resolver scope lifecycle and generic `GateTrailEvent` delivery
  for both mutation-commit and audit-persistence-failure events.
- The package specification now records all 25 implemented exceptions in their
  accepted Tier 1 or operational category, labels the two planned Phase 6
  exceptions and middleware as unimplemented, and reflects the working disabled
  audit mode.
- `docs/PUBLIC_API.md` now provides the authoritative pre-v1 Tier 1 and Tier 2
  inventory, exact consumer signatures, model and scope extension surface,
  event and exception fields, the supported clock seam, and standard argument
  validation behavior. Automated tests require its 52-symbol manifest to match
  the reflected source boundary.
- `README.md` now introduces the package, its pre-alpha and unfrozen status,
  the supported runtimes and database evidence, installation and
  auto-discovery, the `gatetrail-config` publish tag, both provisioning modes,
  a first mutation, a first effective-permission check, and the warning that
  `@internal` symbols are not consumer API.
- The new `docs/CONSUMER_GUIDE.md` documents installation, configuration
  publishing, package-managed and DBA-managed provisioning, subject and model
  setup, every implemented mutation and check, half-open assignment periods,
  human and `SystemActor` examples, the strict, non-strict and disabled audit
  modes, `MutationResult` interpretation, generic `GateTrailEvent` listening
  and the public exception boundary. Both documents keep every unimplemented
  Phase 6 to Phase 10 surface labelled as future work.
- Documentation tests execute the documented first mutation-and-check flow and
  reject an example that fails to parse, names a `Default*` concrete, reaches
  for an `@internal` symbol, passes a named argument no reflected signature
  accepts, or presents an unavailable API as implemented.
- The documentation guard now recognizes both multiline and one-line
  class-level `@internal` PHPDoc and asserts that all 157 Tier 4 symbols are
  covered. The independent pre-v1 freeze review found no unsupported API in
  consumer examples; this review does not itself freeze the API.

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

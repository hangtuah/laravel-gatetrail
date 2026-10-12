# GateTrail Configuration Reference

This is the exhaustive reference for the supported keys in
`config/gatetrail.php`. The packaged defaults are merged automatically; publish
the file only when the application needs to override them:

```bash
php artisan vendor:publish --tag=gatetrail-config
```

GateTrail publishes no second configuration file and no facade. After changing
configuration in a deployed application, rebuild Laravel's configuration cache
using the application's normal deployment procedure.

## Validation timing

Configuration is validated at the boundary that consumes it, not by one eager
boot-time pass:

- schema, table and Oracle object settings are validated when schema context,
  migrations, DDL generation or inspection uses them;
- model classes are validated when the corresponding configured model is first
  resolved;
- reason flags are validated when mutation services are resolved;
- audit mode, metadata limits and sensitive paths are validated before the
  affected mutation persists;
- concurrency attempts are validated before a retryable assignment transaction
  starts;
- subject guards are validated when authorization is resolved.

Invalid security-relevant values are not coerced. Strings such as `'true'` and
numeric strings such as `'3'` do not substitute for the required PHP types.

## Complete key index

| Key | Type | Default |
|---|---|---|
| `database.connection` | `string|null` | `null` |
| `database.schema` | `string|null` | `null` |
| `database.prefix` | `string|null` | `null` |
| `database.provisioning` | `'package'|'dba'` | `'package'` |
| `tables.*` | non-empty portable identifier | See table section |
| `models.*` | Eloquent model class-string | Package model classes |
| `oracle.generated_keys` | `'identity'|'sequence_trigger'` | `'sequence_trigger'` |
| `oracle.sequences.*` | portable identifier | See Oracle section |
| `oracle.triggers.*` | portable identifier | See Oracle section |
| `guards.default` | non-empty `string|null` | `null` |
| `guards.subjects` | exact class-to-guard map | `[]` |
| `assignments.require_reason` | `bool` | `true` |
| `assignments.concurrency_attempts` | integer `1..10` | `3` |
| `definitions.require_reason` | `bool` | `false` |
| `audit.enabled` | `bool` | `true` |
| `audit.strict` | `bool` while audit is enabled | `true` |
| `audit.metadata.max_bytes` | positive integer | `16384` |
| `audit.metadata.max_depth` | positive integer | `8` |
| `sensitive_fields` | list of non-empty string paths | Seven default names |

## Database

### `database.connection`

The Laravel connection used by every GateTrail model, migration, transaction,
schema inspection and DDL operation. `null` or `''` selects the application's
default connection. A non-empty value must be the name of a connection in
`config/database.php`.

GateTrail validates only that the configured value is a string or `null`.
Laravel reports an unknown connection or an unavailable driver when the
connection is used.

### `database.schema`

An optional schema qualifier. `null` or `''` leaves tables unqualified. A
non-empty schema must be a portable database identifier:

- starts with an ASCII letter;
- then contains only ASCII letters, digits and underscores;
- no more than 30 characters.

Do not put a dot or quoted identifier in this value. GateTrail quotes or
qualifies identifiers through the selected database grammar.

### `database.prefix`

An optional string prepended to every configured GateTrail table name. `null`
means no prefix. An empty string is also accepted. A non-empty prefix follows
the same portable character rule and must start with an ASCII letter.

The **combined prefix and table name** must remain within the 30-character
identifier limit. For example, `auth_` plus `acl_roles` produces
`auth_acl_roles`.

### `database.provisioning`

| Value | Behaviour |
|---|---|
| `'package'` | Registers GateTrail migrations while Laravel runs in console; `php artisan migrate` owns schema creation and rollback |
| `'dba'` | Does not register the package migration path; a DBA installs the versioned SQL artifacts and the runtime account only needs data privileges |

No other value is valid. Provisioning mode changes who creates the schema; it
does not change models, managers, authorization or audit behaviour after the
same schema is present.

## Tables

| Key | Default |
|---|---|
| `tables.roles` | `acl_roles` |
| `tables.permissions` | `acl_permissions` |
| `tables.role_assignments` | `acl_role_assignments` |
| `tables.permission_assignments` | `acl_permission_assignments` |
| `tables.role_permissions` | `acl_role_permissions` |
| `tables.assignment_locks` | `acl_assignment_locks` |
| `tables.access_audits` | `acl_access_audits` |

Every value must be a non-empty portable identifier. GateTrail applies
`database.prefix` before validating the final physical table name. Configure
table names before migrations or DBA artifacts are generated; changing them
after data exists is a database migration, not a runtime alias.

## Models

| Key | Default | Required compatibility |
|---|---|---|
| `models.role` | `HangTuah\GateTrail\Models\Role` | Eloquent model, `RoleContract`, `SoftDeletes` |
| `models.permission` | `HangTuah\GateTrail\Models\Permission` | Eloquent model, `PermissionContract`, `SoftDeletes` |
| `models.role_assignment` | `HangTuah\GateTrail\Models\RoleAssignment` | Extends the package base and implements `RoleAssignmentContract` |
| `models.permission_assignment` | `HangTuah\GateTrail\Models\PermissionAssignment` | Extends the package base and implements `PermissionAssignmentContract` |
| `models.role_permission` | `HangTuah\GateTrail\Models\RolePermission` | Extends the package base and implements `RolePermissionContract` |
| `models.access_audit` | `HangTuah\GateTrail\Models\AccessAudit` | Extends the package base |

Role and permission replacements may be independent Eloquent models. The three
assignment models and audit model must extend their GateTrail base because the
package relies on inherited connection, table and lifecycle behaviour.

Missing classes, non-Eloquent classes, missing contracts, missing base classes
or missing `SoftDeletes` fail with `InvalidModelConfiguration`. Configure class
names, not model instances.

## Oracle generated keys and object names

### `oracle.generated_keys`

| Value | Behaviour |
|---|---|
| `'sequence_trigger'` | A sequence and `BEFORE INSERT` trigger supply each generated primary key |
| `'identity'` | Oracle identity columns supply generated primary keys |

The configured strategy must match the schema installed by the DBA or package
migrations. It is not detected from the database at mutation time.

### Sequence and trigger maps

The sequence-trigger strategy requires names for the six tables that own a
generated primary key:

| Table key | Sequence default | Trigger default |
|---|---|---|
| `roles` | `acl_roles_seq` | `acl_roles_trg` |
| `permissions` | `acl_perms_seq` | `acl_perms_trg` |
| `role_assignments` | `acl_role_asgn_seq` | `acl_role_asgn_trg` |
| `permission_assignments` | `acl_perm_asgn_seq` | `acl_perm_asgn_trg` |
| `role_permissions` | `acl_role_perm_seq` | `acl_role_perm_trg` |
| `access_audits` | `acl_audit_seq` | `acl_audit_trg` |

`assignment_locks` has a deterministic scope hash key and needs neither object.
Every configured Oracle object name follows the same portable 30-character
identifier rule. Sequence and trigger maps are not consulted by the identity
strategy.

## Guards

### `guards.default`

An optional authorization namespace used when no subject-specific source is
available. `null` falls back to Laravel's `auth.defaults.guard`. A selected
value must be a non-empty string after trimming; invalid authoritative values
throw `InvalidGuard` instead of falling through.

A GateTrail guard is an authorization namespace. It does not have to name a
Laravel authentication guard.

### `guards.subjects`

An exact subject-class-to-guard map:

```php
'guards' => [
    'default' => 'web',
    'subjects' => [
        App\Models\ServiceAccount::class => 'api',
    ],
],
```

The lookup does not inherit a parent class entry. Subject guard resolution uses
the first available source in this order:

1. public `gateTrailGuardName()` method;
2. declared property exposed through `HasGateTrail`;
3. exact `guards.subjects` entry;
4. `guards.default`;
5. Laravel `auth.defaults.guard`.

The supported shape is an array map. In the current implementation a
non-array `guards.subjects` value contributes no mapping and resolution moves
to the next source; do not rely on that fallback as configuration validation.

## Mutation requirements and concurrency

### `assignments.require_reason`

A real boolean. When `true`, assign, grant and revoke operations require a
non-empty reason after trimming and otherwise throw `ReasonRequired`. When
`false`, an omitted or blank reason is normalized to `null`.

### `definitions.require_reason`

The equivalent rule for role and permission create, update, deactivate and
restore operations. It defaults to `false`.

### `assignments.concurrency_attempts`

The maximum number of complete transaction attempts for a classified transient
assignment-coordination failure. It must be a real PHP integer from `1` through
`10`; booleans, floats and numeric strings are rejected with
`InvalidConcurrencyConfiguration`.

`1` means one attempt and no retry. A caller-owned transaction is never replayed
by GateTrail regardless of this value.

## Auditing

### Audit mode

| `audit.enabled` | `audit.strict` | Mode |
|---:|---:|---|
| `true` | `true` | Mutation and audit share one transaction; audit failure rolls the mutation back |
| `true` | `false` | Mutation commits first; audit is attempted separately and may yield a null audit identifier plus `AuditPersistenceFailed` |
| `false` | ignored | No GateTrail audit is written; every mutation result carries a null audit identifier |

`audit.enabled` must always be a real boolean. `audit.strict` must be a real
boolean when auditing is enabled. Missing, `null`, numeric and string values are
rejected with `InvalidAuditConfiguration`; `strict` is deliberately not read
while auditing is disabled.

Strict mode is the recommended and default mode. Non-strict and disabled modes
are explicit reductions of the package's history guarantee.

### Metadata limits

| Key | Rule |
|---|---|
| `audit.metadata.max_bytes` | Positive integer; maximum encoded JSON bytes before redaction |
| `audit.metadata.max_depth` | Positive integer; maximum JSON nesting depth |

Invalid limits throw `InvalidAuditMetadata`. Mutation metadata must be a
top-level associative array containing JSON-safe UTF-8 values. Objects,
resources, recursion, non-finite floats, invalid UTF-8 and the reserved
redaction marker are rejected.

## Sensitive metadata paths

The defaults are:

```php
[
    'password',
    'password_confirmation',
    'remember_token',
    'access_token',
    'refresh_token',
    'private_key',
    'session_id',
]
```

Each entry must be a non-empty string. A single segment such as `password`
redacts every matching key recursively. A dotted path such as
`payment.card.number` redacts only that exact nested path. An optional leading
`metadata.` is ignored, so `metadata.payment.card.number` has the same meaning.

Redaction replaces the value with GateTrail's reserved marker; it does not
remove the key. Metadata size is checked before redaction, so a large secret is
not a way to bypass `max_bytes`.

## Deployment checklist

Before caching configuration or provisioning a non-local environment:

1. confirm the Laravel connection and database driver;
2. choose package- or DBA-managed provisioning;
3. freeze schema, prefix, table and Oracle object names;
4. ensure every final identifier fits the 30-character portable limit;
5. align the configured Oracle generated-key strategy with installed DDL;
6. validate custom model compatibility;
7. define the default and subject-specific guard namespaces;
8. keep strict auditing unless the weaker guarantee is explicitly accepted;
9. review metadata limits and sensitive paths; and
10. rebuild Laravel's configuration cache through the normal deployment flow.

For runnable mutation and authorization examples, continue with the
[Consumer Guide](CONSUMER_GUIDE.md). For exact supported PHP symbols, use the
[Public API manifest](PUBLIC_API.md).

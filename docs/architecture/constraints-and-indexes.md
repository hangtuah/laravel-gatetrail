# GateTrail Constraint and Index Plan

- Status: Accepted baseline
- Date: 2026-07-28
- Depends on: Initial ERD, ADR 0001, ADR 0005

## 1. Purpose

This document defines the migration-level constraint and index baseline for the
seven GateTrail tables.

It is database-neutral. Adapter-specific DDL may differ, but every supported
database must preserve the behavior described here.

## 2. Conventions

- Every package-owned database identifier is explicitly named and no longer
  than 30 characters.
- Primary-key names in this document are canonical logical names. Oracle,
  PostgreSQL, and SQL Server use them physically. MySQL uses its reserved
  `PRIMARY` name, while SQLite validates primary-key columns and behavior
  without requiring a physical constraint name.
- Primary keys and required foreign keys are `NOT NULL`.
- Definition, assignment, and audit IDs use database-appropriate 64-bit
  compatible numeric types.
- Subject IDs use the initial numeric morph-ID strategy.
- Actor IDs use 191-character portable strings as accepted in ADR 0005.
- Role names, permission names, guards, morph types, and actor types use a
  maximum length of 191 characters.
- Role and permission name comparisons must preserve case and use exact
  matching, regardless of the database's default collation.
- Reasons, snapshots, context, and canonical scope keys use portable text/CLOB
  storage.
- Assignment history must never be removed through cascading deletes.
- Application validation remains required even when a matching database
  constraint exists.

### Migration dependency safety

Package migrations create and remove tables in this dependency-safe order:

```text
Up:
roles
→ permissions
→ assignment locks
→ role assignments
→ permission assignments
→ role permissions
→ access audits

Down:
access audits
→ role permissions
→ permission assignments
→ role assignments
→ assignment locks
→ permissions
→ roles
```

Each child migration checks that its required parent tables exist before
issuing child DDL. Parent rollback checks that dependent child tables are
already absent.

GateTrail does not disable foreign-key enforcement to bypass ordering errors
and does not silently skip an expected table through `hasTable()` during normal
creation.

## 3. `acl_roles`

### Nullability

| Column | Rule |
|---|---|
| `id` | `NOT NULL`, primary key |
| `name` | `NOT NULL`, non-empty after trimming |
| `guard_name` | `NOT NULL`, non-empty after trimming |
| `description` | Nullable |
| `created_at` | `NOT NULL` |
| `updated_at` | `NOT NULL` |
| `deleted_at` | Nullable |

### Constraints and indexes

| Name | Type | Columns or behavior |
|---|---|---|
| `acl_roles_pk` | Logical primary key | `id`; physical name is adapter-specific |
| `acl_role_name_guard_uq` | Unique | `name`, `guard_name` using exact case-sensitive comparison |

Soft-deleted rows remain inside the unique namespace so a definition can be
restored without colliding with a replacement using the same name and guard.

## 4. `acl_permissions`

### Nullability

| Column | Rule |
|---|---|
| `id` | `NOT NULL`, primary key |
| `name` | `NOT NULL`, non-empty after trimming |
| `guard_name` | `NOT NULL`, non-empty after trimming |
| `description` | Nullable |
| `created_at` | `NOT NULL` |
| `updated_at` | `NOT NULL` |
| `deleted_at` | Nullable |

### Constraints and indexes

| Name | Type | Columns or behavior |
|---|---|---|
| `acl_perms_pk` | Logical primary key | `id`; physical name is adapter-specific |
| `acl_perm_name_guard_uq` | Unique | `name`, `guard_name` using exact case-sensitive comparison |

## 5. Assignment-table shared rules

The three assignment tables share these rules:

- `id`, definition foreign keys, `assigned_at`, assigned actor pairs,
  `created_at`, and `updated_at` are required.
- `starts_at` is nullable and means the assignment starts at `assigned_at`.
- `expires_at` is nullable and means the assignment has no scheduled expiry.
- `expires_at`, when present, must be later than
  `COALESCE(starts_at, assigned_at)`.
- Assignment and revocation reasons are nullable at the database layer because
  configuration may relax the default reason requirement.
- `revoked_at`, revoked actor type, and revoked actor ID are either all null or
  all non-null.
- Assignment rows are not soft-deleted.
- No temporal unique index is created. ADR 0001 coordination rows and managed
  transactional overlap checks provide portable uniqueness.

Foreign keys use `RESTRICT` or the database-equivalent `NO ACTION` for delete
and update behavior. No assignment foreign key uses `CASCADE`.

The two actions are equivalent only while the constraint is `NOT DEFERRABLE`.
An engine that can postpone a `NO ACTION` check to commit time — PostgreSQL
does — makes a deferrable foreign key a weaker constraint than `RESTRICT`, so
every GateTrail foreign key is `NOT DEFERRABLE` and a deferrable one is schema
drift.

## 6. `acl_role_assignments`

### Table-specific nullability

| Column | Rule |
|---|---|
| `subject_type`, `subject_id` | `NOT NULL`, logical application reference |
| `role_id` | `NOT NULL`, physical role reference |
| `assignment_reason` | Nullable text/CLOB |
| `revocation_reason` | Nullable text/CLOB |

All other columns follow the shared assignment rules.

### Constraints and indexes

| Name | Type | Columns or behavior |
|---|---|---|
| `acl_role_asgn_pk` | Logical primary key | `id`; physical name is adapter-specific |
| `acl_ra_role_fk` | Foreign key | `role_id` → `acl_roles.id`, no cascade |
| `acl_ra_period_ck` | Check | Valid half-open assignment period |
| `acl_ra_revoke_ck` | Check | Revocation timestamp and actor pair change together |
| `acl_ra_subject_ix` | Index | `subject_type`, `subject_id`, `revoked_at`, `starts_at`, `expires_at` |
| `acl_ra_scope_ix` | Index | `subject_type`, `subject_id`, `role_id`, `revoked_at`, `starts_at`, `expires_at` |
| `acl_ra_role_ix` | Index | `role_id` |

## 7. `acl_permission_assignments`

### Table-specific nullability

| Column | Rule |
|---|---|
| `subject_type`, `subject_id` | `NOT NULL`, logical application reference |
| `permission_id` | `NOT NULL`, physical permission reference |
| `assignment_reason` | Nullable text/CLOB |
| `revocation_reason` | Nullable text/CLOB |

All other columns follow the shared assignment rules.

### Constraints and indexes

| Name | Type | Columns or behavior |
|---|---|---|
| `acl_perm_asgn_pk` | Logical primary key | `id`; physical name is adapter-specific |
| `acl_pa_perm_fk` | Foreign key | `permission_id` → `acl_permissions.id`, no cascade |
| `acl_pa_period_ck` | Check | Valid half-open assignment period |
| `acl_pa_revoke_ck` | Check | Revocation timestamp and actor pair change together |
| `acl_pa_subject_ix` | Index | `subject_type`, `subject_id`, `revoked_at`, `starts_at`, `expires_at` |
| `acl_pa_scope_ix` | Index | `subject_type`, `subject_id`, `permission_id`, `revoked_at`, `starts_at`, `expires_at` |
| `acl_pa_perm_ix` | Index | `permission_id` |

## 8. `acl_role_permissions`

### Table-specific nullability

| Column | Rule |
|---|---|
| `role_id`, `permission_id` | `NOT NULL`, physical definition references |
| `assignment_reason` | Nullable text/CLOB |
| `revocation_reason` | Nullable text/CLOB |

All other columns follow the shared assignment rules.

### Constraints and indexes

| Name | Type | Columns or behavior |
|---|---|---|
| `acl_role_perm_pk` | Logical primary key | `id`; physical name is adapter-specific |
| `acl_rp_role_fk` | Foreign key | `role_id` → `acl_roles.id`, no cascade |
| `acl_rp_perm_fk` | Foreign key | `permission_id` → `acl_permissions.id`, no cascade |
| `acl_rp_period_ck` | Check | Valid half-open assignment period |
| `acl_rp_revoke_ck` | Check | Revocation timestamp and actor pair change together |
| `acl_rp_role_ix` | Index | `role_id`, `revoked_at`, `starts_at`, `expires_at`, `permission_id` |
| `acl_rp_scope_ix` | Index | `role_id`, `permission_id`, `revoked_at`, `starts_at`, `expires_at` |
| `acl_rp_perm_ix` | Index | `permission_id` |

## 9. `acl_assignment_locks`

### Nullability

Every column is `NOT NULL`.

| Column | Additional rule |
|---|---|
| `assignment_kind` | Package-defined assignment-kind value |
| `scope_hash` | Fixed 64-character lowercase hexadecimal SHA-256 digest |
| `scope_key` | Canonical unhashed key in portable text/CLOB storage |
| `created_at`, `updated_at` | Required timestamps |

### Constraints and indexes

| Name | Type | Columns |
|---|---|---|
| `acl_asgn_locks_pk` | Logical composite primary key | `assignment_kind`, `scope_hash`; physical name is adapter-specific |

`scope_key` is not indexed. It exists for collision detection after lookup by
the composite primary key.

## 10. `acl_access_audits`

### Nullability

| Column | Rule |
|---|---|
| `id` | `NOT NULL`, primary key |
| `event` | `NOT NULL` |
| `actor_type`, `actor_id` | `NOT NULL`, logical actor reference |
| `subject_type`, `subject_id` | Both nullable; both present or both absent |
| `target_type`, `target_id` | `NOT NULL`, logical audited-target reference |
| `before`, `after` | Nullable portable text/CLOB |
| `reason`, `context` | Nullable portable text/CLOB |
| `ip_address`, `user_agent`, `request_id` | Nullable request metadata |
| `created_at` | `NOT NULL` |

### Constraints and indexes

| Name | Type | Columns or behavior |
|---|---|---|
| `acl_audit_pk` | Logical primary key | `id`; physical name is adapter-specific |
| `acl_audit_subject_ck` | Check | Subject type and ID are both null or both non-null |
| `acl_audit_actor_ix` | Index | `actor_type`, `actor_id`, `created_at` |
| `acl_audit_subject_ix` | Index | `subject_type`, `subject_id`, `created_at` |
| `acl_audit_target_ix` | Index | `target_type`, `target_id`, `created_at` |
| `acl_audit_event_ix` | Index | `event`, `created_at` |
| `acl_audit_request_ix` | Index | `request_id` |
| `acl_audit_created_ix` | Index | `created_at` |

`request_id` is not unique because one request may produce several audit
events.

## 11. Exact check semantics

Each assignment period check is logically equivalent to:

```sql
expires_at IS NULL
OR expires_at > COALESCE(starts_at, assigned_at)
```

Each assignment revocation check is logically equivalent to:

```sql
(
    revoked_at IS NULL
    AND revoked_by_type IS NULL
    AND revoked_by_id IS NULL
)
OR
(
    revoked_at IS NOT NULL
    AND revoked_by_type IS NOT NULL
    AND revoked_by_id IS NOT NULL
)
```

The audit subject-pair check is logically equivalent to:

```sql
(
    subject_type IS NULL
    AND subject_id IS NULL
)
OR
(
    subject_type IS NOT NULL
    AND subject_id IS NOT NULL
)
```

Migration adapters may express equivalent syntax where database functions
differ.

## 12. Deliberately absent constraints

- No FK targets application-owned subject or actor tables.
- No FK is added to polymorphic audit targets.
- No unique constraint is added directly to assignment history scopes.
- No cascade delete is permitted on assignment history.
- No native JSON constraint is required for snapshots or context.
- No index includes a text/CLOB column.

## 13. Adapter validation

Each Tier 1 database must prove:

1. every package-controlled physical identifier is 30 characters or fewer;
2. adapter-specific primary-key naming follows the accepted portability rule;
3. exact role and permission name uniqueness under its configured collation;
4. named check-constraint behavior;
5. non-cascading FK behavior;
6. use of the intended indexes for core resolver and overlap queries;
7. schema-qualified table support;
8. migration and rollback with explicit object names where the engine permits.

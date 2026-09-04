-- GateTrail schema release 0001
-- Database driver: sqlsrv
-- Direction: install
-- Generated from the canonical schema manifest; do not edit manually.

CREATE TABLE [acl_roles] (
    [id] BIGINT IDENTITY(1,1) NOT NULL,
    [name] NVARCHAR(191) COLLATE Latin1_General_100_BIN2 NOT NULL,
    [guard_name] NVARCHAR(191) COLLATE Latin1_General_100_BIN2 NOT NULL,
    [description] NVARCHAR(MAX) NULL,
    [created_at] DATETIME2(0) NOT NULL,
    [updated_at] DATETIME2(0) NOT NULL,
    [deleted_at] DATETIME2(0) NULL,
    CONSTRAINT [acl_roles_pk] PRIMARY KEY ([id]),
    CONSTRAINT [acl_role_name_guard_uq] UNIQUE ([name], [guard_name])
);

CREATE TABLE [acl_permissions] (
    [id] BIGINT IDENTITY(1,1) NOT NULL,
    [name] NVARCHAR(191) COLLATE Latin1_General_100_BIN2 NOT NULL,
    [guard_name] NVARCHAR(191) COLLATE Latin1_General_100_BIN2 NOT NULL,
    [description] NVARCHAR(MAX) NULL,
    [created_at] DATETIME2(0) NOT NULL,
    [updated_at] DATETIME2(0) NOT NULL,
    [deleted_at] DATETIME2(0) NULL,
    CONSTRAINT [acl_perms_pk] PRIMARY KEY ([id]),
    CONSTRAINT [acl_perm_name_guard_uq] UNIQUE ([name], [guard_name])
);

CREATE TABLE [acl_assignment_locks] (
    [assignment_kind] NVARCHAR(32) COLLATE Latin1_General_100_BIN2 NOT NULL,
    [scope_hash] NCHAR(64) COLLATE Latin1_General_100_BIN2 NOT NULL,
    [scope_key] NVARCHAR(MAX) NOT NULL,
    [created_at] DATETIME2(0) NOT NULL,
    [updated_at] DATETIME2(0) NOT NULL,
    CONSTRAINT [acl_asgn_locks_pk] PRIMARY KEY ([assignment_kind], [scope_hash])
);

CREATE TABLE [acl_role_assignments] (
    [id] BIGINT IDENTITY(1,1) NOT NULL,
    [subject_type] NVARCHAR(191) COLLATE Latin1_General_100_BIN2 NOT NULL,
    [subject_id] BIGINT NOT NULL,
    [role_id] BIGINT NOT NULL,
    [assigned_at] DATETIME2(0) NOT NULL,
    [assigned_by_type] NVARCHAR(191) COLLATE Latin1_General_100_BIN2 NOT NULL,
    [assigned_by_id] NVARCHAR(191) COLLATE Latin1_General_100_BIN2 NOT NULL,
    [assignment_reason] NVARCHAR(MAX) NULL,
    [starts_at] DATETIME2(0) NULL,
    [expires_at] DATETIME2(0) NULL,
    [revoked_at] DATETIME2(0) NULL,
    [revoked_by_type] NVARCHAR(191) COLLATE Latin1_General_100_BIN2 NULL,
    [revoked_by_id] NVARCHAR(191) COLLATE Latin1_General_100_BIN2 NULL,
    [revocation_reason] NVARCHAR(MAX) NULL,
    [created_at] DATETIME2(0) NOT NULL,
    [updated_at] DATETIME2(0) NOT NULL,
    CONSTRAINT [acl_role_asgn_pk] PRIMARY KEY ([id]),
    CONSTRAINT [acl_ra_role_fk] FOREIGN KEY ([role_id]) REFERENCES [acl_roles] ([id]) ON DELETE NO ACTION ON UPDATE NO ACTION,
    CONSTRAINT [acl_ra_period_ck] CHECK ((expires_at IS NULL) OR (expires_at > COALESCE(starts_at, assigned_at))),
    CONSTRAINT [acl_ra_revoke_ck] CHECK (((revoked_at IS NULL) AND (revoked_by_type IS NULL) AND (revoked_by_id IS NULL)) OR ((revoked_at IS NOT NULL) AND (revoked_by_type IS NOT NULL) AND (revoked_by_id IS NOT NULL)))
);

CREATE INDEX [acl_ra_subject_ix] ON [acl_role_assignments] ([subject_type], [subject_id], [revoked_at], [starts_at], [expires_at]);

CREATE INDEX [acl_ra_scope_ix] ON [acl_role_assignments] ([subject_type], [subject_id], [role_id], [revoked_at], [starts_at], [expires_at]);

CREATE INDEX [acl_ra_role_ix] ON [acl_role_assignments] ([role_id]);

CREATE TABLE [acl_permission_assignments] (
    [id] BIGINT IDENTITY(1,1) NOT NULL,
    [subject_type] NVARCHAR(191) COLLATE Latin1_General_100_BIN2 NOT NULL,
    [subject_id] BIGINT NOT NULL,
    [permission_id] BIGINT NOT NULL,
    [assigned_at] DATETIME2(0) NOT NULL,
    [assigned_by_type] NVARCHAR(191) COLLATE Latin1_General_100_BIN2 NOT NULL,
    [assigned_by_id] NVARCHAR(191) COLLATE Latin1_General_100_BIN2 NOT NULL,
    [assignment_reason] NVARCHAR(MAX) NULL,
    [starts_at] DATETIME2(0) NULL,
    [expires_at] DATETIME2(0) NULL,
    [revoked_at] DATETIME2(0) NULL,
    [revoked_by_type] NVARCHAR(191) COLLATE Latin1_General_100_BIN2 NULL,
    [revoked_by_id] NVARCHAR(191) COLLATE Latin1_General_100_BIN2 NULL,
    [revocation_reason] NVARCHAR(MAX) NULL,
    [created_at] DATETIME2(0) NOT NULL,
    [updated_at] DATETIME2(0) NOT NULL,
    CONSTRAINT [acl_perm_asgn_pk] PRIMARY KEY ([id]),
    CONSTRAINT [acl_pa_perm_fk] FOREIGN KEY ([permission_id]) REFERENCES [acl_permissions] ([id]) ON DELETE NO ACTION ON UPDATE NO ACTION,
    CONSTRAINT [acl_pa_period_ck] CHECK ((expires_at IS NULL) OR (expires_at > COALESCE(starts_at, assigned_at))),
    CONSTRAINT [acl_pa_revoke_ck] CHECK (((revoked_at IS NULL) AND (revoked_by_type IS NULL) AND (revoked_by_id IS NULL)) OR ((revoked_at IS NOT NULL) AND (revoked_by_type IS NOT NULL) AND (revoked_by_id IS NOT NULL)))
);

CREATE INDEX [acl_pa_subject_ix] ON [acl_permission_assignments] ([subject_type], [subject_id], [revoked_at], [starts_at], [expires_at]);

CREATE INDEX [acl_pa_scope_ix] ON [acl_permission_assignments] ([subject_type], [subject_id], [permission_id], [revoked_at], [starts_at], [expires_at]);

CREATE INDEX [acl_pa_perm_ix] ON [acl_permission_assignments] ([permission_id]);

CREATE TABLE [acl_role_permissions] (
    [id] BIGINT IDENTITY(1,1) NOT NULL,
    [role_id] BIGINT NOT NULL,
    [permission_id] BIGINT NOT NULL,
    [assigned_at] DATETIME2(0) NOT NULL,
    [assigned_by_type] NVARCHAR(191) COLLATE Latin1_General_100_BIN2 NOT NULL,
    [assigned_by_id] NVARCHAR(191) COLLATE Latin1_General_100_BIN2 NOT NULL,
    [assignment_reason] NVARCHAR(MAX) NULL,
    [starts_at] DATETIME2(0) NULL,
    [expires_at] DATETIME2(0) NULL,
    [revoked_at] DATETIME2(0) NULL,
    [revoked_by_type] NVARCHAR(191) COLLATE Latin1_General_100_BIN2 NULL,
    [revoked_by_id] NVARCHAR(191) COLLATE Latin1_General_100_BIN2 NULL,
    [revocation_reason] NVARCHAR(MAX) NULL,
    [created_at] DATETIME2(0) NOT NULL,
    [updated_at] DATETIME2(0) NOT NULL,
    CONSTRAINT [acl_role_perm_pk] PRIMARY KEY ([id]),
    CONSTRAINT [acl_rp_role_fk] FOREIGN KEY ([role_id]) REFERENCES [acl_roles] ([id]) ON DELETE NO ACTION ON UPDATE NO ACTION,
    CONSTRAINT [acl_rp_perm_fk] FOREIGN KEY ([permission_id]) REFERENCES [acl_permissions] ([id]) ON DELETE NO ACTION ON UPDATE NO ACTION,
    CONSTRAINT [acl_rp_period_ck] CHECK ((expires_at IS NULL) OR (expires_at > COALESCE(starts_at, assigned_at))),
    CONSTRAINT [acl_rp_revoke_ck] CHECK (((revoked_at IS NULL) AND (revoked_by_type IS NULL) AND (revoked_by_id IS NULL)) OR ((revoked_at IS NOT NULL) AND (revoked_by_type IS NOT NULL) AND (revoked_by_id IS NOT NULL)))
);

CREATE INDEX [acl_rp_role_ix] ON [acl_role_permissions] ([role_id], [revoked_at], [starts_at], [expires_at], [permission_id]);

CREATE INDEX [acl_rp_scope_ix] ON [acl_role_permissions] ([role_id], [permission_id], [revoked_at], [starts_at], [expires_at]);

CREATE INDEX [acl_rp_perm_ix] ON [acl_role_permissions] ([permission_id]);

CREATE TABLE [acl_access_audits] (
    [id] BIGINT IDENTITY(1,1) NOT NULL,
    [event] NVARCHAR(191) COLLATE Latin1_General_100_BIN2 NOT NULL,
    [actor_type] NVARCHAR(191) COLLATE Latin1_General_100_BIN2 NOT NULL,
    [actor_id] NVARCHAR(191) COLLATE Latin1_General_100_BIN2 NOT NULL,
    [subject_type] NVARCHAR(191) COLLATE Latin1_General_100_BIN2 NULL,
    [subject_id] BIGINT NULL,
    [target_type] NVARCHAR(191) COLLATE Latin1_General_100_BIN2 NOT NULL,
    [target_id] BIGINT NOT NULL,
    [before] NVARCHAR(MAX) NULL,
    [after] NVARCHAR(MAX) NULL,
    [reason] NVARCHAR(MAX) NULL,
    [context] NVARCHAR(MAX) NULL,
    [ip_address] NVARCHAR(45) NULL,
    [user_agent] NVARCHAR(MAX) NULL,
    [request_id] NVARCHAR(191) NULL,
    [created_at] DATETIME2(0) NOT NULL,
    CONSTRAINT [acl_audit_pk] PRIMARY KEY ([id]),
    CONSTRAINT [acl_audit_subject_ck] CHECK (((subject_type IS NULL) AND (subject_id IS NULL)) OR ((subject_type IS NOT NULL) AND (subject_id IS NOT NULL)))
);

CREATE INDEX [acl_audit_actor_ix] ON [acl_access_audits] ([actor_type], [actor_id], [created_at]);

CREATE INDEX [acl_audit_subject_ix] ON [acl_access_audits] ([subject_type], [subject_id], [created_at]);

CREATE INDEX [acl_audit_target_ix] ON [acl_access_audits] ([target_type], [target_id], [created_at]);

CREATE INDEX [acl_audit_event_ix] ON [acl_access_audits] ([event], [created_at]);

CREATE INDEX [acl_audit_request_ix] ON [acl_access_audits] ([request_id]);

CREATE INDEX [acl_audit_created_ix] ON [acl_access_audits] ([created_at]);

-- GateTrail schema release 0001
-- Database driver: oracle
-- Generated-key strategy: sequence_trigger
-- Direction: install
-- Generated from the canonical schema manifest; do not edit manually.

CREATE TABLE acl_roles (
    id NUMBER(19,0) NOT NULL,
    name VARCHAR2(191 CHAR) NOT NULL,
    guard_name VARCHAR2(191 CHAR) NOT NULL,
    description CLOB NULL,
    created_at TIMESTAMP(6) NOT NULL,
    updated_at TIMESTAMP(6) NOT NULL,
    deleted_at TIMESTAMP(6) NULL,
    CONSTRAINT acl_roles_pk PRIMARY KEY (id),
    CONSTRAINT acl_role_name_guard_uq UNIQUE (name, guard_name)
);

CREATE SEQUENCE acl_roles_seq START WITH 1 INCREMENT BY 1 NOCYCLE CACHE 20;

CREATE TRIGGER acl_roles_trg
BEFORE INSERT ON acl_roles
FOR EACH ROW
BEGIN
    IF :NEW.id IS NULL THEN
        :NEW.id := acl_roles_seq.NEXTVAL;
    END IF;
END;
/

CREATE TABLE acl_permissions (
    id NUMBER(19,0) NOT NULL,
    name VARCHAR2(191 CHAR) NOT NULL,
    guard_name VARCHAR2(191 CHAR) NOT NULL,
    description CLOB NULL,
    created_at TIMESTAMP(6) NOT NULL,
    updated_at TIMESTAMP(6) NOT NULL,
    deleted_at TIMESTAMP(6) NULL,
    CONSTRAINT acl_perms_pk PRIMARY KEY (id),
    CONSTRAINT acl_perm_name_guard_uq UNIQUE (name, guard_name)
);

CREATE SEQUENCE acl_perms_seq START WITH 1 INCREMENT BY 1 NOCYCLE CACHE 20;

CREATE TRIGGER acl_perms_trg
BEFORE INSERT ON acl_permissions
FOR EACH ROW
BEGIN
    IF :NEW.id IS NULL THEN
        :NEW.id := acl_perms_seq.NEXTVAL;
    END IF;
END;
/

CREATE TABLE acl_assignment_locks (
    assignment_kind VARCHAR2(32 CHAR) NOT NULL,
    scope_hash CHAR(64 CHAR) NOT NULL,
    scope_key CLOB NOT NULL,
    created_at TIMESTAMP(6) NOT NULL,
    updated_at TIMESTAMP(6) NOT NULL,
    CONSTRAINT acl_asgn_locks_pk PRIMARY KEY (assignment_kind, scope_hash)
);

CREATE TABLE acl_role_assignments (
    id NUMBER(19,0) NOT NULL,
    subject_type VARCHAR2(191 CHAR) NOT NULL,
    subject_id NUMBER(19,0) NOT NULL,
    role_id NUMBER(19,0) NOT NULL,
    assigned_at TIMESTAMP(6) NOT NULL,
    assigned_by_type VARCHAR2(191 CHAR) NOT NULL,
    assigned_by_id VARCHAR2(191 CHAR) NOT NULL,
    assignment_reason CLOB NULL,
    starts_at TIMESTAMP(6) NULL,
    expires_at TIMESTAMP(6) NULL,
    revoked_at TIMESTAMP(6) NULL,
    revoked_by_type VARCHAR2(191 CHAR) NULL,
    revoked_by_id VARCHAR2(191 CHAR) NULL,
    revocation_reason CLOB NULL,
    created_at TIMESTAMP(6) NOT NULL,
    updated_at TIMESTAMP(6) NOT NULL,
    CONSTRAINT acl_role_asgn_pk PRIMARY KEY (id),
    CONSTRAINT acl_ra_role_fk FOREIGN KEY (role_id) REFERENCES acl_roles (id),
    CONSTRAINT acl_ra_period_ck CHECK ((expires_at IS NULL) OR (expires_at > COALESCE(starts_at, assigned_at))),
    CONSTRAINT acl_ra_revoke_ck CHECK (((revoked_at IS NULL) AND (revoked_by_type IS NULL) AND (revoked_by_id IS NULL)) OR ((revoked_at IS NOT NULL) AND (revoked_by_type IS NOT NULL) AND (revoked_by_id IS NOT NULL)))
);

CREATE INDEX acl_ra_subject_ix ON acl_role_assignments (subject_type, subject_id, revoked_at, starts_at, expires_at);

CREATE INDEX acl_ra_scope_ix ON acl_role_assignments (subject_type, subject_id, role_id, revoked_at, starts_at, expires_at);

CREATE INDEX acl_ra_role_ix ON acl_role_assignments (role_id);

CREATE SEQUENCE acl_role_asgn_seq START WITH 1 INCREMENT BY 1 NOCYCLE CACHE 20;

CREATE TRIGGER acl_role_asgn_trg
BEFORE INSERT ON acl_role_assignments
FOR EACH ROW
BEGIN
    IF :NEW.id IS NULL THEN
        :NEW.id := acl_role_asgn_seq.NEXTVAL;
    END IF;
END;
/

CREATE TABLE acl_permission_assignments (
    id NUMBER(19,0) NOT NULL,
    subject_type VARCHAR2(191 CHAR) NOT NULL,
    subject_id NUMBER(19,0) NOT NULL,
    permission_id NUMBER(19,0) NOT NULL,
    assigned_at TIMESTAMP(6) NOT NULL,
    assigned_by_type VARCHAR2(191 CHAR) NOT NULL,
    assigned_by_id VARCHAR2(191 CHAR) NOT NULL,
    assignment_reason CLOB NULL,
    starts_at TIMESTAMP(6) NULL,
    expires_at TIMESTAMP(6) NULL,
    revoked_at TIMESTAMP(6) NULL,
    revoked_by_type VARCHAR2(191 CHAR) NULL,
    revoked_by_id VARCHAR2(191 CHAR) NULL,
    revocation_reason CLOB NULL,
    created_at TIMESTAMP(6) NOT NULL,
    updated_at TIMESTAMP(6) NOT NULL,
    CONSTRAINT acl_perm_asgn_pk PRIMARY KEY (id),
    CONSTRAINT acl_pa_perm_fk FOREIGN KEY (permission_id) REFERENCES acl_permissions (id),
    CONSTRAINT acl_pa_period_ck CHECK ((expires_at IS NULL) OR (expires_at > COALESCE(starts_at, assigned_at))),
    CONSTRAINT acl_pa_revoke_ck CHECK (((revoked_at IS NULL) AND (revoked_by_type IS NULL) AND (revoked_by_id IS NULL)) OR ((revoked_at IS NOT NULL) AND (revoked_by_type IS NOT NULL) AND (revoked_by_id IS NOT NULL)))
);

CREATE INDEX acl_pa_subject_ix ON acl_permission_assignments (subject_type, subject_id, revoked_at, starts_at, expires_at);

CREATE INDEX acl_pa_scope_ix ON acl_permission_assignments (subject_type, subject_id, permission_id, revoked_at, starts_at, expires_at);

CREATE INDEX acl_pa_perm_ix ON acl_permission_assignments (permission_id);

CREATE SEQUENCE acl_perm_asgn_seq START WITH 1 INCREMENT BY 1 NOCYCLE CACHE 20;

CREATE TRIGGER acl_perm_asgn_trg
BEFORE INSERT ON acl_permission_assignments
FOR EACH ROW
BEGIN
    IF :NEW.id IS NULL THEN
        :NEW.id := acl_perm_asgn_seq.NEXTVAL;
    END IF;
END;
/

CREATE TABLE acl_role_permissions (
    id NUMBER(19,0) NOT NULL,
    role_id NUMBER(19,0) NOT NULL,
    permission_id NUMBER(19,0) NOT NULL,
    assigned_at TIMESTAMP(6) NOT NULL,
    assigned_by_type VARCHAR2(191 CHAR) NOT NULL,
    assigned_by_id VARCHAR2(191 CHAR) NOT NULL,
    assignment_reason CLOB NULL,
    starts_at TIMESTAMP(6) NULL,
    expires_at TIMESTAMP(6) NULL,
    revoked_at TIMESTAMP(6) NULL,
    revoked_by_type VARCHAR2(191 CHAR) NULL,
    revoked_by_id VARCHAR2(191 CHAR) NULL,
    revocation_reason CLOB NULL,
    created_at TIMESTAMP(6) NOT NULL,
    updated_at TIMESTAMP(6) NOT NULL,
    CONSTRAINT acl_role_perm_pk PRIMARY KEY (id),
    CONSTRAINT acl_rp_role_fk FOREIGN KEY (role_id) REFERENCES acl_roles (id),
    CONSTRAINT acl_rp_perm_fk FOREIGN KEY (permission_id) REFERENCES acl_permissions (id),
    CONSTRAINT acl_rp_period_ck CHECK ((expires_at IS NULL) OR (expires_at > COALESCE(starts_at, assigned_at))),
    CONSTRAINT acl_rp_revoke_ck CHECK (((revoked_at IS NULL) AND (revoked_by_type IS NULL) AND (revoked_by_id IS NULL)) OR ((revoked_at IS NOT NULL) AND (revoked_by_type IS NOT NULL) AND (revoked_by_id IS NOT NULL)))
);

CREATE INDEX acl_rp_role_ix ON acl_role_permissions (role_id, revoked_at, starts_at, expires_at, permission_id);

CREATE INDEX acl_rp_scope_ix ON acl_role_permissions (role_id, permission_id, revoked_at, starts_at, expires_at);

CREATE INDEX acl_rp_perm_ix ON acl_role_permissions (permission_id);

CREATE SEQUENCE acl_role_perm_seq START WITH 1 INCREMENT BY 1 NOCYCLE CACHE 20;

CREATE TRIGGER acl_role_perm_trg
BEFORE INSERT ON acl_role_permissions
FOR EACH ROW
BEGIN
    IF :NEW.id IS NULL THEN
        :NEW.id := acl_role_perm_seq.NEXTVAL;
    END IF;
END;
/

CREATE TABLE acl_access_audits (
    id NUMBER(19,0) NOT NULL,
    event VARCHAR2(191 CHAR) NOT NULL,
    actor_type VARCHAR2(191 CHAR) NOT NULL,
    actor_id VARCHAR2(191 CHAR) NOT NULL,
    subject_type VARCHAR2(191 CHAR) NULL,
    subject_id NUMBER(19,0) NULL,
    target_type VARCHAR2(191 CHAR) NOT NULL,
    target_id NUMBER(19,0) NOT NULL,
    before CLOB NULL,
    after CLOB NULL,
    reason CLOB NULL,
    context CLOB NULL,
    ip_address VARCHAR2(45 CHAR) NULL,
    user_agent CLOB NULL,
    request_id VARCHAR2(191 CHAR) NULL,
    created_at TIMESTAMP(6) NOT NULL,
    CONSTRAINT acl_audit_pk PRIMARY KEY (id),
    CONSTRAINT acl_audit_subject_ck CHECK (((subject_type IS NULL) AND (subject_id IS NULL)) OR ((subject_type IS NOT NULL) AND (subject_id IS NOT NULL)))
);

CREATE INDEX acl_audit_actor_ix ON acl_access_audits (actor_type, actor_id, created_at);

CREATE INDEX acl_audit_subject_ix ON acl_access_audits (subject_type, subject_id, created_at);

CREATE INDEX acl_audit_target_ix ON acl_access_audits (target_type, target_id, created_at);

CREATE INDEX acl_audit_event_ix ON acl_access_audits (event, created_at);

CREATE INDEX acl_audit_request_ix ON acl_access_audits (request_id);

CREATE INDEX acl_audit_created_ix ON acl_access_audits (created_at);

CREATE SEQUENCE acl_audit_seq START WITH 1 INCREMENT BY 1 NOCYCLE CACHE 20;

CREATE TRIGGER acl_audit_trg
BEFORE INSERT ON acl_access_audits
FOR EACH ROW
BEGIN
    IF :NEW.id IS NULL THEN
        :NEW.id := acl_audit_seq.NEXTVAL;
    END IF;
END;
/

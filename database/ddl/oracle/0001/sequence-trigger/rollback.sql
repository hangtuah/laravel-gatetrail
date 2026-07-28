-- GateTrail schema release 0001
-- Database driver: oracle
-- Generated-key strategy: sequence_trigger
-- Direction: rollback
-- Generated from the canonical schema manifest; do not edit manually.

DROP TRIGGER acl_audit_trg;

DROP TRIGGER acl_role_perm_trg;

DROP TRIGGER acl_perm_asgn_trg;

DROP TRIGGER acl_role_asgn_trg;

DROP TRIGGER acl_perms_trg;

DROP TRIGGER acl_roles_trg;

DROP TABLE acl_access_audits;

DROP TABLE acl_role_permissions;

DROP TABLE acl_permission_assignments;

DROP TABLE acl_role_assignments;

DROP TABLE acl_assignment_locks;

DROP TABLE acl_permissions;

DROP TABLE acl_roles;

DROP SEQUENCE acl_audit_seq;

DROP SEQUENCE acl_role_perm_seq;

DROP SEQUENCE acl_perm_asgn_seq;

DROP SEQUENCE acl_role_asgn_seq;

DROP SEQUENCE acl_perms_seq;

DROP SEQUENCE acl_roles_seq;

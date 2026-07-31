<?php

declare(strict_types=1);

use HangTuah\GateTrail\Models\AccessAudit;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\PermissionAssignment;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\Models\RoleAssignment;
use HangTuah\GateTrail\Models\RolePermission;

return [
    'database' => [
        'connection' => null,
        'schema' => null,
        'prefix' => null,
        'provisioning' => 'package',
    ],

    'tables' => [
        'roles' => 'acl_roles',
        'permissions' => 'acl_permissions',
        'role_assignments' => 'acl_role_assignments',
        'permission_assignments' => 'acl_permission_assignments',
        'role_permissions' => 'acl_role_permissions',
        'assignment_locks' => 'acl_assignment_locks',
        'access_audits' => 'acl_access_audits',
    ],

    'models' => [
        'role' => Role::class,
        'permission' => Permission::class,
        'role_assignment' => RoleAssignment::class,
        'permission_assignment' => PermissionAssignment::class,
        'role_permission' => RolePermission::class,
        'access_audit' => AccessAudit::class,
    ],

    'oracle' => [
        'generated_keys' => 'sequence_trigger',

        'sequences' => [
            'roles' => 'acl_roles_seq',
            'permissions' => 'acl_perms_seq',
            'role_assignments' => 'acl_role_asgn_seq',
            'permission_assignments' => 'acl_perm_asgn_seq',
            'role_permissions' => 'acl_role_perm_seq',
            'access_audits' => 'acl_audit_seq',
        ],

        'triggers' => [
            'roles' => 'acl_roles_trg',
            'permissions' => 'acl_perms_trg',
            'role_assignments' => 'acl_role_asgn_trg',
            'permission_assignments' => 'acl_perm_asgn_trg',
            'role_permissions' => 'acl_role_perm_trg',
            'access_audits' => 'acl_audit_trg',
        ],
    ],

    'guards' => [
        'default' => null,
        'subjects' => [],
    ],

    'assignments' => [
        'require_reason' => true,
        'concurrency_attempts' => 3,
    ],

    'definitions' => [
        'require_reason' => false,
    ],

    /*
     * Strict auditing writes the mutation and its audit record in one
     * transaction, so an audit failure rolls the mutation back.
     *
     * Setting 'strict' => false is an explicit operational trade-off: the
     * mutation commits first and the audit is written afterwards in its own
     * transaction. A mutation can therefore succeed with no audit evidence.
     * When that happens GateTrail returns a null audit identifier, writes one
     * error-level log, and dispatches AuditPersistenceFailed. It never throws
     * after the commit and never retries the mutation. Inside a caller-owned
     * transaction the audit waits for the outermost commit, so the immediate
     * result also carries a null audit identifier.
     *
     * Setting 'enabled' => false switches auditing off entirely and is the
     * strongest warning in this file: mutations still validate, lock, and
     * persist normally, but GateTrail writes no history at all. There is no
     * record of who changed which role, permission, or assignment, when, or
     * why, and nothing reconstructs that history later. Every mutation returns
     * a null audit identifier. 'strict' has no effect while auditing is off.
     * Choose this only when another system already records authorization
     * changes; GateTrail never switches to it by itself, including after an
     * audit failure.
     *
     * Validation is fail-closed, and it is evaluated in that order:
     *
     * - 'enabled' must always be a real boolean. A missing key, null, "true",
     *   "false", 0, 1, an array, or an object is rejected with
     *   InvalidAuditConfiguration before a mutation runs.
     * - When 'enabled' is true, 'strict' must also be a real boolean, and is
     *   rejected on the same terms.
     * - When 'enabled' is false, 'strict' has no operational effect and is
     *   never consulted, so an invalid value there is not reported.
     *
     * A typo in a flag that matters therefore fails loudly instead of quietly
     * downgrading auditing.
     */
    'audit' => [
        'enabled' => true,
        'strict' => true,

        'metadata' => [
            'max_bytes' => 16384,
            'max_depth' => 8,
        ],
    ],

    'sensitive_fields' => [
        'password',
        'password_confirmation',
        'remember_token',
        'access_token',
        'refresh_token',
        'private_key',
        'session_id',
    ],
];

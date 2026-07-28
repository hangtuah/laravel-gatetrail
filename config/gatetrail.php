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

    'audit' => [
        'enabled' => true,
        'strict' => true,
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

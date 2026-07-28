<?php

declare(strict_types=1);

use HangTuah\GateTrail\Database\Migrations\AssignmentTableMigration;

return new class extends AssignmentTableMigration
{
    public function up(): void
    {
        $this->prepareSchema();
        $this->requireParentTables('role_permissions', [
            'roles',
            'permissions',
        ]);

        if ($this->installOracleTable('role_permissions')) {
            return;
        }

        $this->createAssignmentTable(
            tableKey: 'role_permissions',
            identityColumns: [
                'role_id' => 'integer',
                'permission_id' => 'integer',
            ],
            foreignKeys: [
                'role_id' => [
                    'parent' => 'roles',
                    'constraint' => 'acl_rp_role_fk',
                ],
                'permission_id' => [
                    'parent' => 'permissions',
                    'constraint' => 'acl_rp_perm_fk',
                ],
            ],
            indexes: [
                'acl_rp_role_ix' => [
                    'role_id',
                    'revoked_at',
                    'starts_at',
                    'expires_at',
                    'permission_id',
                ],
                'acl_rp_scope_ix' => [
                    'role_id',
                    'permission_id',
                    'revoked_at',
                    'starts_at',
                    'expires_at',
                ],
                'acl_rp_perm_ix' => ['permission_id'],
            ],
            periodConstraint: 'acl_rp_period_ck',
            revokeConstraint: 'acl_rp_revoke_ck',
        );
    }

    public function down(): void
    {
        if ($this->rollbackOracleTable('role_permissions')) {
            return;
        }

        $this->schema()->drop(
            $this->context()->table('role_permissions'),
        );
    }
};

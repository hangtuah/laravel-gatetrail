<?php

declare(strict_types=1);

use HangTuah\GateTrail\Database\Migrations\AssignmentTableMigration;

return new class extends AssignmentTableMigration
{
    public function up(): void
    {
        $this->prepareSchema();
        $this->requireParentTables('permission_assignments', ['permissions']);

        if ($this->installOracleTable('permission_assignments')) {
            return;
        }

        $this->createAssignmentTable(
            tableKey: 'permission_assignments',
            identityColumns: [
                'subject_type' => 'string',
                'subject_id' => 'integer',
                'permission_id' => 'integer',
            ],
            foreignKeys: [
                'permission_id' => [
                    'parent' => 'permissions',
                    'constraint' => 'acl_pa_perm_fk',
                ],
            ],
            indexes: [
                'acl_pa_subject_ix' => [
                    'subject_type',
                    'subject_id',
                    'revoked_at',
                    'starts_at',
                    'expires_at',
                ],
                'acl_pa_scope_ix' => [
                    'subject_type',
                    'subject_id',
                    'permission_id',
                    'revoked_at',
                    'starts_at',
                    'expires_at',
                ],
                'acl_pa_perm_ix' => ['permission_id'],
            ],
            periodConstraint: 'acl_pa_period_ck',
            revokeConstraint: 'acl_pa_revoke_ck',
        );
    }

    public function down(): void
    {
        if ($this->rollbackOracleTable('permission_assignments')) {
            return;
        }

        $this->schema()->drop(
            $this->context()->table('permission_assignments'),
        );
    }
};

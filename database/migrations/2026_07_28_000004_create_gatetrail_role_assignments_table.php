<?php

declare(strict_types=1);

use HangTuah\GateTrail\Database\Migrations\AssignmentTableMigration;

return new class extends AssignmentTableMigration
{
    public function up(): void
    {
        $this->prepareSchema();
        $this->requireParentTables('role_assignments', ['roles']);

        $this->createAssignmentTable(
            tableKey: 'role_assignments',
            identityColumns: [
                'subject_type' => 'string',
                'subject_id' => 'integer',
                'role_id' => 'integer',
            ],
            foreignKeys: [
                'role_id' => [
                    'parent' => 'roles',
                    'constraint' => 'acl_ra_role_fk',
                ],
            ],
            indexes: [
                'acl_ra_subject_ix' => [
                    'subject_type',
                    'subject_id',
                    'revoked_at',
                    'starts_at',
                    'expires_at',
                ],
                'acl_ra_scope_ix' => [
                    'subject_type',
                    'subject_id',
                    'role_id',
                    'revoked_at',
                    'starts_at',
                    'expires_at',
                ],
                'acl_ra_role_ix' => ['role_id'],
            ],
            periodConstraint: 'acl_ra_period_ck',
            revokeConstraint: 'acl_ra_revoke_ck',
        );
    }

    public function down(): void
    {
        $this->schema()->drop(
            $this->context()->table('role_assignments'),
        );
    }
};

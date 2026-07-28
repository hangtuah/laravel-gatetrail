<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Manifest;

use InvalidArgumentException;

final readonly class SchemaManifest
{
    /**
     * @param  list<TableDefinition>  $tables
     */
    public function __construct(
        private array $tables,
    ) {}

    public static function gateTrail(): self
    {
        return new self([
            self::roles(),
            self::permissions(),
            self::assignmentLocks(),
            self::roleAssignments(),
            self::permissionAssignments(),
            self::rolePermissions(),
            self::accessAudits(),
        ]);
    }

    /**
     * @return list<TableDefinition>
     */
    public function tables(): array
    {
        return $this->tables;
    }

    public function table(string $key): TableDefinition
    {
        foreach ($this->tables as $table) {
            if ($table->key === $key) {
                return $table;
            }
        }

        throw new InvalidArgumentException(
            sprintf('Unknown GateTrail manifest table [%s].', $key),
        );
    }

    /**
     * @return list<string>
     */
    public function creationOrder(): array
    {
        return array_map(
            static fn (TableDefinition $table): string => $table->key,
            $this->tables,
        );
    }

    /**
     * @return list<string>
     */
    public function rollbackOrder(): array
    {
        return array_reverse($this->creationOrder());
    }

    private static function roles(): TableDefinition
    {
        return new TableDefinition(
            key: 'roles',
            migration: '2026_07_28_000001_create_gatetrail_roles_table.php',
            columns: self::definitionColumns(),
            constraints: [
                self::primary('acl_roles_pk'),
                self::unique(
                    'acl_role_name_guard_uq',
                    ['name', 'guard_name'],
                ),
            ],
        );
    }

    private static function permissions(): TableDefinition
    {
        return new TableDefinition(
            key: 'permissions',
            migration: '2026_07_28_000002_create_gatetrail_permissions_table.php',
            columns: self::definitionColumns(),
            constraints: [
                self::primary('acl_perms_pk'),
                self::unique(
                    'acl_perm_name_guard_uq',
                    ['name', 'guard_name'],
                ),
            ],
        );
    }

    private static function assignmentLocks(): TableDefinition
    {
        return new TableDefinition(
            key: 'assignment_locks',
            migration: '2026_07_28_000003_create_gatetrail_assignment_locks_table.php',
            columns: [
                self::string('assignment_kind', 32, exact: true),
                self::string('scope_hash', 64, exact: true),
                self::text('scope_key'),
                self::dateTime('created_at'),
                self::dateTime('updated_at'),
            ],
            constraints: [
                new ConstraintDefinition(
                    name: 'acl_asgn_locks_pk',
                    type: ConstraintType::Primary,
                    columns: ['assignment_kind', 'scope_hash'],
                ),
            ],
        );
    }

    private static function roleAssignments(): TableDefinition
    {
        return new TableDefinition(
            key: 'role_assignments',
            migration: '2026_07_28_000004_create_gatetrail_role_assignments_table.php',
            columns: self::assignmentColumns([
                self::string('subject_type', exact: true),
                self::bigInteger('subject_id'),
                self::bigInteger('role_id'),
            ]),
            constraints: [
                self::primary('acl_role_asgn_pk'),
                self::foreign('acl_ra_role_fk', 'role_id', 'roles'),
                self::periodCheck('acl_ra_period_ck'),
                self::revokeCheck('acl_ra_revoke_ck'),
            ],
            indexes: [
                new IndexDefinition(
                    'acl_ra_subject_ix',
                    ['subject_type', 'subject_id', 'revoked_at', 'starts_at', 'expires_at'],
                ),
                new IndexDefinition(
                    'acl_ra_scope_ix',
                    ['subject_type', 'subject_id', 'role_id', 'revoked_at', 'starts_at', 'expires_at'],
                ),
                new IndexDefinition('acl_ra_role_ix', ['role_id']),
            ],
            dependencies: ['roles'],
        );
    }

    private static function permissionAssignments(): TableDefinition
    {
        return new TableDefinition(
            key: 'permission_assignments',
            migration: '2026_07_28_000005_create_gatetrail_permission_assignments_table.php',
            columns: self::assignmentColumns([
                self::string('subject_type', exact: true),
                self::bigInteger('subject_id'),
                self::bigInteger('permission_id'),
            ]),
            constraints: [
                self::primary('acl_perm_asgn_pk'),
                self::foreign(
                    'acl_pa_perm_fk',
                    'permission_id',
                    'permissions',
                ),
                self::periodCheck('acl_pa_period_ck'),
                self::revokeCheck('acl_pa_revoke_ck'),
            ],
            indexes: [
                new IndexDefinition(
                    'acl_pa_subject_ix',
                    ['subject_type', 'subject_id', 'revoked_at', 'starts_at', 'expires_at'],
                ),
                new IndexDefinition(
                    'acl_pa_scope_ix',
                    ['subject_type', 'subject_id', 'permission_id', 'revoked_at', 'starts_at', 'expires_at'],
                ),
                new IndexDefinition('acl_pa_perm_ix', ['permission_id']),
            ],
            dependencies: ['permissions'],
        );
    }

    private static function rolePermissions(): TableDefinition
    {
        return new TableDefinition(
            key: 'role_permissions',
            migration: '2026_07_28_000006_create_gatetrail_role_permissions_table.php',
            columns: self::assignmentColumns([
                self::bigInteger('role_id'),
                self::bigInteger('permission_id'),
            ]),
            constraints: [
                self::primary('acl_role_perm_pk'),
                self::foreign('acl_rp_role_fk', 'role_id', 'roles'),
                self::foreign(
                    'acl_rp_perm_fk',
                    'permission_id',
                    'permissions',
                ),
                self::periodCheck('acl_rp_period_ck'),
                self::revokeCheck('acl_rp_revoke_ck'),
            ],
            indexes: [
                new IndexDefinition(
                    'acl_rp_role_ix',
                    ['role_id', 'revoked_at', 'starts_at', 'expires_at', 'permission_id'],
                ),
                new IndexDefinition(
                    'acl_rp_scope_ix',
                    ['role_id', 'permission_id', 'revoked_at', 'starts_at', 'expires_at'],
                ),
                new IndexDefinition('acl_rp_perm_ix', ['permission_id']),
            ],
            dependencies: ['roles', 'permissions'],
        );
    }

    private static function accessAudits(): TableDefinition
    {
        return new TableDefinition(
            key: 'access_audits',
            migration: '2026_07_28_000007_create_gatetrail_access_audits_table.php',
            columns: [
                self::bigInteger('id', generated: true),
                self::string('event', exact: true),
                self::string('actor_type', exact: true),
                self::string('actor_id', exact: true),
                self::string('subject_type', nullable: true, exact: true),
                self::bigInteger('subject_id', nullable: true),
                self::string('target_type', exact: true),
                self::bigInteger('target_id'),
                self::text('before', nullable: true),
                self::text('after', nullable: true),
                self::text('reason', nullable: true),
                self::text('context', nullable: true),
                self::string('ip_address', length: 45, nullable: true),
                self::text('user_agent', nullable: true),
                self::string('request_id', nullable: true),
                self::dateTime('created_at'),
            ],
            constraints: [
                self::primary('acl_audit_pk'),
                new ConstraintDefinition(
                    name: 'acl_audit_subject_ck',
                    type: ConstraintType::Check,
                    columns: ['subject_type', 'subject_id'],
                    expression: '(subject_type IS NULL AND subject_id IS NULL) '
                        .'OR (subject_type IS NOT NULL AND subject_id IS NOT NULL)',
                ),
            ],
            indexes: [
                new IndexDefinition(
                    'acl_audit_actor_ix',
                    ['actor_type', 'actor_id', 'created_at'],
                ),
                new IndexDefinition(
                    'acl_audit_subject_ix',
                    ['subject_type', 'subject_id', 'created_at'],
                ),
                new IndexDefinition(
                    'acl_audit_target_ix',
                    ['target_type', 'target_id', 'created_at'],
                ),
                new IndexDefinition(
                    'acl_audit_event_ix',
                    ['event', 'created_at'],
                ),
                new IndexDefinition('acl_audit_request_ix', ['request_id']),
                new IndexDefinition('acl_audit_created_ix', ['created_at']),
            ],
        );
    }

    /**
     * @return list<ColumnDefinition>
     */
    private static function definitionColumns(): array
    {
        return [
            self::bigInteger('id', generated: true),
            self::string('name', exact: true),
            self::string('guard_name', exact: true),
            self::text('description', nullable: true),
            self::dateTime('created_at'),
            self::dateTime('updated_at'),
            self::dateTime('deleted_at', nullable: true),
        ];
    }

    /**
     * @param  list<ColumnDefinition>  $identity
     * @return list<ColumnDefinition>
     */
    private static function assignmentColumns(array $identity): array
    {
        return [
            self::bigInteger('id', generated: true),
            ...$identity,
            self::dateTime('assigned_at'),
            self::string('assigned_by_type', exact: true),
            self::string('assigned_by_id', exact: true),
            self::text('assignment_reason', nullable: true),
            self::dateTime('starts_at', nullable: true),
            self::dateTime('expires_at', nullable: true),
            self::dateTime('revoked_at', nullable: true),
            self::string('revoked_by_type', nullable: true, exact: true),
            self::string('revoked_by_id', nullable: true, exact: true),
            self::text('revocation_reason', nullable: true),
            self::dateTime('created_at'),
            self::dateTime('updated_at'),
        ];
    }

    private static function bigInteger(
        string $name,
        bool $nullable = false,
        bool $generated = false,
    ): ColumnDefinition {
        return new ColumnDefinition(
            name: $name,
            type: ColumnType::BigInteger,
            nullable: $nullable,
            generated: $generated,
        );
    }

    private static function string(
        string $name,
        int $length = 191,
        bool $nullable = false,
        bool $exact = false,
    ): ColumnDefinition {
        return new ColumnDefinition(
            name: $name,
            type: ColumnType::String,
            nullable: $nullable,
            length: $length,
            exactComparison: $exact,
        );
    }

    private static function text(
        string $name,
        bool $nullable = false,
    ): ColumnDefinition {
        return new ColumnDefinition(
            name: $name,
            type: ColumnType::Text,
            nullable: $nullable,
        );
    }

    private static function dateTime(
        string $name,
        bool $nullable = false,
    ): ColumnDefinition {
        return new ColumnDefinition(
            name: $name,
            type: ColumnType::DateTime,
            nullable: $nullable,
        );
    }

    private static function primary(string $name): ConstraintDefinition
    {
        return new ConstraintDefinition(
            name: $name,
            type: ConstraintType::Primary,
            columns: ['id'],
        );
    }

    /**
     * @param  list<string>  $columns
     */
    private static function unique(
        string $name,
        array $columns,
    ): ConstraintDefinition {
        return new ConstraintDefinition(
            name: $name,
            type: ConstraintType::Unique,
            columns: $columns,
        );
    }

    private static function foreign(
        string $name,
        string $column,
        string $referencedTable,
    ): ConstraintDefinition {
        return new ConstraintDefinition(
            name: $name,
            type: ConstraintType::Foreign,
            columns: [$column],
            referencedTable: $referencedTable,
            referencedColumns: ['id'],
        );
    }

    private static function periodCheck(string $name): ConstraintDefinition
    {
        return new ConstraintDefinition(
            name: $name,
            type: ConstraintType::Check,
            columns: ['assigned_at', 'starts_at', 'expires_at'],
            expression: 'expires_at IS NULL '
                .'OR expires_at > COALESCE(starts_at, assigned_at)',
        );
    }

    private static function revokeCheck(string $name): ConstraintDefinition
    {
        return new ConstraintDefinition(
            name: $name,
            type: ConstraintType::Check,
            columns: [
                'revoked_at',
                'revoked_by_type',
                'revoked_by_id',
            ],
            expression: '(revoked_at IS NULL '
                .'AND revoked_by_type IS NULL '
                .'AND revoked_by_id IS NULL) '
                .'OR (revoked_at IS NOT NULL '
                .'AND revoked_by_type IS NOT NULL '
                .'AND revoked_by_id IS NOT NULL)',
        );
    }
}

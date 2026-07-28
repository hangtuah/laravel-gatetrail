<?php

declare(strict_types=1);

use HangTuah\GateTrail\Database\Migrations\GateTrailMigration;
use Illuminate\Database\Schema\Blueprint;

return new class extends GateTrailMigration
{
    public function up(): void
    {
        $this->prepareSchema();

        if ($this->installOracleTable('roles')) {
            return;
        }

        $collation = $this->adapter()->caseSensitiveCollation();

        $this->schema()->create(
            $this->context()->table('roles'),
            static function (Blueprint $table) use ($collation): void {
                $table->id();

                $name = $table->string('name', 191);
                $guard = $table->string('guard_name', 191);

                if ($collation !== null) {
                    $name->collation($collation);
                    $guard->collation($collation);
                }

                $table->longText('description')->nullable();
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
                $table->dateTime('deleted_at')->nullable();
                $table->unique(
                    ['name', 'guard_name'],
                    'acl_role_name_guard_uq',
                );
            },
        );
    }

    public function down(): void
    {
        $this->requireChildTablesAbsent('roles', [
            'role_assignments',
            'role_permissions',
        ]);

        if ($this->rollbackOracleTable('roles')) {
            return;
        }

        $this->schema()->drop($this->context()->table('roles'));
    }
};

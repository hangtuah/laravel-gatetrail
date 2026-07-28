<?php

declare(strict_types=1);

use HangTuah\GateTrail\Database\Migrations\GateTrailMigration;
use Illuminate\Database\Schema\Blueprint;

return new class extends GateTrailMigration
{
    public function up(): void
    {
        $this->prepareSchema();

        if ($this->installOracleTable('assignment_locks')) {
            return;
        }

        $collation = $this->adapter()->caseSensitiveCollation();
        $primary = $this->adapter()->physicalPrimaryKeyName('acl_asgn_locks_pk');

        $this->schema()->create(
            $this->context()->table('assignment_locks'),
            static function (Blueprint $table) use ($collation, $primary): void {
                $kind = $table->string('assignment_kind', 32);
                $hash = $table->char('scope_hash', 64);

                if ($collation !== null) {
                    $kind->collation($collation);
                    $hash->collation($collation);
                }

                $table->longText('scope_key');
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
                $table->primary(
                    ['assignment_kind', 'scope_hash'],
                    $primary,
                );
            },
        );
    }

    public function down(): void
    {
        if ($this->rollbackOracleTable('assignment_locks')) {
            return;
        }

        $this->schema()->drop(
            $this->context()->table('assignment_locks'),
        );
    }
};

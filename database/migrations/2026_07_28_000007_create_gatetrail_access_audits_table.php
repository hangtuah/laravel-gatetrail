<?php

declare(strict_types=1);

use HangTuah\GateTrail\Database\Migrations\GateTrailMigration;
use Illuminate\Database\Schema\Blueprint;

return new class extends GateTrailMigration
{
    public function up(): void
    {
        $this->prepareSchema();

        if ($this->adapter()->requiresInlineCheckConstraints()) {
            $this->createSQLiteTable();

            return;
        }

        $collation = $this->adapter()->caseSensitiveCollation();

        $this->schema()->create(
            $this->context()->table('access_audits'),
            function (Blueprint $table) use ($collation): void {
                $table->id();
                $this->stringColumn($table, 'event', $collation);
                $this->stringColumn($table, 'actor_type', $collation);
                $this->stringColumn($table, 'actor_id', $collation);
                $this->stringColumn($table, 'subject_type', $collation, true);
                $table->unsignedBigInteger('subject_id')->nullable();
                $this->stringColumn($table, 'target_type', $collation);
                $table->unsignedBigInteger('target_id');
                $table->text('before')->nullable();
                $table->text('after')->nullable();
                $table->text('reason')->nullable();
                $table->text('context')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->string('request_id', 191)->nullable();
                $table->dateTime('created_at');

                $this->addIndexes($table);
            },
        );

        $this->addCheckConstraints('access_audits', [
            'acl_audit_subject_ck' => $this->subjectExpression(),
        ]);
    }

    public function down(): void
    {
        $this->schema()->drop(
            $this->context()->table('access_audits'),
        );
    }

    private function createSQLiteTable(): void
    {
        $grammar = $this->connection()->getQueryGrammar();

        $definitions = [
            $grammar->wrap('id').' INTEGER PRIMARY KEY AUTOINCREMENT',
            $grammar->wrap('event').' VARCHAR(191) COLLATE BINARY NOT NULL',
            $grammar->wrap('actor_type').' VARCHAR(191) COLLATE BINARY NOT NULL',
            $grammar->wrap('actor_id').' VARCHAR(191) COLLATE BINARY NOT NULL',
            $grammar->wrap('subject_type').' VARCHAR(191) COLLATE BINARY NULL',
            $grammar->wrap('subject_id').' INTEGER NULL',
            $grammar->wrap('target_type').' VARCHAR(191) COLLATE BINARY NOT NULL',
            $grammar->wrap('target_id').' INTEGER NOT NULL',
            $grammar->wrap('before').' TEXT NULL',
            $grammar->wrap('after').' TEXT NULL',
            $grammar->wrap('reason').' TEXT NULL',
            $grammar->wrap('context').' TEXT NULL',
            $grammar->wrap('ip_address').' VARCHAR(45) NULL',
            $grammar->wrap('user_agent').' TEXT NULL',
            $grammar->wrap('request_id').' VARCHAR(191) NULL',
            $grammar->wrap('created_at').' DATETIME NOT NULL',
            sprintf(
                'CONSTRAINT %s CHECK (%s)',
                $grammar->wrap('acl_audit_subject_ck'),
                $this->subjectExpression(),
            ),
        ];

        $this->connection()->statement(
            sprintf(
                'CREATE TABLE %s (%s)',
                $grammar->wrapTable($this->context()->table('access_audits')),
                implode(', ', $definitions),
            ),
        );

        $this->schema()->table(
            $this->context()->table('access_audits'),
            function (Blueprint $table): void {
                $this->addIndexes($table);
            },
        );
    }

    private function stringColumn(
        Blueprint $table,
        string $name,
        ?string $collation,
        bool $nullable = false,
    ): void {
        $column = $table->string($name, 191);

        if ($collation !== null) {
            $column->collation($collation);
        }

        if ($nullable) {
            $column->nullable();
        }
    }

    private function addIndexes(Blueprint $table): void
    {
        $table->index(
            ['actor_type', 'actor_id', 'created_at'],
            'acl_audit_actor_ix',
        );
        $table->index(
            ['subject_type', 'subject_id', 'created_at'],
            'acl_audit_subject_ix',
        );
        $table->index(
            ['target_type', 'target_id', 'created_at'],
            'acl_audit_target_ix',
        );
        $table->index(
            ['event', 'created_at'],
            'acl_audit_event_ix',
        );
        $table->index('request_id', 'acl_audit_request_ix');
        $table->index('created_at', 'acl_audit_created_ix');
    }

    private function subjectExpression(): string
    {
        return '(subject_type IS NULL AND subject_id IS NULL) '
            .'OR (subject_type IS NOT NULL AND subject_id IS NOT NULL)';
    }
};

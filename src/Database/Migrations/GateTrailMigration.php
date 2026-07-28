<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Migrations;

use HangTuah\GateTrail\Database\Schema\SchemaAdapter;
use HangTuah\GateTrail\Database\Schema\SchemaAdapterFactory;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Exceptions\MigrationDependencyError;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Builder;

abstract class GateTrailMigration extends Migration
{
    public function getConnection(): ?string
    {
        return $this->context()->connectionName();
    }

    public function shouldRun(): bool
    {
        return $this->context()->provisioning() === 'package';
    }

    protected function prepareSchema(): void
    {
        $this->context()->validateAll();
    }

    /**
     * @param  list<string>  $parentKeys
     */
    protected function requireParentTables(string $childKey, array $parentKeys): void
    {
        $child = $this->context()->table($childKey);

        foreach ($parentKeys as $parentKey) {
            $parent = $this->context()->table($parentKey);

            if (! $this->schema()->hasTable($parent)) {
                throw MigrationDependencyError::missing($child, $parent);
            }
        }
    }

    /**
     * @param  list<string>  $childKeys
     */
    protected function requireChildTablesAbsent(string $parentKey, array $childKeys): void
    {
        $parent = $this->context()->table($parentKey);

        foreach ($childKeys as $childKey) {
            $child = $this->context()->table($childKey);

            if ($this->schema()->hasTable($child)) {
                throw MigrationDependencyError::stillReferenced($parent, $child);
            }
        }
    }

    /**
     * @param  array<string, string>  $constraints
     */
    protected function addCheckConstraints(string $tableKey, array $constraints): void
    {
        if ($this->adapter()->requiresInlineCheckConstraints()) {
            return;
        }

        $grammar = $this->connection()->getQueryGrammar();
        $table = $grammar->wrapTable($this->context()->table($tableKey));

        foreach ($constraints as $name => $expression) {
            $constraint = $grammar->wrap($name);

            $this->connection()->statement(
                sprintf(
                    'ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s)',
                    $table,
                    $constraint,
                    $expression,
                ),
            );
        }
    }

    protected function context(): SchemaContext
    {
        return app(SchemaContext::class);
    }

    protected function schema(): Builder
    {
        return $this->connection()->getSchemaBuilder();
    }

    protected function connection(): Connection
    {
        return app(DatabaseManager::class)->connection(
            $this->context()->connectionName(),
        );
    }

    protected function adapter(): SchemaAdapter
    {
        return app(SchemaAdapterFactory::class)->forDriver(
            $this->connection()->getDriverName(),
        );
    }
}

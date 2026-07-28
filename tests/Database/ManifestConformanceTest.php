<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Database;

use HangTuah\GateTrail\Database\Schema\Manifest\ConstraintType;
use HangTuah\GateTrail\Database\Schema\Manifest\SchemaManifest;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

final class ManifestConformanceTest extends TestCase
{
    public function test_sqlite_migrations_conform_to_manifest_columns_and_indexes(): void
    {
        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);

        $schema = Schema::connection('testing');
        $manifest = $this->app->make(SchemaManifest::class);
        $context = $this->app->make(SchemaContext::class);

        foreach ($manifest->tables() as $table) {
            $physicalTable = $context->table($table->key);
            $actualColumns = collect($schema->getColumns($physicalTable))
                ->keyBy('name');

            $this->assertSame(
                $table->columnNames(),
                $actualColumns->keys()->all(),
                sprintf(
                    'Columns for [%s] drifted from the manifest.',
                    $physicalTable,
                ),
            );

            foreach ($table->columns as $column) {
                // SQLite reports an INTEGER PRIMARY KEY rowid alias as
                // nullable in PRAGMA metadata even though stored row IDs are
                // always non-null.
                if ($column->generated) {
                    continue;
                }

                $this->assertSame(
                    $column->nullable,
                    (bool) $actualColumns->get($column->name)['nullable'],
                    sprintf(
                        'Nullability for [%s.%s] drifted from the manifest.',
                        $physicalTable,
                        $column->name,
                    ),
                );
            }

            $actualIndexes = collect($schema->getIndexes($physicalTable))
                ->pluck('name')
                ->all();

            foreach ($table->indexes as $index) {
                $this->assertContains($index->name, $actualIndexes);
            }

            foreach ($table->constraints as $constraint) {
                if ($constraint->type === ConstraintType::Unique) {
                    $this->assertContains(
                        $constraint->name,
                        $actualIndexes,
                    );
                }
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Database;

use HangTuah\GateTrail\Database\Schema\Manifest\SchemaManifest;
use PHPUnit\Framework\TestCase;

final class MigrationOrderTest extends TestCase
{
    public function test_migration_filenames_encode_parent_before_child_order(): void
    {
        $files = glob(__DIR__.'/../../database/migrations/*.php');

        $this->assertIsArray($files);
        $manifestMigrations = array_map(
            static fn ($table): string => $table->migration,
            SchemaManifest::gateTrail()->tables(),
        );

        $this->assertSame(
            $manifestMigrations,
            array_map(basename(...), $files),
        );
    }
}

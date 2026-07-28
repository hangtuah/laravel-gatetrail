<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests;

use Illuminate\Database\Migrations\Migrator;

final class PackageMigrationLoadingTest extends TestCase
{
    public function test_package_provisioning_registers_the_migration_path(): void
    {
        $migrator = $this->app->make(Migrator::class);

        $this->assertContains(
            realpath(__DIR__.'/../database/migrations'),
            array_map(realpath(...), $migrator->paths()),
        );
    }
}

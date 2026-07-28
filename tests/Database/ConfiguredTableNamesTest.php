<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Database;

use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

final class ConfiguredTableNamesTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('gatetrail.database.prefix', 'x_');
    }

    public function test_migrations_use_the_configured_table_prefix(): void
    {
        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);

        $this->assertTrue(
            Schema::connection('testing')->hasTable('x_acl_roles'),
        );
        $this->assertTrue(
            Schema::connection('testing')->hasTable(
                'x_acl_role_assignments',
            ),
        );
        $this->assertFalse(
            Schema::connection('testing')->hasTable('acl_roles'),
        );
    }
}

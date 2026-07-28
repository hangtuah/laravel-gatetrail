<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests;

use Illuminate\Database\Migrations\Migrator;

final class DbaProvisioningBootTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('gatetrail.database.provisioning', 'dba');
    }

    public function test_dba_provisioning_does_not_register_package_migrations(): void
    {
        $migrator = $this->app->make(Migrator::class);

        $this->assertNotContains(
            realpath(__DIR__.'/../database/migrations'),
            array_map(realpath(...), $migrator->paths()),
        );
    }
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests;

use HangTuah\GateTrail\GateTrailServiceProvider;

final class PackageBootTest extends TestCase
{
    public function test_package_boots_and_loads_its_configuration(): void
    {
        $provider = $this->app->getProvider(
            GateTrailServiceProvider::class,
        );

        $this->assertInstanceOf(GateTrailServiceProvider::class, $provider);
        $this->assertSame(
            'acl_roles',
            config('gatetrail.tables.roles'),
        );
        $this->assertSame(
            'package',
            config('gatetrail.database.provisioning'),
        );
        $this->assertCount(
            7,
            config('gatetrail.tables'),
        );
    }
}

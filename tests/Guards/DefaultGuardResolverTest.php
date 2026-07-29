<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Guards;

use HangTuah\GateTrail\Exceptions\InvalidGuard;
use HangTuah\GateTrail\Guards\DefaultGuardResolver;
use HangTuah\GateTrail\Tests\TestCase;

final class DefaultGuardResolverTest extends TestCase
{
    public function test_package_default_precedes_laravel_default(): void
    {
        config()->set('gatetrail.guards.default', ' api ');
        config()->set('auth.defaults.guard', 'web');

        $this->assertSame(
            'api',
            app(DefaultGuardResolver::class)->resolve(),
        );
    }

    public function test_laravel_default_is_the_final_fallback(): void
    {
        config()->set('gatetrail.guards.default');
        config()->set('auth.defaults.guard', 'web');

        $this->assertSame(
            'web',
            app(DefaultGuardResolver::class)->resolve(),
        );
    }

    public function test_empty_configured_default_is_rejected(): void
    {
        config()->set('gatetrail.guards.default', ' ');

        $this->expectException(InvalidGuard::class);

        app(DefaultGuardResolver::class)->resolve();
    }
}

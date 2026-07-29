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
            app(DefaultGuardResolver::class)->resolve('role definition'),
        );
    }

    public function test_laravel_default_is_the_final_fallback(): void
    {
        config()->set('gatetrail.guards.default');
        config()->set('auth.defaults.guard', 'web');

        $this->assertSame(
            'web',
            app(DefaultGuardResolver::class)
                ->resolve('role-permission assignment'),
        );
    }

    public function test_empty_configured_default_is_rejected(): void
    {
        config()->set('gatetrail.guards.default', ' ');

        $this->expectException(InvalidGuard::class);

        app(DefaultGuardResolver::class)->resolve('role definition');
    }

    public function test_failure_reports_the_caller_supplied_context(): void
    {
        config()->set('gatetrail.guards.default', ' ');
        $resolver = app(DefaultGuardResolver::class);

        try {
            $resolver->resolve('role definition');

            $this->fail('An invalid package default should be rejected.');
        } catch (InvalidGuard $exception) {
            $this->assertSame('role definition', $exception->contextType);
            $this->assertSame('package default', $exception->source);
        }

        try {
            $resolver->resolve('role-permission assignment');

            $this->fail('An invalid package default should be rejected.');
        } catch (InvalidGuard $exception) {
            $this->assertSame(
                'role-permission assignment',
                $exception->contextType,
            );
        }
    }

    public function test_failure_reports_the_laravel_default_source(): void
    {
        config()->set('gatetrail.guards.default');
        config()->set('auth.defaults.guard', '');

        try {
            app(DefaultGuardResolver::class)->resolve('role definition');

            $this->fail('An invalid Laravel default should be rejected.');
        } catch (InvalidGuard $exception) {
            $this->assertSame('role definition', $exception->contextType);
            $this->assertSame('Laravel default', $exception->source);
        }
    }
}

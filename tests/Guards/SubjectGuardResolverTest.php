<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Guards;

use HangTuah\GateTrail\Concerns\HasGateTrail;
use HangTuah\GateTrail\Exceptions\InvalidGuard;
use HangTuah\GateTrail\Guards\SubjectGuardResolver;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

final class SubjectGuardResolverTest extends TestCase
{
    public function test_public_method_wins_over_every_other_source(): void
    {
        config()->set('gatetrail.guards.subjects', [
            MethodGuardSubject::class => 'configured',
        ]);
        config()->set('gatetrail.guards.default', 'package');

        $this->assertSame(
            'MethodGuard',
            app(SubjectGuardResolver::class)->resolve(
                new MethodGuardSubject,
            ),
        );
    }

    public function test_declared_property_is_trimmed_and_case_preserved(): void
    {
        $this->assertSame(
            'Admin',
            app(SubjectGuardResolver::class)->resolve(
                new PropertyGuardSubject,
            ),
        );
    }

    public function test_exact_subject_configuration_precedes_defaults(): void
    {
        config()->set('gatetrail.guards.subjects', [
            ConfiguredGuardSubject::class => 'configured',
        ]);
        config()->set('gatetrail.guards.default', 'package');

        $this->assertSame(
            'configured',
            app(SubjectGuardResolver::class)->resolve(
                new ConfiguredGuardSubject,
            ),
        );
    }

    public function test_package_default_precedes_laravel_default(): void
    {
        config()->set('gatetrail.guards.default', 'package');
        config()->set('auth.defaults.guard', 'laravel');

        $this->assertSame(
            'package',
            app(SubjectGuardResolver::class)->resolve(new PlainSubject),
        );
    }

    public function test_laravel_default_is_the_final_fallback(): void
    {
        config()->set('gatetrail.guards.default', null);
        config()->set('auth.defaults.guard', 'web');

        $this->assertSame(
            'web',
            app(SubjectGuardResolver::class)->resolve(new PlainSubject),
        );
    }

    public function test_invalid_authoritative_method_does_not_fall_back(): void
    {
        config()->set('gatetrail.guards.default', 'package');

        $this->expectException(InvalidGuard::class);
        $this->expectExceptionMessage('subject method');

        app(SubjectGuardResolver::class)->resolve(
            new InvalidMethodGuardSubject,
        );
    }

    public function test_invalid_authoritative_configuration_does_not_fall_back(): void
    {
        config()->set('gatetrail.guards.subjects', [
            ConfiguredGuardSubject::class => '   ',
        ]);
        config()->set('gatetrail.guards.default', 'package');

        $this->expectException(InvalidGuard::class);
        $this->expectExceptionMessage('subject configuration');

        app(SubjectGuardResolver::class)->resolve(
            new ConfiguredGuardSubject,
        );
    }
}

final class MethodGuardSubject extends Model
{
    use HasGateTrail;

    protected string $gateTrailGuardName = 'property';

    public function gateTrailGuardName(): string
    {
        return '  MethodGuard  ';
    }
}

final class PropertyGuardSubject extends Model
{
    use HasGateTrail;

    protected string $gateTrailGuardName = '  Admin  ';
}

final class ConfiguredGuardSubject extends Model {}

final class PlainSubject extends Model {}

final class InvalidMethodGuardSubject extends Model
{
    public function gateTrailGuardName(): array
    {
        return [];
    }
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Managers;

use DateTimeImmutable;
use HangTuah\GateTrail\Contracts\RoleManager;
use HangTuah\GateTrail\Managers\DefaultRoleManager;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use HangTuah\GateTrail\Tests\TestCase;
use Psr\Clock\ClockInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

final class DefaultRoleManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);
        $this->app->instance(
            ClockInterface::class,
            new FrozenClock(
                new DateTimeImmutable('2026-07-29 12:00:00 UTC'),
            ),
        );
    }

    public function test_contract_resolves_to_the_default_manager(): void
    {
        $manager = app(RoleManager::class);

        $this->assertInstanceOf(DefaultRoleManager::class, $manager);
        $this->assertSame($manager, app(RoleManager::class));
    }

    public function test_public_api_creates_a_role_through_the_full_path(): void
    {
        $result = app(RoleManager::class)->create(
            name: 'reviewer',
            guard: 'web',
            actor: SystemActor::named('definition-suite'),
            reason: 'Initial provisioning',
            description: 'Reviews documents.',
            metadata: ['ticket' => 'SEC-42'],
        );
        $audit = $this->app['db']
            ->table('acl_access_audits')
            ->where('id', $result->auditId)
            ->first();

        $this->assertSame(MutationOutcome::Created, $result->outcome);
        $this->assertSame(
            'reviewer',
            $result->resource->getAttribute('name'),
        );
        $this->assertSame(
            'Reviews documents.',
            $result->resource->getAttribute('description'),
        );
        $this->assertNotNull($audit);
        $this->assertSame('role.created', $audit->event);
        $this->assertSame('role', $audit->target_type);
        $this->assertSame(
            ['ticket' => 'SEC-42'],
            json_decode(
                $audit->context,
                true,
                flags: JSON_THROW_ON_ERROR,
            )['metadata'],
        );
    }

    public function test_public_api_creates_without_optional_arguments(): void
    {
        $result = app(RoleManager::class)->create(
            'reviewer',
            'web',
            SystemActor::named('definition-suite'),
        );

        $this->assertSame(MutationOutcome::Created, $result->outcome);
        $this->assertNull($result->resource->getAttribute('description'));
        $this->assertNotNull($result->auditId);
    }

    public function test_current_public_contract_exposes_only_create(): void
    {
        $methods = array_values(array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            array_filter(
                (new ReflectionClass(RoleManager::class))
                    ->getMethods(ReflectionMethod::IS_PUBLIC),
                static fn (ReflectionMethod $method): bool => $method
                    ->getDeclaringClass()
                    ->getName() === RoleManager::class,
            ),
        ));

        $this->assertSame(['create'], $methods);
        $this->assertTrue(
            (new ReflectionMethod(DefaultRoleManager::class, 'create'))
                ->getDeclaringClass()
                ->getName() === DefaultRoleManager::class,
        );
    }

    public function test_create_signature_has_no_assignment_concepts(): void
    {
        $parameters = array_map(
            static fn (ReflectionParameter $parameter): string => $parameter
                ->getName(),
            (new ReflectionMethod(RoleManager::class, 'create'))
                ->getParameters(),
        );

        $this->assertSame(
            [
                'name',
                'guard',
                'actor',
                'reason',
                'description',
                'metadata',
            ],
            $parameters,
        );
    }
}

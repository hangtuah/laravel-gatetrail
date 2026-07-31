<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Managers;

use DateTimeImmutable;
use HangTuah\GateTrail\Contracts\PermissionManager;
use HangTuah\GateTrail\Exceptions\DuplicatePermission;
use HangTuah\GateTrail\Managers\DefaultPermissionManager;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\MutationResult;
use HangTuah\GateTrail\Mutations\Handlers\CreatePermissionHandler;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use HangTuah\GateTrail\Tests\TestCase;
use Psr\Clock\ClockInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

final class DefaultPermissionManagerTest extends TestCase
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
        $manager = app(PermissionManager::class);

        $this->assertInstanceOf(DefaultPermissionManager::class, $manager);
        $this->assertSame($manager, app(PermissionManager::class));
    }

    public function test_public_api_creates_a_permission_through_the_full_path(): void
    {
        $result = app(PermissionManager::class)->create(
            name: 'documents.approve',
            guard: 'web',
            actor: SystemActor::named('definition-suite'),
            reason: 'Introduce approval capability',
            description: 'Approve submitted documents',
            metadata: ['ticket' => 'SEC-42'],
        );
        $audit = $this->app['db']
            ->table('acl_access_audits')
            ->where('id', $result->auditId)
            ->first();

        $this->assertSame(MutationOutcome::Created, $result->outcome);
        $this->assertSame(
            'documents.approve',
            $result->resource->getAttribute('name'),
        );
        $this->assertSame(
            'Approve submitted documents',
            $result->resource->getAttribute('description'),
        );
        $this->assertSame(
            '2026-07-29 12:00:00',
            $result->resource->getAttribute('created_at')
                ?->format('Y-m-d H:i:s'),
        );
        $this->assertNotNull($audit);
        $this->assertSame('permission.created', $audit->event);
        $this->assertSame('permission', $audit->target_type);
        $this->assertSame(
            '{"schema_version":1,"guard_name":"web",'
            .'"permission_name":"documents.approve",'
            .'"metadata":{"ticket":"SEC-42"}}',
            $audit->context,
        );
    }

    public function test_public_api_creates_without_optional_arguments(): void
    {
        $result = app(PermissionManager::class)->create(
            'documents.approve',
            'web',
            SystemActor::named('definition-suite'),
        );

        $this->assertSame(MutationOutcome::Created, $result->outcome);
        $this->assertNull($result->resource->getAttribute('description'));
        $this->assertNotNull($result->auditId);
    }

    public function test_public_api_rejects_a_duplicate_namespace(): void
    {
        $manager = app(PermissionManager::class);
        $manager->create(
            'documents.approve',
            'web',
            SystemActor::named('definition-suite'),
        );

        try {
            $manager->create(
                'documents.approve',
                'web',
                SystemActor::named('definition-suite'),
            );

            $this->fail('A duplicate namespace should be rejected.');
        } catch (DuplicatePermission) {
            $this->assertSame(1, Permission::query()->withTrashed()->count());
            $this->assertDatabaseCount('acl_access_audits', 1);
        }
    }

    public function test_manager_signature_matches_the_accepted_decision(): void
    {
        $this->assertSame(
            [
                'name',
                'guard',
                'actor',
                'reason',
                'description',
                'metadata',
            ],
            array_map(
                static fn (ReflectionParameter $parameter): string => $parameter
                    ->getName(),
                (new ReflectionMethod(PermissionManager::class, 'create'))
                    ->getParameters(),
            ),
        );
    }

    public function test_public_contract_exposes_only_permission_creation(): void
    {
        $methods = array_values(array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            array_filter(
                (new ReflectionClass(PermissionManager::class))
                    ->getMethods(ReflectionMethod::IS_PUBLIC),
                static fn (ReflectionMethod $method): bool => $method
                    ->getDeclaringClass()
                    ->getName() === PermissionManager::class,
            ),
        ));

        $this->assertSame(['create'], $methods);

        foreach (['update', 'deactivate', 'restore'] as $unimplemented) {
            $this->assertFalse(
                method_exists(PermissionManager::class, $unimplemented),
                "[$unimplemented] must not exist before it is implemented.",
            );
            $this->assertFalse(
                method_exists(
                    DefaultPermissionManager::class,
                    $unimplemented,
                ),
                "[$unimplemented] must not exist as a stub.",
            );
        }
    }

    public function test_adapter_is_query_free_and_validation_free_by_structure(): void
    {
        $reflection = new ReflectionClass(DefaultPermissionManager::class);

        // The adapter owns exactly one handler and the package clock, so it
        // has no collaborator that could validate, query, or audit.
        $this->assertSame(
            [CreatePermissionHandler::class, ClockInterface::class],
            array_values(array_filter(array_map(
                static fn (ReflectionParameter $parameter): ?string => $parameter
                    ->getType() instanceof ReflectionNamedType
                        ? $parameter->getType()->getName()
                        : null,
                $reflection->getConstructor()?->getParameters() ?? [],
            ))),
        );
        $this->assertSame(
            ['__construct', 'create'],
            array_map(
                static fn (ReflectionMethod $method): string => $method
                    ->getName(),
                $reflection->getMethods(),
            ),
        );

        $create = new ReflectionMethod(
            DefaultPermissionManager::class,
            'create',
        );

        $this->assertFalse($create->isAbstract());
        $this->assertSame(
            MutationResult::class,
            $create->getReturnType() instanceof ReflectionNamedType
                ? $create->getReturnType()->getName()
                : null,
        );
    }
}

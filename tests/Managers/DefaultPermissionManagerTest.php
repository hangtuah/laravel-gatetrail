<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Managers;

use DateTimeImmutable;
use HangTuah\GateTrail\Contracts\PermissionManager;
use HangTuah\GateTrail\Exceptions\DuplicatePermission;
use HangTuah\GateTrail\Exceptions\PermissionNotFound;
use HangTuah\GateTrail\Managers\DefaultPermissionManager;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\MutationResult;
use HangTuah\GateTrail\Mutations\Handlers\CreatePermissionHandler;
use HangTuah\GateTrail\Mutations\Handlers\DeactivatePermissionHandler;
use HangTuah\GateTrail\Mutations\Handlers\RestorePermissionHandler;
use HangTuah\GateTrail\Mutations\Handlers\UpdatePermissionHandler;
use HangTuah\GateTrail\PermissionChanges;
use HangTuah\GateTrail\PermissionReference;
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

    public function test_public_api_updates_a_permission_through_the_full_path(): void
    {
        $manager = app(PermissionManager::class);
        $created = $manager->create(
            'documents.approve',
            'web',
            SystemActor::named('definition-suite'),
            description: 'Approve submitted documents',
        );

        $result = $manager->update(
            permission: $created->resource,
            changes: PermissionChanges::description(
                'Approve or return submitted documents',
            ),
            actor: SystemActor::named('definition-suite'),
            reason: 'Clarify permission purpose',
            metadata: ['ticket' => 'SEC-42'],
        );
        $audit = $this->app['db']
            ->table('acl_access_audits')
            ->where('id', $result->auditId)
            ->first();

        $this->assertSame(MutationOutcome::Updated, $result->outcome);
        $this->assertTrue($result->changed());
        $this->assertSame(
            'Approve or return submitted documents',
            $result->resource->getAttribute('description'),
        );
        $this->assertNotNull($audit);
        $this->assertSame('permission.updated', $audit->event);
        $this->assertSame('permission', $audit->target_type);
        $this->assertSame(
            '{"schema_version":1,"guard_name":"web",'
            .'"permission_name":"documents.approve",'
            .'"metadata":{"ticket":"SEC-42"}}',
            $audit->context,
        );
    }

    public function test_public_api_clears_a_description_explicitly(): void
    {
        $manager = app(PermissionManager::class);
        $manager->create(
            'documents.approve',
            'web',
            SystemActor::named('definition-suite'),
            description: 'Obsolete description',
        );

        $result = $manager->update(
            permission: 'documents.approve',
            changes: PermissionChanges::description(null),
            actor: SystemActor::named('definition-suite'),
            reason: 'Remove obsolete description',
        );

        $this->assertSame(MutationOutcome::Updated, $result->outcome);
        $this->assertNull($result->resource->getAttribute('description'));
        $this->assertNotNull($result->auditId);
    }

    public function test_public_api_returns_unchanged_without_audit(): void
    {
        $manager = app(PermissionManager::class);
        $manager->create(
            'documents.approve',
            'web',
            SystemActor::named('definition-suite'),
            description: 'Approve submitted documents',
        );

        $result = $manager->update(
            permission: 'documents.approve',
            changes: PermissionChanges::description(
                'Approve submitted documents',
            ),
            actor: SystemActor::named('definition-suite'),
        );

        $this->assertSame(MutationOutcome::Unchanged, $result->outcome);
        $this->assertNull($result->auditId);
        $this->assertFalse($result->changed());
        $this->assertDatabaseCount('acl_access_audits', 1);
    }

    public function test_public_update_accepts_every_accepted_input(): void
    {
        $manager = app(PermissionManager::class);
        $actor = SystemActor::named('definition-suite');
        $permission = $manager->create(
            'documents.approve',
            'web',
            $actor,
        )->resource;
        $inputs = [
            'model' => $permission,
            'integer' => (int) $permission->getKey(),
            'reference' => PermissionReference::id($permission->getKey()),
            'name' => 'documents.approve',
        ];

        foreach ($inputs as $label => $input) {
            $result = $manager->update(
                permission: $input,
                changes: PermissionChanges::description(
                    "Updated through $label",
                ),
                actor: $actor,
            );

            $this->assertSame(
                MutationOutcome::Updated,
                $result->outcome,
                "Input [$label] should update the permission.",
            );
            $this->assertNotNull($result->auditId);
        }

        $this->assertSame(
            'Updated through name',
            Permission::query()->sole()->getAttribute('description'),
        );
    }

    public function test_public_api_deactivates_a_permission_through_the_full_path(): void
    {
        $manager = app(PermissionManager::class);
        $created = $manager->create(
            'documents.approve',
            'web',
            SystemActor::named('definition-suite'),
            description: 'Approve submitted documents',
        );

        $result = $manager->deactivate(
            permission: $created->resource,
            actor: SystemActor::named('definition-suite'),
            reason: 'Superseded by a narrower permission',
            metadata: ['ticket' => 'SEC-42'],
        );
        $audit = $this->app['db']
            ->table('acl_access_audits')
            ->where('id', $result->auditId)
            ->first();

        $this->assertSame(MutationOutcome::Deactivated, $result->outcome);
        $this->assertTrue($result->changed());
        $this->assertSame(
            '2026-07-29 12:00:00',
            $result->resource->getAttribute('deleted_at')
                ?->format('Y-m-d H:i:s'),
        );
        $this->assertNotNull($audit);
        $this->assertSame('permission.deactivated', $audit->event);
        $this->assertSame('permission', $audit->target_type);
        $this->assertSame(
            '{"schema_version":1,"guard_name":"web",'
            .'"permission_name":"documents.approve",'
            .'"metadata":{"ticket":"SEC-42"}}',
            $audit->context,
        );
    }

    public function test_public_deactivation_accepts_every_accepted_input(): void
    {
        $manager = app(PermissionManager::class);
        $actor = SystemActor::named('definition-suite');
        $inputs = [
            'model' => static fn (Permission $permission): Permission => $permission,
            'integer' => static fn (Permission $permission): int => (int) $permission
                ->getKey(),
            'reference' => static fn (Permission $permission): PermissionReference => PermissionReference::id(
                $permission->getKey(),
            ),
            'name' => static fn (Permission $permission): string => 'documents.approve',
        ];

        foreach ($inputs as $label => $input) {
            Permission::query()->withTrashed()->forceDelete();
            $permission = $manager->create(
                'documents.approve',
                'web',
                $actor,
            )->resource;

            $result = $manager->deactivate(
                permission: $input($permission),
                actor: $actor,
            );

            $this->assertSame(
                MutationOutcome::Deactivated,
                $result->outcome,
                "Input [$label] should deactivate the permission.",
            );
            $this->assertNotNull($result->auditId);
            $this->assertNotNull(
                Permission::query()
                    ->withTrashed()
                    ->whereKey($permission->getKey())
                    ->first()
                    ?->getAttribute('deleted_at'),
            );
        }
    }

    public function test_deactivated_permission_cannot_be_updated_or_recreated(): void
    {
        $manager = app(PermissionManager::class);
        $created = $manager->create(
            'documents.approve',
            'web',
            SystemActor::named('definition-suite'),
        );
        $manager->deactivate(
            permission: $created->resource,
            actor: SystemActor::named('definition-suite'),
        );

        try {
            $manager->update(
                permission: PermissionReference::id(
                    $created->resource->getKey(),
                ),
                changes: PermissionChanges::description('Updated purpose'),
                actor: SystemActor::named('definition-suite'),
            );

            $this->fail('A deactivated permission should not be updatable.');
        } catch (PermissionNotFound $exception) {
            $this->assertSame('web', $exception->guard);
        }

        try {
            $manager->create(
                'documents.approve',
                'web',
                SystemActor::named('definition-suite'),
            );

            $this->fail('A deactivated namespace should remain occupied.');
        } catch (DuplicatePermission) {
            $this->assertDatabaseCount('acl_access_audits', 2);
        }
    }

    public function test_public_api_restores_a_permission_through_the_full_path(): void
    {
        $manager = app(PermissionManager::class);
        $created = $manager->create(
            'documents.approve',
            'web',
            SystemActor::named('definition-suite'),
            description: 'Approve submitted documents',
        );
        $manager->deactivate(
            permission: $created->resource,
            actor: SystemActor::named('definition-suite'),
        );

        $result = $manager->restore(
            permission: $created->resource,
            actor: SystemActor::named('definition-suite'),
            reason: 'Reinstated after review',
            metadata: ['ticket' => 'SEC-42'],
        );
        $audit = $this->app['db']
            ->table('acl_access_audits')
            ->where('id', $result->auditId)
            ->first();

        $this->assertSame(MutationOutcome::Restored, $result->outcome);
        $this->assertTrue($result->changed());
        $this->assertNull($result->resource->getAttribute('deleted_at'));
        $this->assertNotNull($audit);
        $this->assertSame('permission.restored', $audit->event);
        $this->assertSame('permission', $audit->target_type);
        $this->assertSame(
            '{"schema_version":1,"guard_name":"web",'
            .'"permission_name":"documents.approve",'
            .'"metadata":{"ticket":"SEC-42"}}',
            $audit->context,
        );
    }

    public function test_public_restoration_accepts_every_accepted_input(): void
    {
        $manager = app(PermissionManager::class);
        $actor = SystemActor::named('definition-suite');
        $inputs = [
            'model' => static fn (Permission $permission): Permission => $permission,
            'integer' => static fn (Permission $permission): int => (int) $permission
                ->getKey(),
            'reference' => static fn (Permission $permission): PermissionReference => PermissionReference::id(
                $permission->getKey(),
            ),
            'name' => static fn (Permission $permission): string => 'documents.approve',
        ];

        foreach ($inputs as $label => $input) {
            Permission::query()->withTrashed()->forceDelete();
            $permission = $manager->create(
                'documents.approve',
                'web',
                $actor,
            )->resource;
            $manager->deactivate(permission: $permission, actor: $actor);

            $result = $manager->restore(
                permission: $input($permission),
                actor: $actor,
            );

            $this->assertSame(
                MutationOutcome::Restored,
                $result->outcome,
                "Input [$label] should restore the permission.",
            );
            $this->assertNotNull($result->auditId);
            $this->assertNull(
                Permission::query()
                    ->withTrashed()
                    ->whereKey($permission->getKey())
                    ->first()
                    ?->getAttribute('deleted_at'),
            );
        }
    }

    public function test_full_permission_lifecycle_round_trip(): void
    {
        $manager = app(PermissionManager::class);
        $actor = SystemActor::named('definition-suite');

        $created = $manager->create(
            'documents.approve',
            'web',
            $actor,
            description: 'Approve submitted documents',
        );
        $permission = $created->resource;

        $this->assertSame(MutationOutcome::Created, $created->outcome);
        $this->assertSame(
            MutationOutcome::Updated,
            $manager->update(
                permission: $permission,
                changes: PermissionChanges::description('Approve documents'),
                actor: $actor,
            )->outcome,
        );
        $this->assertSame(
            MutationOutcome::Deactivated,
            $manager->deactivate(permission: $permission, actor: $actor)
                ->outcome,
        );
        $this->assertSame(
            MutationOutcome::Restored,
            $manager->restore(permission: $permission, actor: $actor)
                ->outcome,
        );

        // Both active-permission mutations work again once it is back.
        $this->assertSame(
            MutationOutcome::Updated,
            $manager->update(
                permission: $permission,
                changes: PermissionChanges::description('Approve everything'),
                actor: $actor,
            )->outcome,
        );
        $this->assertSame(
            MutationOutcome::Deactivated,
            $manager->deactivate(permission: $permission, actor: $actor)
                ->outcome,
        );
        $this->assertSame(
            MutationOutcome::Restored,
            $manager->restore(permission: $permission, actor: $actor)
                ->outcome,
        );

        $persisted = Permission::query()->sole();

        $this->assertSame(
            'Approve everything',
            $persisted->getAttribute('description'),
        );
        $this->assertSame(
            'documents.approve',
            $persisted->getAttribute('name'),
        );
        $this->assertSame('web', $persisted->getAttribute('guard_name'));
        $this->assertDatabaseCount('acl_access_audits', 7);

        try {
            $manager->create('documents.approve', 'web', $actor);

            $this->fail('A restored namespace should remain occupied.');
        } catch (DuplicatePermission) {
            $this->assertSame(
                1,
                Permission::query()->withTrashed()->count(),
            );
        }
    }

    public function test_manager_signatures_match_the_accepted_decision(): void
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
            $this->parameters('create'),
        );
        $this->assertSame(
            ['permission', 'changes', 'actor', 'reason', 'metadata'],
            $this->parameters('update'),
        );
        $this->assertSame(
            ['permission', 'actor', 'reason', 'metadata'],
            $this->parameters('deactivate'),
        );
        $this->assertSame(
            ['permission', 'actor', 'reason', 'metadata'],
            $this->parameters('restore'),
        );
    }

    public function test_public_contract_exposes_the_four_permission_mutations(): void
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

        $this->assertSame(
            ['create', 'update', 'deactivate', 'restore'],
            $methods,
        );

        foreach ($methods as $method) {
            $this->assertSame(
                DefaultPermissionManager::class,
                (new ReflectionMethod(DefaultPermissionManager::class, $method))
                    ->getDeclaringClass()
                    ->getName(),
            );
        }
    }

    public function test_adapter_is_query_free_and_validation_free_by_structure(): void
    {
        $reflection = new ReflectionClass(DefaultPermissionManager::class);

        // The adapter owns exactly one handler per mutation plus the package
        // clock, so it has no collaborator that could validate, query, or
        // audit, and neither method can be a placeholder body.
        $this->assertSame(
            [
                CreatePermissionHandler::class,
                UpdatePermissionHandler::class,
                DeactivatePermissionHandler::class,
                RestorePermissionHandler::class,
                ClockInterface::class,
            ],
            array_values(array_filter(array_map(
                static fn (ReflectionParameter $parameter): ?string => $parameter
                    ->getType() instanceof ReflectionNamedType
                        ? $parameter->getType()->getName()
                        : null,
                $reflection->getConstructor()?->getParameters() ?? [],
            ))),
        );
        $this->assertSame(
            ['__construct', 'create', 'update', 'deactivate', 'restore'],
            array_map(
                static fn (ReflectionMethod $method): string => $method
                    ->getName(),
                $reflection->getMethods(),
            ),
        );

        foreach (['create', 'update', 'deactivate', 'restore'] as $method) {
            $mutation = new ReflectionMethod(
                DefaultPermissionManager::class,
                $method,
            );

            $this->assertFalse($mutation->isAbstract());
            $this->assertSame(
                MutationResult::class,
                $mutation->getReturnType() instanceof ReflectionNamedType
                    ? $mutation->getReturnType()->getName()
                    : null,
            );
        }
    }

    /**
     * @return list<string>
     */
    private function parameters(string $method): array
    {
        return array_map(
            static fn (ReflectionParameter $parameter): string => $parameter
                ->getName(),
            (new ReflectionMethod(PermissionManager::class, $method))
                ->getParameters(),
        );
    }
}

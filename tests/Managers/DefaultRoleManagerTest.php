<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Managers;

use DateTimeImmutable;
use HangTuah\GateTrail\Contracts\RoleManager;
use HangTuah\GateTrail\Exceptions\DuplicateRole;
use HangTuah\GateTrail\Exceptions\RoleNotFound;
use HangTuah\GateTrail\Managers\DefaultRoleManager;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\MutationResult;
use HangTuah\GateTrail\Mutations\Handlers\CreateRoleHandler;
use HangTuah\GateTrail\Mutations\Handlers\DeactivateRoleHandler;
use HangTuah\GateTrail\Mutations\Handlers\RestoreRoleHandler;
use HangTuah\GateTrail\Mutations\Handlers\UpdateRoleHandler;
use HangTuah\GateTrail\RoleChanges;
use HangTuah\GateTrail\RoleReference;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use HangTuah\GateTrail\Tests\TestCase;
use Psr\Clock\ClockInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
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

    public function test_public_api_updates_a_role_through_the_full_path(): void
    {
        $manager = app(RoleManager::class);
        $created = $manager->create(
            'reviewer',
            'web',
            SystemActor::named('definition-suite'),
        );

        $result = $manager->update(
            role: $created->resource,
            changes: RoleChanges::description(
                'Reviews submitted documents',
            ),
            actor: SystemActor::named('definition-suite'),
            reason: 'Clarify role purpose',
            metadata: ['ticket' => 'SEC-42'],
        );
        $audit = $this->app['db']
            ->table('acl_access_audits')
            ->where('id', $result->auditId)
            ->first();

        $this->assertSame(MutationOutcome::Updated, $result->outcome);
        $this->assertSame(
            'Reviews submitted documents',
            $result->resource->getAttribute('description'),
        );
        $this->assertNotNull($audit);
        $this->assertSame('role.updated', $audit->event);
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

    public function test_public_api_clears_a_description_explicitly(): void
    {
        $manager = app(RoleManager::class);
        $manager->create(
            'reviewer',
            'web',
            SystemActor::named('definition-suite'),
            description: 'Obsolete description',
        );

        $result = $manager->update(
            role: 'reviewer',
            changes: RoleChanges::description(null),
            actor: SystemActor::named('definition-suite'),
            reason: 'Remove obsolete description',
        );

        $this->assertSame(MutationOutcome::Updated, $result->outcome);
        $this->assertNull($result->resource->getAttribute('description'));
        $this->assertNotNull($result->auditId);
    }

    public function test_public_api_returns_unchanged_without_audit(): void
    {
        $manager = app(RoleManager::class);
        $manager->create(
            'reviewer',
            'web',
            SystemActor::named('definition-suite'),
            description: 'Reviews documents.',
        );

        $result = $manager->update(
            role: 'reviewer',
            changes: RoleChanges::description('Reviews documents.'),
            actor: SystemActor::named('definition-suite'),
        );

        $this->assertSame(MutationOutcome::Unchanged, $result->outcome);
        $this->assertNull($result->auditId);
        $this->assertFalse($result->changed());
        $this->assertDatabaseCount('acl_access_audits', 1);
    }

    public function test_public_api_deactivates_a_role_through_the_full_path(): void
    {
        $manager = app(RoleManager::class);
        $created = $manager->create(
            'reviewer',
            'web',
            SystemActor::named('definition-suite'),
            description: 'Reviews documents.',
        );

        $result = $manager->deactivate(
            role: $created->resource,
            actor: SystemActor::named('definition-suite'),
            reason: 'Superseded by a narrower role',
            metadata: ['ticket' => 'SEC-42'],
        );
        $audit = $this->app['db']
            ->table('acl_access_audits')
            ->where('id', $result->auditId)
            ->first();

        $this->assertSame(MutationOutcome::Deactivated, $result->outcome);
        $this->assertTrue($result->changed());
        $this->assertNotNull($result->auditId);
        $this->assertSame(
            '2026-07-29 12:00:00',
            $result->resource->getAttribute('deleted_at')
                ?->format('Y-m-d H:i:s'),
        );
        $this->assertNotNull($audit);
        $this->assertSame('role.deactivated', $audit->event);
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

    public function test_public_deactivation_accepts_every_accepted_input(): void
    {
        $manager = app(RoleManager::class);
        $actor = SystemActor::named('definition-suite');
        $inputs = [
            static fn (Role $role): Role => $role,
            static fn (Role $role): int => (int) $role->getKey(),
            static fn (Role $role): RoleReference => RoleReference::id(
                $role->getKey(),
            ),
            static fn (Role $role): string => 'reviewer',
        ];

        foreach ($inputs as $index => $input) {
            Role::query()->withTrashed()->forceDelete();
            $role = $manager->create(
                'reviewer',
                'web',
                $actor,
            )->resource;

            $result = $manager->deactivate(
                role: $input($role),
                actor: $actor,
            );

            $this->assertSame(
                MutationOutcome::Deactivated,
                $result->outcome,
                "Input [$index] should deactivate the role.",
            );
            $this->assertNotNull($result->auditId);
            $this->assertNotNull(
                Role::query()
                    ->withTrashed()
                    ->whereKey($role->getKey())
                    ->first()
                    ?->getAttribute('deleted_at'),
            );
        }
    }

    public function test_deactivated_role_cannot_be_updated_or_recreated(): void
    {
        $manager = app(RoleManager::class);
        $created = $manager->create(
            'reviewer',
            'web',
            SystemActor::named('definition-suite'),
        );
        $manager->deactivate(
            role: $created->resource,
            actor: SystemActor::named('definition-suite'),
        );

        try {
            $manager->update(
                role: RoleReference::id($created->resource->getKey()),
                changes: RoleChanges::description('Updated purpose'),
                actor: SystemActor::named('definition-suite'),
            );

            $this->fail('A deactivated role should not be updatable.');
        } catch (RoleNotFound $exception) {
            $this->assertSame('web', $exception->guard);
        }

        try {
            $manager->create(
                'reviewer',
                'web',
                SystemActor::named('definition-suite'),
            );

            $this->fail('A deactivated namespace should remain occupied.');
        } catch (DuplicateRole) {
            $this->assertDatabaseCount('acl_access_audits', 2);
        }
    }

    public function test_public_api_restores_a_role_through_the_full_path(): void
    {
        $manager = app(RoleManager::class);
        $created = $manager->create(
            'reviewer',
            'web',
            SystemActor::named('definition-suite'),
            description: 'Reviews documents.',
        );
        $manager->deactivate(
            role: $created->resource,
            actor: SystemActor::named('definition-suite'),
        );

        $result = $manager->restore(
            role: $created->resource,
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
        $this->assertNotNull($result->auditId);
        $this->assertNull($result->resource->getAttribute('deleted_at'));
        $this->assertNotNull($audit);
        $this->assertSame('role.restored', $audit->event);
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

    public function test_public_restoration_accepts_every_accepted_input(): void
    {
        $manager = app(RoleManager::class);
        $actor = SystemActor::named('definition-suite');
        $inputs = [
            static fn (Role $role): Role => $role,
            static fn (Role $role): int => (int) $role->getKey(),
            static fn (Role $role): RoleReference => RoleReference::id(
                $role->getKey(),
            ),
            static fn (Role $role): string => 'reviewer',
        ];

        foreach ($inputs as $index => $input) {
            Role::query()->withTrashed()->forceDelete();
            $role = $manager->create('reviewer', 'web', $actor)->resource;
            $manager->deactivate(role: $role, actor: $actor);

            $result = $manager->restore(
                role: $input($role),
                actor: $actor,
            );

            $this->assertSame(
                MutationOutcome::Restored,
                $result->outcome,
                "Input [$index] should restore the role.",
            );
            $this->assertNotNull($result->auditId);
            $this->assertNull(
                Role::query()
                    ->withTrashed()
                    ->whereKey($role->getKey())
                    ->first()
                    ?->getAttribute('deleted_at'),
            );
        }
    }

    public function test_full_role_lifecycle_round_trip(): void
    {
        $manager = app(RoleManager::class);
        $actor = SystemActor::named('definition-suite');

        $created = $manager->create(
            'reviewer',
            'web',
            $actor,
            description: 'Reviews documents.',
        );
        $role = $created->resource;

        $this->assertSame(MutationOutcome::Created, $created->outcome);
        $this->assertSame(
            MutationOutcome::Updated,
            $manager->update(
                role: $role,
                changes: RoleChanges::description('Reviews submissions'),
                actor: $actor,
            )->outcome,
        );
        $this->assertSame(
            MutationOutcome::Deactivated,
            $manager->deactivate(role: $role, actor: $actor)->outcome,
        );
        $this->assertSame(
            MutationOutcome::Restored,
            $manager->restore(role: $role, actor: $actor)->outcome,
        );

        // Both active-role mutations work again once the definition is back.
        $this->assertSame(
            MutationOutcome::Updated,
            $manager->update(
                role: $role,
                changes: RoleChanges::description('Reviews everything'),
                actor: $actor,
            )->outcome,
        );
        $this->assertSame(
            MutationOutcome::Deactivated,
            $manager->deactivate(role: $role, actor: $actor)->outcome,
        );
        $this->assertSame(
            MutationOutcome::Restored,
            $manager->restore(role: $role, actor: $actor)->outcome,
        );

        $this->assertSame(
            'Reviews everything',
            Role::query()->sole()->getAttribute('description'),
        );
        $this->assertSame('reviewer', Role::query()->sole()->getAttribute('name'));
        $this->assertDatabaseCount('acl_access_audits', 7);

        try {
            $manager->create('reviewer', 'web', $actor);

            $this->fail('A restored namespace should remain occupied.');
        } catch (DuplicateRole) {
            $this->assertSame(1, Role::query()->withTrashed()->count());
        }
    }

    public function test_public_contract_exposes_the_four_role_mutations(): void
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

        $this->assertSame(
            ['create', 'update', 'deactivate', 'restore'],
            $methods,
        );

        foreach ($methods as $method) {
            $this->assertSame(
                DefaultRoleManager::class,
                (new ReflectionMethod(DefaultRoleManager::class, $method))
                    ->getDeclaringClass()
                    ->getName(),
            );
        }
    }

    public function test_no_role_mutation_is_declared_as_a_stub(): void
    {
        $handlers = array_values(array_filter(array_map(
            static fn (ReflectionParameter $parameter): ?string => $parameter
                ->getType() instanceof ReflectionNamedType
                    ? $parameter->getType()->getName()
                    : null,
            (new ReflectionClass(DefaultRoleManager::class))
                ->getConstructor()
                ?->getParameters() ?? [],
        )));

        // Every public mutation is backed by an injected single-use-case
        // handler, so none of the four can be a placeholder body.
        $this->assertSame(
            [
                CreateRoleHandler::class,
                UpdateRoleHandler::class,
                DeactivateRoleHandler::class,
                RestoreRoleHandler::class,
                ClockInterface::class,
            ],
            $handlers,
        );

        foreach (
            ['create', 'update', 'deactivate', 'restore'] as $method
        ) {
            $reflection = new ReflectionMethod(
                DefaultRoleManager::class,
                $method,
            );

            $this->assertFalse($reflection->isAbstract());
            $this->assertSame(
                MutationResult::class,
                $reflection->getReturnType() instanceof ReflectionNamedType
                    ? $reflection->getReturnType()->getName()
                    : null,
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
            ['role', 'changes', 'actor', 'reason', 'metadata'],
            $this->parameters('update'),
        );
        $this->assertSame(
            ['role', 'actor', 'reason', 'metadata'],
            $this->parameters('deactivate'),
        );
        $this->assertSame(
            ['role', 'actor', 'reason', 'metadata'],
            $this->parameters('restore'),
        );
    }

    /**
     * @return list<string>
     */
    private function parameters(string $method): array
    {
        return array_map(
            static fn (ReflectionParameter $parameter): string => $parameter
                ->getName(),
            (new ReflectionMethod(RoleManager::class, $method))
                ->getParameters(),
        );
    }
}

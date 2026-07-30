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
use HangTuah\GateTrail\RoleChanges;
use HangTuah\GateTrail\RoleReference;
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

    public function test_current_public_contract_exposes_create_update_and_deactivate(): void
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

        $this->assertSame(['create', 'update', 'deactivate'], $methods);
        $this->assertFalse(
            (new ReflectionClass(RoleManager::class))->hasMethod('restore'),
        );
        $this->assertFalse(
            (new ReflectionClass(DefaultRoleManager::class))
                ->hasMethod('restore'),
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

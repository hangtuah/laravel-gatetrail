<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use HangTuah\GateTrail\Exceptions\GuardMismatch;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\Mutations\RolePermissionDefinitionResolver;
use HangTuah\GateTrail\Tests\TestCase;
use LogicException;

final class RolePermissionDefinitionResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);
    }

    public function test_two_names_use_the_configured_default_guard(): void
    {
        config()->set('gatetrail.guards.default', 'api');
        Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'api',
        ]);
        Permission::query()->create([
            'name' => 'documents.approve',
            'guard_name' => 'api',
        ]);

        [$role, $permission, $guard] = $this->insideTransaction(
            static fn (
                RolePermissionDefinitionResolver $resolver,
            ): array => $resolver->resolve(
                'reviewer',
                'documents.approve',
            ),
        );

        $this->assertSame('reviewer', $role->getAttribute('name'));
        $this->assertSame(
            'documents.approve',
            $permission->getAttribute('name'),
        );
        $this->assertSame('api', $guard);
    }

    public function test_explicit_role_supplies_guard_for_permission_name(): void
    {
        $role = Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'api',
        ]);
        Permission::query()->create([
            'name' => 'documents.approve',
            'guard_name' => 'api',
        ]);

        [, $permission, $guard] = $this->insideTransaction(
            static fn (
                RolePermissionDefinitionResolver $resolver,
            ): array => $resolver->resolve(
                $role,
                'documents.approve',
            ),
        );

        $this->assertSame(
            'documents.approve',
            $permission->getAttribute('name'),
        );
        $this->assertSame('api', $guard);
    }

    public function test_explicit_definitions_with_different_guards_fail(): void
    {
        $role = Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'web',
        ]);
        $permission = Permission::query()->create([
            'name' => 'documents.approve',
            'guard_name' => 'api',
        ]);

        $this->expectException(GuardMismatch::class);
        $this->expectExceptionMessage(
            'role guard [web] does not match permission guard [api]',
        );

        $this->insideTransaction(
            static fn (
                RolePermissionDefinitionResolver $resolver,
            ): array => $resolver->resolve($role, $permission),
        );
    }

    public function test_resolution_outside_a_transaction_is_rejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('inside a database transaction');

        app(RolePermissionDefinitionResolver::class)->resolve(
            'reviewer',
            'documents.approve',
        );
    }

    /**
     * @template T
     *
     * @param  callable(RolePermissionDefinitionResolver): T  $callback
     * @return T
     */
    private function insideTransaction(callable $callback): mixed
    {
        return $this->app['db']
            ->connection('testing')
            ->transaction(
                fn (): mixed => $callback(
                    app(RolePermissionDefinitionResolver::class),
                ),
            );
    }
}

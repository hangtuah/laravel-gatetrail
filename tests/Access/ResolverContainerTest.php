<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Access;

use HangTuah\GateTrail\Access\DefaultPermissionResolver;
use HangTuah\GateTrail\Access\DefaultRoleResolver;
use HangTuah\GateTrail\Access\EffectiveAccessQueryBuilder;
use HangTuah\GateTrail\Access\EffectiveAccessResolver;
use HangTuah\GateTrail\Access\EffectiveAccessRowMapper;
use HangTuah\GateTrail\Access\RequestedDefinitionNormalizer;
use HangTuah\GateTrail\Contracts\AssignmentManager;
use HangTuah\GateTrail\Contracts\PermissionManager;
use HangTuah\GateTrail\Contracts\PermissionResolver;
use HangTuah\GateTrail\Contracts\RoleManager;
use HangTuah\GateTrail\Contracts\RoleResolver;
use HangTuah\GateTrail\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

/**
 * How the public resolvers are wired, and why it has to be that way.
 *
 * The failure this file exists to prevent is a **scope leak**. The internal
 * `EffectiveAccessResolver` is scoped because it is where Increment 8's
 * request memo will live; if either public adapter were a singleton, it would
 * capture that scoped resolver at first resolution and keep serving the same
 * one for the whole life of a long-lived worker. One request's authorization
 * state would then answer the next request's questions — silently, and in the
 * direction that over-grants.
 *
 * A binding is not asserted by reading the provider. Every check below
 * resolves through the container and observes what actually comes back,
 * including across a scope flush.
 */
final class ResolverContainerTest extends TestCase
{
    /**
     * @return array<string, array{class-string, class-string}>
     */
    public static function resolvers(): array
    {
        return [
            'permission' => [PermissionResolver::class, DefaultPermissionResolver::class],
            'role' => [RoleResolver::class, DefaultRoleResolver::class],
        ];
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function scopedServices(): array
    {
        return [
            'internal resolver' => [EffectiveAccessResolver::class],
            'permission contract' => [PermissionResolver::class],
            'role contract' => [RoleResolver::class],
            'permission adapter' => [DefaultPermissionResolver::class],
            'role adapter' => [DefaultRoleResolver::class],
        ];
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function singletonServices(): array
    {
        return [
            'query builder' => [EffectiveAccessQueryBuilder::class],
            'row mapper' => [EffectiveAccessRowMapper::class],
            'input normalizer' => [RequestedDefinitionNormalizer::class],
            'assignment manager' => [AssignmentManager::class],
            'role manager' => [RoleManager::class],
            'permission manager' => [PermissionManager::class],
        ];
    }

    // -------------------------------------------------------------- Bindings

    #[DataProvider('resolvers')]
    public function test_each_contract_resolves_to_its_real_adapter(
        string $contract,
        string $concrete,
    ): void {
        $this->assertTrue($this->app->bound($contract));

        $resolved = $this->app->make($contract);

        $this->assertInstanceOf($concrete, $resolved);
        $this->assertInstanceOf($contract, $resolved);
    }

    /**
     * The placeholder Increment 2 refused to bind. A null object answering
     * `false` would be indistinguishable from a legitimate deny, so the
     * adapter must genuinely delegate.
     */
    #[DataProvider('resolvers')]
    public function test_the_adapter_holds_the_scoped_internal_resolver(
        string $contract,
        string $concrete,
    ): void {
        $adapter = $this->app->make($contract);
        $access = new ReflectionProperty($concrete, 'access');

        $this->assertInstanceOf(
            EffectiveAccessResolver::class,
            $access->getValue($adapter),
        );
        $this->assertSame(
            $this->app->make(EffectiveAccessResolver::class),
            $access->getValue($adapter),
            'The adapter must share the scope’s resolver, not build its own.',
        );
    }

    /**
     * The contract and its concrete class are one object inside a scope, so a
     * consumer injecting either sees the same state.
     */
    #[DataProvider('resolvers')]
    public function test_the_contract_and_the_concrete_class_are_one_instance(
        string $contract,
        string $concrete,
    ): void {
        $this->assertSame(
            $this->app->make($contract),
            $this->app->make($concrete),
        );
    }

    #[DataProvider('scopedServices')]
    public function test_repeated_resolution_returns_the_same_instance(
        string $abstract,
    ): void {
        $this->assertSame($this->app->make($abstract), $this->app->make($abstract));
    }

    // ------------------------------------------------------- Scope boundaries

    #[DataProvider('scopedServices')]
    public function test_a_new_scope_receives_a_different_instance(
        string $abstract,
    ): void {
        $first = $this->app->make($abstract);

        $this->app->forgetScopedInstances();

        $this->assertNotSame($first, $this->app->make($abstract));
    }

    /**
     * The contrast that gives the assertion above its meaning: stateless
     * collaborators are singletons and must survive the same flush.
     */
    #[DataProvider('singletonServices')]
    public function test_a_singleton_survives_a_scope_flush(string $abstract): void
    {
        $first = $this->app->make($abstract);

        $this->app->forgetScopedInstances();

        $this->assertSame($first, $this->app->make($abstract));
    }

    /**
     * The leak itself. After a scope flush the adapters must be holding the
     * **new** internal resolver — an adapter that survived would still be
     * pointing at the discarded one.
     */
    #[DataProvider('resolvers')]
    public function test_no_adapter_carries_a_stale_resolver_across_scopes(
        string $contract,
        string $concrete,
    ): void {
        $firstAdapter = $this->app->make($contract);
        $firstAccess = new ReflectionProperty($concrete, 'access');
        $stale = $firstAccess->getValue($firstAdapter);

        $this->app->forgetScopedInstances();

        $secondAdapter = $this->app->make($contract);
        $fresh = $firstAccess->getValue($secondAdapter);

        $this->assertNotSame($firstAdapter, $secondAdapter);
        $this->assertNotSame($stale, $fresh);
        $this->assertSame(
            $this->app->make(EffectiveAccessResolver::class),
            $fresh,
        );
    }

    /**
     * A singleton anywhere in the graph would pin the scoped resolver. Asserted
     * by resolving every singleton first, flushing, and checking that none of
     * them handed back the discarded resolver.
     */
    public function test_no_singleton_captures_the_scoped_resolver(): void
    {
        $stale = $this->app->make(EffectiveAccessResolver::class);

        foreach (self::singletonServices() as [$abstract]) {
            $this->app->make($abstract);
        }

        $this->app->forgetScopedInstances();

        $fresh = $this->app->make(EffectiveAccessResolver::class);

        $this->assertNotSame($stale, $fresh);

        foreach (self::singletonServices() as [$abstract]) {
            foreach ($this->heldObjects($this->app->make($abstract)) as $held) {
                $this->assertNotSame(
                    $stale,
                    $held,
                    sprintf('[%s] must not retain a scoped resolver.', $abstract),
                );
            }
        }
    }

    // ------------------------------------------------------- Existing wiring

    #[DataProvider('singletonServices')]
    public function test_existing_manager_bindings_are_unchanged(
        string $abstract,
    ): void {
        $this->assertTrue($this->app->bound($abstract));
        $this->assertInstanceOf($abstract, $this->app->make($abstract));
    }

    public function test_nothing_is_aliased_in_place_of_a_real_binding(): void
    {
        foreach ([PermissionResolver::class, RoleResolver::class] as $contract) {
            $this->assertFalse(
                $this->app->isAlias($contract),
                'The contract is bound to a real adapter, not aliased.',
            );
        }
    }

    /**
     * @return list<object>
     */
    private function heldObjects(object $service): array
    {
        $held = [];

        foreach ((new \ReflectionObject($service))->getProperties() as $property) {
            $value = $property->getValue($service);

            if (is_object($value)) {
                $held[] = $value;
            }
        }

        return $held;
    }
}

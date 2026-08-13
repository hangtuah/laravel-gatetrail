<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Contracts;

use HangTuah\GateTrail\Contracts\AssignmentManager;
use HangTuah\GateTrail\Contracts\PermissionManager;
use HangTuah\GateTrail\Contracts\PermissionResolver;
use HangTuah\GateTrail\Contracts\RoleManager;
use HangTuah\GateTrail\Contracts\RoleResolver;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Contracts\Container\BindingResolutionException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Records why the resolver contracts are deliberately unbound.
 *
 * ADR 0012 Increment 2 ships contracts with no implementation behind them; the
 * concrete `DefaultPermissionResolver` and `DefaultRoleResolver` arrive in
 * Increment 7. An interface cannot have a meaningful container binding without
 * a concrete class, so binding one now would require a fake, a throwing
 * placeholder or a null object — each of which is a lie the container would
 * happily hand to a consumer.
 *
 * These tests assert the package's own explicit binding decisions, not
 * Laravel's resolution internals, so they stay valid across framework
 * versions and will fail loudly if a placeholder is ever slipped in.
 */
final class ResolverBindingDeferralTest extends TestCase
{
    /**
     * @return array<string, array{class-string}>
     */
    public static function resolverContracts(): array
    {
        return [
            'permission resolver' => [PermissionResolver::class],
            'role resolver' => [RoleResolver::class],
        ];
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function boundManagerContracts(): array
    {
        return [
            'assignment manager' => [AssignmentManager::class],
            'role manager' => [RoleManager::class],
            'permission manager' => [PermissionManager::class],
        ];
    }

    #[DataProvider('resolverContracts')]
    public function test_the_provider_registers_no_resolver_binding(
        string $contract,
    ): void {
        $this->assertFalse(
            $this->app->bound($contract),
            sprintf(
                '[%s] must stay unbound until Increment 7 supplies a real implementation.',
                $contract,
            ),
        );
    }

    /**
     * Nothing may be aliased to the contract either, which is the other way a
     * placeholder could satisfy the container.
     */
    #[DataProvider('resolverContracts')]
    public function test_no_alias_stands_in_for_a_resolver(
        string $contract,
    ): void {
        $this->assertFalse($this->app->isAlias($contract));
        $this->assertFalse($this->app->resolved($contract));
    }

    /**
     * Resolving must fail outright rather than yield something that looks
     * usable. A deny returned by a null object would be indistinguishable
     * from a real authorization decision.
     */
    #[DataProvider('resolverContracts')]
    public function test_resolving_a_resolver_fails_rather_than_returning_a_placeholder(
        string $contract,
    ): void {
        $this->expectException(BindingResolutionException::class);

        $this->app->make($contract);
    }

    /**
     * The comparison that gives the assertions above their meaning: the
     * package does bind its contracts once implementations exist, so an
     * unbound resolver is a sequencing decision rather than an oversight.
     */
    #[DataProvider('boundManagerContracts')]
    public function test_contracts_with_implementations_are_bound(
        string $contract,
    ): void {
        $this->assertTrue($this->app->bound($contract));
        $this->assertInstanceOf($contract, $this->app->make($contract));
    }
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Contracts;

use HangTuah\GateTrail\Access\DefaultPermissionResolver;
use HangTuah\GateTrail\Access\DefaultRoleResolver;
use HangTuah\GateTrail\Access\EffectivePermission;
use HangTuah\GateTrail\Access\EffectiveRole;
use HangTuah\GateTrail\Contracts\PermissionContract;
use HangTuah\GateTrail\Contracts\PermissionResolver;
use HangTuah\GateTrail\Contracts\RoleContract;
use HangTuah\GateTrail\Contracts\RoleResolver;
use HangTuah\GateTrail\PermissionReference;
use HangTuah\GateTrail\RoleReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;

/**
 * Freezes the two public resolver contracts.
 *
 * Phase 6 builds five adapters on these signatures, so a silent change here
 * would propagate into the Gate hook, the subject concern, six middleware
 * aliases and Blade. Each rule below protects a decision recorded in ADR 0012
 * rather than restating PHP syntax.
 */
final class ResolverContractTest extends TestCase
{
    /**
     * @return array<string, array{class-string, string, string, class-string}>
     */
    public static function contracts(): array
    {
        return [
            'permission resolver' => [
                PermissionResolver::class,
                'allows',
                'effectivePermissions',
                EffectivePermission::class,
            ],
            'role resolver' => [
                RoleResolver::class,
                'has',
                'effectiveRoles',
                EffectiveRole::class,
            ],
        ];
    }

    /**
     * @return array<string, array{class-string, array<int, string>}>
     */
    public static function checkInputUnions(): array
    {
        return [
            'permission resolver' => [
                PermissionResolver::class,
                [PermissionContract::class, PermissionReference::class, 'int', 'string'],
            ],
            'role resolver' => [
                RoleResolver::class,
                [RoleContract::class, RoleReference::class, 'int', 'string'],
            ],
        ];
    }

    #[DataProvider('contracts')]
    public function test_the_contract_exists_in_the_accepted_namespace(
        string $contract,
    ): void {
        $this->assertTrue(interface_exists($contract));
        $this->assertSame(
            'HangTuah\\GateTrail\\Contracts',
            (new ReflectionClass($contract))->getNamespaceName(),
        );
    }

    /**
     * Extra methods are the way a contract quietly grows. ADR 0012 keeps
     * `hasAny()`, `hasAll()`, `allowsAny()` and `allowsAll()` out of v1.
     */
    #[DataProvider('contracts')]
    public function test_it_declares_exactly_two_methods(
        string $contract,
        string $check,
        string $collection,
    ): void {
        $names = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass($contract))->getMethods(),
        );

        sort($names);
        $expected = [$check, $collection];
        sort($expected);

        $this->assertSame($expected, $names);
    }

    #[DataProvider('contracts')]
    public function test_the_boolean_check_takes_a_model_then_the_definition(
        string $contract,
        string $check,
    ): void {
        $parameters = (new ReflectionMethod($contract, $check))->getParameters();

        $this->assertCount(2, $parameters);
        $this->assertSame('subject', $parameters[0]->getName());
        $this->assertSame(0, $parameters[0]->getPosition());

        $subject = $parameters[0]->getType();

        $this->assertInstanceOf(ReflectionNamedType::class, $subject);
        $this->assertSame(Model::class, $subject->getName());
        $this->assertFalse($subject->allowsNull());
    }

    #[DataProvider('checkInputUnions')]
    public function test_the_definition_input_union_is_exact(
        string $contract,
        array $expected,
    ): void {
        $method = $contract === PermissionResolver::class ? 'allows' : 'has';
        $parameters = (new ReflectionMethod($contract, $method))->getParameters();

        sort($expected);

        $this->assertSame($expected, $this->typeNames($parameters[1]->getType()));
    }

    /**
     * The output types are absent from the input union deliberately, so a
     * value a consumer holds cannot be resubmitted as evidence of access.
     */
    #[DataProvider('contracts')]
    public function test_the_output_type_is_not_an_accepted_input(
        string $contract,
        string $check,
        string $collection,
        string $element,
    ): void {
        foreach ([$check, $collection] as $method) {
            foreach ((new ReflectionMethod($contract, $method))->getParameters() as $parameter) {
                $this->assertNotContains(
                    $element,
                    $this->typeNames($parameter->getType()),
                    sprintf('[%s] must not accept its own output type.', $method),
                );
            }
        }
    }

    #[DataProvider('contracts')]
    public function test_the_boolean_check_returns_bool(
        string $contract,
        string $check,
    ): void {
        $type = (new ReflectionMethod($contract, $check))->getReturnType();

        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame('bool', $type->getName());
        $this->assertFalse($type->allowsNull());
    }

    #[DataProvider('contracts')]
    public function test_the_collection_method_takes_only_a_model(
        string $contract,
        string $check,
        string $collection,
    ): void {
        $parameters = (new ReflectionMethod($contract, $collection))->getParameters();

        $this->assertCount(1, $parameters);
        $this->assertSame('subject', $parameters[0]->getName());

        $type = $parameters[0]->getType();

        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame(Model::class, $type->getName());
    }

    #[DataProvider('contracts')]
    public function test_the_collection_method_returns_a_laravel_collection(
        string $contract,
        string $check,
        string $collection,
    ): void {
        $type = (new ReflectionMethod($contract, $collection))->getReturnType();

        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame(Collection::class, $type->getName());
        $this->assertFalse($type->allowsNull());
    }

    /**
     * The element type lives only in PHPDoc, so PHPStan is the only thing
     * enforcing it. A drifting generic would silently widen the contract.
     */
    #[DataProvider('contracts')]
    public function test_the_collection_generic_names_its_element_type(
        string $contract,
        string $check,
        string $collection,
        string $element,
    ): void {
        $doc = (new ReflectionMethod($contract, $collection))->getDocComment();

        $this->assertIsString($doc);
        $this->assertStringContainsString(
            sprintf('@return Collection<int, %s>', class_basename($element)),
            $doc,
        );
    }

    /**
     * `mixed` was the specification's provisional subject type. It must not
     * survive anywhere in the frozen surface, including the generics.
     */
    #[DataProvider('contracts')]
    public function test_no_mixed_appears_in_the_public_surface(
        string $contract,
    ): void {
        foreach ((new ReflectionClass($contract))->getMethods() as $method) {
            foreach ($method->getParameters() as $parameter) {
                $this->assertNotContains(
                    'mixed',
                    $this->typeNames($parameter->getType()),
                    sprintf('[%s] must not accept mixed.', $method->getName()),
                );
            }

            $this->assertNotContains(
                'mixed',
                $this->typeNames($method->getReturnType()),
                sprintf('[%s] must not return mixed.', $method->getName()),
            );

            $doc = $method->getDocComment();

            if (is_string($doc)) {
                $this->assertStringNotContainsString('mixed', $doc);
            }
        }
    }

    /**
     * An interface cannot hold an algorithm, but it can hold a default method
     * body in a trait-like sense only if someone changes it to an abstract
     * class. Asserting it stays an interface keeps the boundary honest.
     */
    #[DataProvider('contracts')]
    public function test_it_carries_no_implementation(string $contract): void
    {
        $reflection = new ReflectionClass($contract);

        $this->assertTrue($reflection->isInterface());
        $this->assertSame([], $reflection->getProperties());
        $this->assertSame([], $reflection->getConstants());

        foreach ($reflection->getMethods() as $method) {
            $this->assertTrue($method->isAbstract());
            $this->assertTrue($method->isPublic());
            $this->assertFalse($method->isStatic());
        }
    }

    /**
     * Increment 7 supplied the implementations the contracts were shipped
     * without. The package must own exactly one of each: a second
     * implementation inside GateTrail would be a second algorithm, which is
     * what one shared snapshot exists to prevent. A consuming application is
     * free to add its own, so only this namespace is checked.
     */
    #[DataProvider('contracts')]
    public function test_the_package_ships_exactly_one_implementation(
        string $contract,
    ): void {
        $expected = $contract === PermissionResolver::class
            ? DefaultPermissionResolver::class
            : DefaultRoleResolver::class;

        $this->assertTrue(class_exists($expected));
        $this->assertTrue(is_subclass_of($expected, $contract));

        $reflection = new ReflectionClass($expected);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());

        $implementations = array_values(array_filter(
            get_declared_classes(),
            static fn (string $class): bool => is_subclass_of($class, $contract)
                && str_starts_with($class, 'HangTuah\\GateTrail\\'),
        ));

        $this->assertSame([$expected], $implementations);
    }

    /**
     * @return array<int, string>
     */
    private function typeNames(?\ReflectionType $type): array
    {
        if ($type === null) {
            return [];
        }

        if ($type instanceof ReflectionNamedType) {
            return [$type->getName()];
        }

        $this->assertInstanceOf(ReflectionUnionType::class, $type);

        $names = array_map(
            static function (\ReflectionType $member): string {
                return $member instanceof ReflectionNamedType
                    ? $member->getName()
                    : (string) $member;
            },
            $type->getTypes(),
        );

        sort($names);

        return $names;
    }
}

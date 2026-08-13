<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Access;

use Error;
use HangTuah\GateTrail\Access\EffectivePermission;
use HangTuah\GateTrail\Access\EffectiveRole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;

/**
 * Freezes the shape of the two effective-access output types.
 *
 * Both are public compatibility surface for reading, so their class names,
 * field names and field types cannot drift. Construction is deliberately not
 * surface, which is asserted here rather than merely documented.
 */
final class EffectiveAccessValueObjectTest extends TestCase
{
    /**
     * @return array<string, array{class-string}>
     */
    public static function valueObjects(): array
    {
        return [
            'effective permission' => [EffectivePermission::class],
            'effective role' => [EffectiveRole::class],
        ];
    }

    /**
     * @return array<string, array{class-string, int|string}>
     */
    public static function identifiers(): array
    {
        $cases = [];

        foreach (self::valueObjects() as $label => [$class]) {
            $cases[$label.', integer'] = [$class, 7];
            $cases[$label.', numeric string'] = [$class, '7'];
            $cases[$label.', leading zero string'] = [$class, '007'];
            $cases[$label.', uuid string'] = [
                $class,
                '0193c4a1-6f2e-7a3b-9c4d-5e6f70819a2b',
            ];
        }

        return $cases;
    }

    #[DataProvider('valueObjects')]
    public function test_it_lives_in_the_accepted_namespace(string $class): void
    {
        $this->assertTrue(class_exists($class));
        $this->assertSame(
            'HangTuah\\GateTrail\\Access',
            (new ReflectionClass($class))->getNamespaceName(),
        );
    }

    #[DataProvider('valueObjects')]
    public function test_it_is_final_and_readonly(string $class): void
    {
        $reflection = new ReflectionClass($class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    /**
     * A public constructor would make the parameter list frozen surface and
     * would let a consumer fabricate an authorization-shaped value.
     */
    #[DataProvider('valueObjects')]
    public function test_construction_is_package_controlled(string $class): void
    {
        $constructor = (new ReflectionClass($class))->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPrivate());
    }

    #[DataProvider('valueObjects')]
    public function test_its_shape_is_exactly_id_name_and_guard_name(
        string $class,
    ): void {
        $expected = [
            'id' => ['int', 'string'],
            'name' => ['string'],
            'guardName' => ['string'],
        ];
        $actual = [];

        foreach ((new ReflectionClass($class))->getProperties() as $property) {
            $this->assertTrue($property->isPublic());
            $this->assertTrue($property->isReadOnly());
            $this->assertFalse($property->isStatic());

            $actual[$property->getName()] = $this->typeNames($property->getType());
        }

        $this->assertSame($expected, $actual);
    }

    #[DataProvider('valueObjects')]
    public function test_the_named_factory_is_public_static_and_internal(
        string $class,
    ): void {
        $factory = new ReflectionMethod($class, 'of');

        $this->assertTrue($factory->isPublic());
        $this->assertTrue($factory->isStatic());

        $doc = $factory->getDocComment();

        $this->assertIsString($doc);
        $this->assertStringContainsString('@internal', $doc);
        $this->assertStringContainsString(
            'Not supported consumer API',
            $doc,
            'The factory must state that callability is not support.',
        );
    }

    #[DataProvider('valueObjects')]
    public function test_the_factory_parameters_mirror_the_property_shape(
        string $class,
    ): void {
        $parameters = (new ReflectionMethod($class, 'of'))->getParameters();
        $actual = [];

        foreach ($parameters as $parameter) {
            $actual[$parameter->getName()] = $this->typeNames($parameter->getType());
        }

        $this->assertSame([
            'id' => ['int', 'string'],
            'name' => ['string'],
            'guardName' => ['string'],
        ], $actual);
    }

    /**
     * `of()` is the only static entry point. A second one would be a consumer
     * factory in all but name.
     */
    #[DataProvider('valueObjects')]
    public function test_no_other_public_factory_exists(string $class): void
    {
        $reflection = new ReflectionClass($class);
        $names = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $names[] = $method->getName();
        }

        sort($names);

        $this->assertSame(['of'], $names);
    }

    #[DataProvider('identifiers')]
    public function test_the_factory_preserves_the_identifier_exactly(
        string $class,
        int|string $id,
    ): void {
        $value = $class::of($id, 'documents.approve', 'web');

        $this->assertSame($id, $value->id);
    }

    #[DataProvider('valueObjects')]
    public function test_it_exposes_the_values_it_was_built_with(
        string $class,
    ): void {
        $value = $class::of(7, 'documents.approve', 'web');

        $this->assertSame(7, $value->id);
        $this->assertSame('documents.approve', $value->name);
        $this->assertSame('web', $value->guardName);
    }

    /**
     * The property is what a consumer holds, so immutability has to hold at
     * runtime and not only in the class declaration.
     */
    #[DataProvider('valueObjects')]
    public function test_its_properties_cannot_be_mutated(string $class): void
    {
        $value = $class::of(7, 'documents.approve', 'web');

        $this->expectException(Error::class);

        $value->name = 'documents.delete';
    }

    #[DataProvider('valueObjects')]
    public function test_it_rejects_undeclared_properties(string $class): void
    {
        $value = $class::of(7, 'documents.approve', 'web');

        $this->expectException(Error::class);

        $value->subjectId = 1;
    }

    /**
     * Nothing mutable, connected or lazily loadable may travel inside these
     * objects — that is the whole reason models were rejected as the element
     * type.
     */
    #[DataProvider('valueObjects')]
    public function test_no_implementation_state_escapes_through_it(
        string $class,
    ): void {
        $value = $class::of(7, 'documents.approve', 'web');

        foreach (get_object_vars($value) as $name => $held) {
            $this->assertIsNotObject(
                $held,
                sprintf('[%s] must not carry an object.', $name),
            );
            $this->assertIsNotArray(
                $held,
                sprintf('[%s] must not carry a mutable structure.', $name),
            );
        }

        $this->assertSame(
            ['id', 'name', 'guardName'],
            array_keys(get_object_vars($value)),
        );
    }

    public function test_both_types_share_one_identical_shape(): void
    {
        $shape = static fn (string $class): array => array_map(
            static fn (\ReflectionProperty $property): string => $property->getName(),
            (new ReflectionClass($class))->getProperties(),
        );

        $this->assertSame(
            $shape(EffectivePermission::class),
            $shape(EffectiveRole::class),
        );
    }

    /**
     * They are distinct types, so a role can never be accepted where a
     * permission is expected.
     */
    public function test_the_two_types_are_not_interchangeable(): void
    {
        $this->assertNotInstanceOf(
            EffectiveRole::class,
            EffectivePermission::of(7, 'documents.approve', 'web'),
        );
        $this->assertNotInstanceOf(
            EffectivePermission::class,
            EffectiveRole::of(7, 'reviewer', 'web'),
        );
    }

    /**
     * @return array<int, string>
     */
    private function typeNames(?\ReflectionType $type): array
    {
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

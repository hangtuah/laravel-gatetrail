<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Diagnostics;

use HangTuah\GateTrail\Diagnostics\StatementMeasurement;
use HangTuah\GateTrail\Tests\TestCase;
use LogicException;
use ReflectionClass;
use ReflectionNamedType;

/**
 * The shape of a measurement, asserted structurally.
 *
 * The privacy claim in ADR 0012 section 8 is only as strong as the type that
 * carries the result. If a measurement could hold one string, every budget
 * assertion in the suite would become a place SQL or an identifier could
 * survive. So the type is pinned by reflection rather than by convention: every
 * property is an `int`, and there is no room for anything else.
 */
final class StatementMeasurementTest extends TestCase
{
    public function test_it_is_final_readonly_and_internal(): void
    {
        $class = new ReflectionClass(StatementMeasurement::class);

        $this->assertTrue($class->isFinal());
        $this->assertTrue($class->isReadOnly());
        $this->assertStringContainsString(
            '@internal',
            (string) $class->getDocComment(),
        );
    }

    /**
     * The whole privacy argument in one assertion: nothing on this object can
     * hold a string, an array, an object or a float.
     */
    public function test_every_property_is_a_plain_integer(): void
    {
        $class = new ReflectionClass(StatementMeasurement::class);
        $properties = $class->getProperties();

        $this->assertNotSame([], $properties);

        foreach ($properties as $property) {
            $type = $property->getType();

            $this->assertInstanceOf(
                ReflectionNamedType::class,
                $type,
                $property->getName().' must carry one simple type.',
            );
            $this->assertSame(
                'int',
                $type->getName(),
                $property->getName().' must be an int counter.',
            );
            $this->assertFalse($type->allowsNull());
            $this->assertTrue($property->isReadOnly());
        }
    }

    public function test_the_category_vocabulary_is_exactly_the_documented_one(): void
    {
        $names = array_map(
            static fn (\ReflectionProperty $property): string => $property->getName(),
            (new ReflectionClass(StatementMeasurement::class))->getProperties(),
        );

        sort($names);

        $this->assertSame(
            ['delete', 'insert', 'other', 'schema', 'select', 'total', 'update'],
            $names,
            'The vocabulary is fixed and closed; adding to it is a decision.',
        );
    }

    public function test_construction_is_package_controlled(): void
    {
        $constructor = (new ReflectionClass(StatementMeasurement::class))
            ->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPrivate());

        foreach (['of', 'none'] as $factory) {
            $this->assertStringContainsString(
                '@internal',
                (string) (new ReflectionClass(StatementMeasurement::class))
                    ->getMethod($factory)
                    ->getDocComment(),
            );
        }
    }

    public function test_total_is_derived_from_the_categories(): void
    {
        $measurement = StatementMeasurement::of(3, 2, 1, 4, 5, 6);

        $this->assertSame(3, $measurement->select);
        $this->assertSame(2, $measurement->insert);
        $this->assertSame(1, $measurement->update);
        $this->assertSame(4, $measurement->delete);
        $this->assertSame(5, $measurement->schema);
        $this->assertSame(6, $measurement->other);
        $this->assertSame(21, $measurement->total);
        $this->assertSame(
            $measurement->select
                + $measurement->insert
                + $measurement->update
                + $measurement->delete
                + $measurement->schema
                + $measurement->other,
            $measurement->total,
        );
    }

    public function test_the_zero_measurement_is_zero_everywhere(): void
    {
        $measurement = StatementMeasurement::none();

        foreach (
            ['total', 'select', 'insert', 'update', 'delete', 'schema', 'other'] as $counter
        ) {
            $this->assertSame(0, $measurement->{$counter});
        }
    }

    public function test_a_negative_counter_is_an_invariant_failure(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'A GateTrail statement count cannot be negative.',
        );

        StatementMeasurement::of(1, 0, 0, -1, 0, 0);
    }

    /**
     * Cheap to assert and worth asserting: whatever a failure message, a dump
     * or a serialized copy contains, it is digits.
     */
    public function test_no_representation_of_it_can_carry_text(): void
    {
        $measurement = StatementMeasurement::of(3, 0, 0, 0, 0, 0);

        foreach ([
            print_r($measurement, true),
            var_export($measurement, true),
            serialize($measurement),
            json_encode(get_object_vars($measurement), JSON_THROW_ON_ERROR),
        ] as $representation) {
            $this->assertDoesNotMatchRegularExpression(
                '/\bselect\b\s+["\'`\[]/i',
                $representation,
                'No representation may contain a SQL fragment.',
            );
        }

        $this->assertSame(
            ['total', 'select', 'insert', 'update', 'delete', 'schema', 'other'],
            array_keys(get_object_vars($measurement)),
        );

        foreach (get_object_vars($measurement) as $value) {
            $this->assertIsInt($value);
        }
    }
}

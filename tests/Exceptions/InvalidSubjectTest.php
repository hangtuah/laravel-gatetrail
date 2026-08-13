<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Exceptions;

use HangTuah\GateTrail\Exceptions\InvalidSubject;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

final class InvalidSubjectTest extends TestCase
{
    /**
     * @return array<string, array{InvalidSubject, string}>
     */
    public static function factories(): array
    {
        return [
            'not persisted' => [
                InvalidSubject::notPersisted('user'),
                InvalidSubject::REASON_NOT_PERSISTED,
            ],
            'missing identifier' => [
                InvalidSubject::missingIdentifier('user'),
                InvalidSubject::REASON_MISSING_IDENTIFIER,
            ],
            'unsupported identifier' => [
                InvalidSubject::unsupportedIdentifier('user'),
                InvalidSubject::REASON_UNSUPPORTED_IDENTIFIER,
            ],
            'mutated identifier' => [
                InvalidSubject::mutatedIdentifier('user'),
                InvalidSubject::REASON_MUTATED_IDENTIFIER,
            ],
            'invalid morph type' => [
                InvalidSubject::invalidMorphType('App\\Models\\User'),
                InvalidSubject::REASON_INVALID_MORPH_TYPE,
            ],
        ];
    }

    /**
     * Identifiers a consuming application might use that must never reach a
     * log aggregator through this exception.
     *
     * @return array<string, array{string}>
     */
    public static function sensitiveIdentifiers(): array
    {
        return [
            'national identifier' => ['880101105432'],
            'customer number' => ['ACC-99871234'],
            'uuid' => ['0193c4a1-6f2e-7a3b-9c4d-5e6f70819a2b'],
            'ulid' => ['01JBQZ9YV8K3M2N4P5R6S7T8V9'],
        ];
    }

    #[DataProvider('factories')]
    public function test_each_factory_carries_its_fixed_reason(
        InvalidSubject $exception,
        string $reason,
    ): void {
        $this->assertSame($reason, $exception->reason);
    }

    #[DataProvider('factories')]
    public function test_every_failure_reports_a_safe_subject_type(
        InvalidSubject $exception,
    ): void {
        $this->assertNotSame('', $exception->subjectType);
        $this->assertStringContainsString(
            $exception->subjectType,
            $exception->getMessage(),
        );
    }

    #[DataProvider('factories')]
    public function test_no_failure_chains_a_previous_exception(
        InvalidSubject $exception,
    ): void {
        $this->assertNull($exception->getPrevious());
    }

    public function test_it_extends_the_invalid_argument_hierarchy(): void
    {
        $this->assertInstanceOf(
            InvalidArgumentException::class,
            InvalidSubject::notPersisted('user'),
        );
    }

    public function test_it_is_final(): void
    {
        $this->assertTrue(
            (new ReflectionClass(InvalidSubject::class))->isFinal(),
        );
    }

    /**
     * A public constructor would let a caller build an instance carrying an
     * arbitrary reason outside the fixed vocabulary.
     */
    public function test_construction_is_restricted_to_the_named_factories(): void
    {
        $constructor = (new ReflectionClass(InvalidSubject::class))
            ->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPrivate());
    }

    public function test_the_reason_vocabulary_is_exactly_the_five_accepted_terms(): void
    {
        $reflection = new ReflectionClass(InvalidSubject::class);

        $this->assertSame([
            'REASON_NOT_PERSISTED' => 'not_persisted',
            'REASON_MISSING_IDENTIFIER' => 'missing_identifier',
            'REASON_UNSUPPORTED_IDENTIFIER' => 'unsupported_identifier',
            'REASON_MUTATED_IDENTIFIER' => 'mutated_identifier',
            'REASON_INVALID_MORPH_TYPE' => 'invalid_morph_type',
        ], $reflection->getConstants());
    }

    /**
     * The public surface is two strings. Anything else — a model, an attribute
     * bag, a connection — would be a channel for the data this exception
     * exists to withhold.
     */
    public function test_its_public_surface_is_only_the_two_safe_strings(): void
    {
        $reflection = new ReflectionClass(InvalidSubject::class);
        $names = [];

        foreach ($reflection->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() !== InvalidSubject::class) {
                continue;
            }

            $names[] = $property->getName();

            $type = $property->getType();

            $this->assertInstanceOf(ReflectionNamedType::class, $type);
            $this->assertSame('string', $type->getName());
            $this->assertTrue($property->isReadOnly());
        }

        sort($names);

        $this->assertSame(['reason', 'subjectType'], $names);
    }

    /**
     * The factories take no identifier at all, so there is no parameter
     * through which one could be captured.
     */
    #[DataProvider('sensitiveIdentifiers')]
    public function test_a_rejected_identifier_cannot_reach_the_exception(
        string $identifier,
    ): void {
        $exception = InvalidSubject::unsupportedIdentifier('user');

        $this->assertStringNotContainsString(
            $identifier,
            $exception->getMessage(),
        );
        $this->assertStringNotContainsString(
            $identifier,
            (string) $exception,
        );
        $this->assertStringNotContainsString(
            $identifier,
            print_r(get_object_vars($exception), true),
        );
    }

    public function test_no_factory_accepts_an_identifier_parameter(): void
    {
        $reflection = new ReflectionClass(InvalidSubject::class);

        foreach ($reflection->getMethods() as $method) {
            if (! $method->isStatic()) {
                continue;
            }

            $this->assertSame(
                ['subjectType'],
                array_map(
                    static fn ($parameter): string => $parameter->getName(),
                    $method->getParameters(),
                ),
                sprintf('[%s] must accept a safe type label only.', $method->getName()),
            );
        }
    }

    public function test_the_message_states_the_type_and_the_reason_only(): void
    {
        $this->assertSame(
            'GateTrail could not resolve a valid subject identity for [user]: [mutated_identifier].',
            InvalidSubject::mutatedIdentifier('user')->getMessage(),
        );
    }
}

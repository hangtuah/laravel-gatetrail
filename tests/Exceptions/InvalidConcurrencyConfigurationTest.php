<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Exceptions;

use HangTuah\GateTrail\Exceptions\InvalidConcurrencyConfiguration;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class InvalidConcurrencyConfigurationTest extends TestCase
{
    public function test_it_reports_the_configuration_key_only(): void
    {
        $exception = InvalidConcurrencyConfiguration::forKey(
            'gatetrail.assignments.concurrency_attempts',
        );

        $this->assertSame(
            'gatetrail.assignments.concurrency_attempts',
            $exception->key,
        );
        $this->assertSame(
            'GateTrail configuration [gatetrail.assignments.concurrency_attempts] '
            .'must be an integer between 1 and 10.',
            $exception->getMessage(),
        );
    }

    public function test_it_has_no_previous_exception(): void
    {
        $this->assertNull(
            InvalidConcurrencyConfiguration::forKey('a.key')->getPrevious(),
        );
    }

    public function test_it_exposes_only_the_key(): void
    {
        $reflection = new ReflectionClass(
            InvalidConcurrencyConfiguration::class,
        );

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue(
            $reflection->isSubclassOf(InvalidArgumentException::class),
        );
        $this->assertTrue(
            $reflection->getConstructor()?->isPrivate() ?? false,
        );
        $this->assertSame(
            ['key'],
            array_map(
                static fn (ReflectionProperty $property): string => $property
                    ->getName(),
                $reflection->getProperties(ReflectionProperty::IS_PUBLIC),
            ),
        );
    }
}

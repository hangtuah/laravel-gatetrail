<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Concurrency;

use HangTuah\GateTrail\Concurrency\RetryPolicy;
use HangTuah\GateTrail\Exceptions\InvalidConcurrencyConfiguration;
use HangTuah\GateTrail\Tests\TestCase;
use ReflectionClass;
use stdClass;

final class RetryPolicyTest extends TestCase
{
    public function test_the_shipped_default_is_three_attempts(): void
    {
        $this->assertSame(3, $this->policy()->attempts());
        $this->assertSame(
            3,
            config('gatetrail.assignments.concurrency_attempts'),
        );
    }

    public function test_the_accepted_range_is_one_to_ten(): void
    {
        foreach ([1, 2, 3, 9, 10] as $attempts) {
            config()->set(RetryPolicy::KEY, $attempts);

            $this->assertSame($attempts, $this->policy()->attempts());
        }
    }

    public function test_values_outside_the_range_are_rejected(): void
    {
        foreach ([0, -1, -10, 11, 100, PHP_INT_MAX] as $attempts) {
            config()->set(RetryPolicy::KEY, $attempts);

            try {
                $this->policy()->attempts();

                $this->fail("[$attempts] should be rejected.");
            } catch (InvalidConcurrencyConfiguration $exception) {
                $this->assertSame(RetryPolicy::KEY, $exception->key);
            }
        }
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function malformedValues(): array
    {
        return [
            'true' => [true],
            'false' => [false],
            'float' => [3.0],
            'numeric string' => ['3'],
            'string' => ['three'],
            'empty string' => [''],
            'null' => [null],
            'list' => [[3]],
            'map' => [['attempts' => 3]],
            'object' => [new stdClass],
        ];
    }

    public function test_no_value_is_ever_coerced(): void
    {
        foreach (self::malformedValues() as $label => [$value]) {
            config()->set(RetryPolicy::KEY, $value);

            try {
                $this->policy()->attempts();

                $this->fail("A [$label] value should be rejected.");
            } catch (InvalidConcurrencyConfiguration $exception) {
                $this->assertSame(RetryPolicy::KEY, $exception->key);
            }
        }
    }

    public function test_a_missing_key_is_invalid_rather_than_defaulted(): void
    {
        config()->set('gatetrail.assignments', []);

        try {
            $this->policy()->attempts();

            $this->fail('A missing key should be rejected.');
        } catch (InvalidConcurrencyConfiguration $exception) {
            $this->assertSame(RetryPolicy::KEY, $exception->key);
        }
    }

    public function test_the_rejected_value_is_never_echoed(): void
    {
        config()->set(RetryPolicy::KEY, 'super-secret-token');

        try {
            $this->policy()->attempts();

            $this->fail('A malformed value should be rejected.');
        } catch (InvalidConcurrencyConfiguration $exception) {
            $this->assertStringNotContainsString(
                'super-secret-token',
                $exception->getMessage(),
            );
            $this->assertSame(
                'GateTrail configuration [gatetrail.assignments.concurrency_attempts] '
                .'must be an integer between 1 and 10.',
                $exception->getMessage(),
            );
        }
    }

    public function test_the_policy_has_no_mutation_name_responsibility(): void
    {
        $methods = array_map(
            static fn (object $method): string => $method->getName(),
            (new ReflectionClass(RetryPolicy::class))->getMethods(),
        );

        // Attempt configuration only: name validation belongs to MutationName.
        $this->assertSame(['__construct', 'attempts'], $methods);
    }

    public function test_the_policy_resolves_from_the_container(): void
    {
        $this->assertInstanceOf(RetryPolicy::class, app(RetryPolicy::class));
        $this->assertSame(app(RetryPolicy::class), app(RetryPolicy::class));
    }

    private function policy(): RetryPolicy
    {
        $this->app->forgetInstance(RetryPolicy::class);

        return $this->app->make(RetryPolicy::class);
    }
}

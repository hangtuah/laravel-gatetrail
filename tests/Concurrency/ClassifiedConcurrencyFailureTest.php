<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Concurrency;

use HangTuah\GateTrail\Concurrency\ClassifiedConcurrencyFailure;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Throwable;

final class ClassifiedConcurrencyFailureTest extends TestCase
{
    private const SECRETS = [
        'select * from acl_assignment_locks',
        'Duplicate entry for key',
        'acl_asgn_locks_pk',
        'Assigned by administrator',
        'hunter2',
    ];

    public function test_it_is_a_value_and_never_a_throwable(): void
    {
        $reflection = new ReflectionClass(ClassifiedConcurrencyFailure::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
        $this->assertFalse(
            $reflection->implementsInterface(Throwable::class),
            'A Throwable would carry a stack trace GateTrail cannot control.',
        );
        $this->assertTrue(
            $reflection->getConstructor()?->isPrivate() ?? false,
        );
    }

    public function test_every_supported_driver_and_category_is_representable(): void
    {
        $drivers = ['mysql', 'pgsql', 'sqlsrv', 'oracle', 'sqlite'];
        $categories = [
            'coordination_race',
            'deadlock',
            'serialization_failure',
        ];

        foreach ($drivers as $driver) {
            foreach ($categories as $category) {
                $failure = ClassifiedConcurrencyFailure::classified(
                    $driver,
                    $category,
                );

                $this->assertSame($driver, $failure->driver);
                $this->assertSame($category, $failure->category);
                $this->assertNull($failure->sqlState);
                $this->assertNull($failure->vendorCode);
            }
        }
    }

    public function test_the_adr_classification_matrix_is_representable(): void
    {
        $matrix = [
            ['pgsql', 'coordination_race', '23505', null],
            ['oracle', 'coordination_race', '23000', 1],
            ['sqlsrv', 'coordination_race', '23000', 2627],
            ['sqlite', 'coordination_race', '23000', 19],
            ['mysql', 'coordination_race', '23000', 1062],
            ['pgsql', 'serialization_failure', '40001', null],
            ['pgsql', 'deadlock', '40P01', null],
            ['mysql', 'deadlock', '40001', 1213],
            ['sqlsrv', 'deadlock', '40001', 1205],
            ['oracle', 'deadlock', '61000', 60],
            ['oracle', 'serialization_failure', '72000', 8177],
        ];

        foreach ($matrix as [$driver, $category, $sqlState, $vendorCode]) {
            $failure = ClassifiedConcurrencyFailure::classified(
                $driver,
                $category,
                $sqlState,
                $vendorCode,
            );

            $this->assertSame($sqlState, $failure->sqlState);
            $this->assertSame($vendorCode, $failure->vendorCode);
        }
    }

    public function test_an_unsupported_driver_is_rejected(): void
    {
        foreach (['', ' ', 'MySQL', 'mariadb', 'mongodb', 'postgres'] as $driver) {
            try {
                ClassifiedConcurrencyFailure::classified(
                    $driver,
                    'deadlock',
                );

                $this->fail("Driver [$driver] should be rejected.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString(
                    'unsupported driver',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_an_unsupported_category_is_rejected(): void
    {
        foreach ([
            '',
            ' ',
            'Deadlock',
            'lock_timeout',
            'database_busy',
            'unique_violation',
        ] as $category) {
            try {
                ClassifiedConcurrencyFailure::classified('mysql', $category);

                $this->fail("Category [$category] should be rejected.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString(
                    'unsupported concurrency failure category',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_only_a_canonical_sqlstate_is_accepted(): void
    {
        foreach ([
            '',
            '2350',
            '235055',
            '23-05',
            'SQLSTATE[23505]',
            'Duplicate entry for key acl_asgn_locks_pk',
        ] as $sqlState) {
            try {
                ClassifiedConcurrencyFailure::classified(
                    'mysql',
                    'deadlock',
                    $sqlState,
                );

                $this->fail('A malformed SQLSTATE should be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString(
                    'five alphanumeric characters',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_the_object_graph_holds_only_the_four_safe_scalars(): void
    {
        $failure = ClassifiedConcurrencyFailure::classified(
            'mysql',
            'deadlock',
            '40001',
            1213,
        );

        $this->assertSame(
            ['driver', 'category', 'sqlState', 'vendorCode'],
            array_keys(get_object_vars($failure)),
        );

        foreach (get_object_vars($failure) as $value) {
            $this->assertTrue(
                $value === null || is_string($value) || is_int($value),
                'Only nullable scalars may be stored.',
            );
        }
    }

    public function test_no_sensitive_string_can_enter_the_value(): void
    {
        $failure = ClassifiedConcurrencyFailure::classified(
            'mysql',
            'deadlock',
            '40001',
            1213,
        );
        $serialized = json_encode(
            get_object_vars($failure),
            JSON_THROW_ON_ERROR,
        );

        foreach (self::SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }

        // Every rejected field above proves there is no undocumented door:
        // the only string inputs are a fixed driver list, a fixed category
        // list, and a five-character SQLSTATE.
        foreach (self::SECRETS as $secret) {
            foreach ([
                static fn (): mixed => ClassifiedConcurrencyFailure::classified(
                    $secret,
                    'deadlock',
                ),
                static fn (): mixed => ClassifiedConcurrencyFailure::classified(
                    'mysql',
                    $secret,
                ),
                static fn (): mixed => ClassifiedConcurrencyFailure::classified(
                    'mysql',
                    'deadlock',
                    $secret,
                ),
            ] as $attempt) {
                try {
                    $attempt();

                    $this->fail('A planted secret should be rejected.');
                } catch (InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Exceptions;

use HangTuah\GateTrail\Concurrency\ClassifiedConcurrencyFailure;
use HangTuah\GateTrail\Exceptions\ConcurrencyRetryExhausted;
use HangTuah\GateTrail\Mutations\MutationName;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

final class ConcurrencyRetryExhaustedTest extends TestCase
{
    private const SECRETS = [
        'select * from acl_assignment_locks',
        'Duplicate entry',
        'acl_asgn_locks_pk',
        'acl_role_assignments',
        'Assigned by administrator',
        'SEC-42',
        'hunter2',
    ];

    public function test_owned_exhaustion_reports_the_attempts_it_used(): void
    {
        $exception = ConcurrencyRetryExhausted::afterAttempts(
            MutationName::ROLE_ASSIGNED,
            3,
            $this->failure(),
        );

        $this->assertSame('role.assigned', $exception->operation);
        $this->assertSame(3, $exception->attempts);
        $this->assertFalse($exception->callerOwned);
        $this->assertSame(
            'GateTrail could not complete [role.assigned] after 3 concurrent attempts.',
            $exception->getMessage(),
        );
    }

    public function test_a_caller_owned_failure_reports_a_single_attempt(): void
    {
        $exception = ConcurrencyRetryExhausted::insideCallerTransaction(
            MutationName::PERMISSION_GRANTED_TO_ROLE,
            $this->failure(),
        );

        $this->assertSame(
            'permission.granted_to_role',
            $exception->operation,
        );
        $this->assertSame(1, $exception->attempts);
        $this->assertTrue($exception->callerOwned);
        $this->assertSame(
            'GateTrail could not complete [permission.granted_to_role] '
            .'inside a caller-owned transaction under concurrent load.',
            $exception->getMessage(),
        );
    }

    public function test_the_previous_chain_is_always_empty(): void
    {
        foreach ([
            ConcurrencyRetryExhausted::afterAttempts(
                MutationName::ROLE_ASSIGNED,
                3,
                $this->failure(),
            ),
            ConcurrencyRetryExhausted::insideCallerTransaction(
                MutationName::ROLE_ASSIGNED,
                $this->failure(),
            ),
        ] as $exception) {
            $this->assertNull($exception->getPrevious());
        }
    }

    public function test_the_classified_failure_populates_every_diagnostic(): void
    {
        $exception = ConcurrencyRetryExhausted::afterAttempts(
            MutationName::ROLE_REVOKED,
            2,
            ClassifiedConcurrencyFailure::classified(
                'pgsql',
                'serialization_failure',
                '40001',
                null,
            ),
        );

        $this->assertSame('pgsql', $exception->driver);
        $this->assertSame('serialization_failure', $exception->category);
        $this->assertSame('40001', $exception->sqlState);
        $this->assertNull($exception->vendorCode);
    }

    public function test_the_last_classified_value_wins(): void
    {
        // The orchestrator keeps only the latest classification, so whichever
        // value it hands over is the one reported.
        $exception = ConcurrencyRetryExhausted::afterAttempts(
            MutationName::ROLE_ASSIGNED,
            3,
            ClassifiedConcurrencyFailure::classified(
                'sqlsrv',
                'deadlock',
                '40001',
                1205,
            ),
        );

        $this->assertSame('sqlsrv', $exception->driver);
        $this->assertSame('deadlock', $exception->category);
        $this->assertSame(1205, $exception->vendorCode);
    }

    public function test_only_coordinated_assignment_operations_are_accepted(): void
    {
        foreach (MutationName::assignments() as $operation) {
            $this->assertSame(
                $operation,
                ConcurrencyRetryExhausted::afterAttempts(
                    $operation,
                    1,
                    $this->failure(),
                )->operation,
            );
        }

        foreach ([
            'role.created',
            'permission.deactivated',
            'role.renamed',
            '',
        ] as $operation) {
            try {
                ConcurrencyRetryExhausted::afterAttempts(
                    $operation,
                    1,
                    $this->failure(),
                );

                $this->fail("[$operation] should be rejected.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString(
                    'coordinated assignment mutation',
                    $exception->getMessage(),
                );
            }

            try {
                ConcurrencyRetryExhausted::insideCallerTransaction(
                    $operation,
                    $this->failure(),
                );

                $this->fail("[$operation] should be rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_an_attempt_count_below_one_is_rejected(): void
    {
        foreach ([0, -1, -100] as $attempts) {
            try {
                ConcurrencyRetryExhausted::afterAttempts(
                    MutationName::ROLE_ASSIGNED,
                    $attempts,
                    $this->failure(),
                );

                $this->fail("[$attempts] attempts should be rejected.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString(
                    'fewer than one concurrency attempt',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_no_planted_secret_reaches_the_message_or_properties(): void
    {
        $exception = ConcurrencyRetryExhausted::afterAttempts(
            MutationName::ROLE_ASSIGNED,
            3,
            $this->failure(),
        );
        $serialized = json_encode(
            [$exception->getMessage(), $this->properties($exception)],
            JSON_THROW_ON_ERROR,
        );

        foreach (self::SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
    }

    public function test_no_query_exception_is_retained_anywhere(): void
    {
        $exception = ConcurrencyRetryExhausted::afterAttempts(
            MutationName::ROLE_ASSIGNED,
            3,
            $this->failure(),
        );

        foreach ($this->properties($exception) as $value) {
            $this->assertTrue(
                $value === null || is_scalar($value),
                'Only nullable scalars may be exposed.',
            );
        }

        $this->assertNull($exception->getPrevious());
    }

    public function test_the_public_surface_is_final_and_fixed(): void
    {
        $reflection = new ReflectionClass(ConcurrencyRetryExhausted::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue(
            $reflection->isSubclassOf(RuntimeException::class),
        );
        $this->assertTrue(
            $reflection->getConstructor()?->isPrivate() ?? false,
        );
        $this->assertSame(
            [
                'operation',
                'attempts',
                'callerOwned',
                'driver',
                'category',
                'sqlState',
                'vendorCode',
            ],
            array_map(
                static fn (ReflectionProperty $property): string => $property
                    ->getName(),
                $reflection->getProperties(ReflectionProperty::IS_PUBLIC),
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function properties(ConcurrencyRetryExhausted $exception): array
    {
        return [
            'operation' => $exception->operation,
            'attempts' => $exception->attempts,
            'callerOwned' => $exception->callerOwned,
            'driver' => $exception->driver,
            'category' => $exception->category,
            'sqlState' => $exception->sqlState,
            'vendorCode' => $exception->vendorCode,
        ];
    }

    private function failure(): ClassifiedConcurrencyFailure
    {
        return ClassifiedConcurrencyFailure::classified(
            'mysql',
            'coordination_race',
            '23000',
            1062,
        );
    }
}

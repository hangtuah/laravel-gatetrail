<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Concurrency;

use HangTuah\GateTrail\Concurrency\ClassifiedConcurrencyFailure;
use HangTuah\GateTrail\Concurrency\CoordinationInsertRace;
use Illuminate\Database\QueryException;
use InvalidArgumentException;
use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;
use Throwable;

/**
 * The marker's contract, exercised directly through its smallest safe internal
 * factory.
 *
 * Constructing it here is the only place in the package where it is created at
 * all. Proof that no production path creates one lives in
 * {@see RetryRemainsUnactivatedTest}.
 */
final class CoordinationInsertRaceTest extends TestCase
{
    public function test_it_is_an_internal_final_control_flow_exception(): void
    {
        $reflection = new ReflectionClass(CoordinationInsertRace::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue(
            $reflection->isSubclassOf(RuntimeException::class),
        );
        $this->assertStringContainsString(
            '@internal',
            (string) $reflection->getDocComment(),
        );
        $this->assertTrue(
            $reflection->getConstructor()?->isPrivate() ?? false,
            'Only the named factory may create the marker.',
        );
        $this->assertSame(
            'HangTuah\GateTrail\Concurrency',
            $reflection->getNamespaceName(),
            'It is not an Exceptions-namespace public domain exception.',
        );
    }

    public function test_it_carries_exactly_one_classified_failure(): void
    {
        $failure = $this->raceFailure();
        $marker = CoordinationInsertRace::confirmed($failure);

        $this->assertSame($failure, $marker->failure);

        $declared = [];

        foreach (
            (new ReflectionClass(CoordinationInsertRace::class))->getProperties() as $property
        ) {
            if ($property->getDeclaringClass()->getName() === CoordinationInsertRace::class) {
                $declared[] = $property->getName();
            }
        }

        $this->assertSame(['failure'], $declared);
    }

    public function test_only_a_coordination_race_can_be_marked(): void
    {
        foreach (['deadlock', 'serialization_failure'] as $category) {
            try {
                CoordinationInsertRace::confirmed(
                    ClassifiedConcurrencyFailure::classified(
                        'mysql',
                        $category,
                        '40001',
                        1213,
                    ),
                );

                $this->fail("A [$category] failure must not be markable.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString(
                    'confirmed coordination race',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_every_supported_driver_can_be_marked(): void
    {
        foreach (['mysql', 'pgsql', 'sqlsrv', 'oracle', 'sqlite'] as $driver) {
            $marker = CoordinationInsertRace::confirmed(
                ClassifiedConcurrencyFailure::classified(
                    $driver,
                    'coordination_race',
                    '23000',
                    1062,
                ),
            );

            $this->assertSame($driver, $marker->failure->driver);
            $this->assertSame(
                'coordination_race',
                $marker->failure->category,
            );
        }
    }

    public function test_the_previous_chain_is_always_empty(): void
    {
        $marker = CoordinationInsertRace::confirmed($this->raceFailure());

        $this->assertNull($marker->getPrevious());
    }

    public function test_no_factory_or_constructor_accepts_a_throwable(): void
    {
        $reflection = new ReflectionClass(CoordinationInsertRace::class);

        foreach ($reflection->getMethods() as $method) {
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();

                if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }

                $this->assertNotSame(
                    Throwable::class,
                    $type->getName(),
                );
                $this->assertFalse(
                    is_a($type->getName(), Throwable::class, true),
                    sprintf(
                        'Method [%s] must not accept a Throwable.',
                        $method->getName(),
                    ),
                );
            }
        }

        $this->assertFalse(
            $reflection->hasMethod('withQueryException'),
        );
    }

    public function test_the_message_is_stable_and_safe(): void
    {
        $drivers = ['mysql', 'pgsql', 'sqlsrv', 'oracle', 'sqlite'];
        $messages = [];

        foreach ($drivers as $driver) {
            $messages[] = CoordinationInsertRace::confirmed(
                ClassifiedConcurrencyFailure::classified(
                    $driver,
                    'coordination_race',
                    '23505',
                    7,
                ),
            )->getMessage();
        }

        $this->assertSame(
            ['GateTrail lost an assignment coordination race.'],
            array_values(array_unique($messages)),
        );
    }

    public function test_nothing_from_a_driver_exception_can_reach_it(): void
    {
        // A real driver exception is classified and then dropped; the marker is
        // built from the classified value alone.
        $previous = new PDOException(
            'ORA-00001: unique constraint (ACL.ACL_ASGN_LOCKS_PK) violated',
        );
        $previous->errorInfo = [
            'HY000',
            1,
            'ORA-00001: unique constraint (ACL.ACL_ASGN_LOCKS_PK) violated',
        ];
        $driverException = new QueryException(
            'testing',
            'insert into "acl_assignment_locks" values (?, ?)',
            ['role', 'hunter2'],
            $previous,
        );

        $marker = CoordinationInsertRace::confirmed(
            ClassifiedConcurrencyFailure::classified(
                'oracle',
                'coordination_race',
                'HY000',
                1,
            ),
        );

        $graph = json_encode(
            [
                'message' => $marker->getMessage(),
                'previous' => $marker->getPrevious(),
                'failure' => get_object_vars($marker->failure),
            ],
            JSON_THROW_ON_ERROR,
        );

        foreach ([
            'ORA-00001',
            'ACL_ASGN_LOCKS_PK',
            'acl_assignment_locks',
            'insert into',
            'hunter2',
            'role',
        ] as $leak) {
            $this->assertStringNotContainsString($leak, $graph);
        }

        // The driver exception is untouched and simply falls out of scope.
        $this->assertNotSame($driverException, $marker->getPrevious());
    }

    private function raceFailure(): ClassifiedConcurrencyFailure
    {
        return ClassifiedConcurrencyFailure::classified(
            'pgsql',
            'coordination_race',
            '23505',
            7,
        );
    }
}

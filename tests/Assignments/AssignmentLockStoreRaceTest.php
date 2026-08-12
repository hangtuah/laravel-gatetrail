<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Assignments;

use Carbon\CarbonImmutable;
use HangTuah\GateTrail\Assignments\AssignmentLockStore;
use HangTuah\GateTrail\Assignments\AssignmentScope;
use HangTuah\GateTrail\Concurrency\CoordinationInsertRace;
use HangTuah\GateTrail\Exceptions\AssignmentScopeCollision;
use HangTuah\GateTrail\Tests\Fixtures\CoordinationRacePlanter;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use PDOException;
use ReflectionMethod;

/**
 * The coordination `INSERT` is the one statement in GateTrail that can lose a
 * race, and this file is the evidence that the catch around it is exactly that
 * narrow.
 */
final class AssignmentLockStoreRaceTest extends TestCase
{
    private const TABLE = 'acl_assignment_locks';

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);
    }

    public function test_a_real_lost_insert_race_becomes_the_marker(): void
    {
        $scope = AssignmentScope::forRole('user', 42, 7);
        $planter = $this->plant($scope);

        try {
            $this->acquire($scope);

            $this->fail('The lost race should raise the marker.');
        } catch (CoordinationInsertRace $race) {
            $this->assertSame(1, $planter->planted());
            $this->assertSame('coordination_race', $race->failure->category);
            $this->assertSame('sqlite', $race->failure->driver);
            $this->assertSame('23000', $race->failure->sqlState);
            $this->assertSame(19, $race->failure->vendorCode);
        }
    }

    public function test_the_marker_retains_nothing_from_the_driver_exception(): void
    {
        $scope = AssignmentScope::forRole('user', 42, 7);
        $this->plant($scope);

        try {
            $this->acquire($scope);

            $this->fail('The lost race should raise the marker.');
        } catch (CoordinationInsertRace $race) {
            $this->assertNull(
                $race->getPrevious(),
                'The driver exception must never be chained.',
            );
            $this->assertSame(
                'GateTrail lost an assignment coordination race.',
                $race->getMessage(),
            );

            $graph = json_encode(
                [
                    'message' => $race->getMessage(),
                    'previous' => $race->getPrevious(),
                    'failure' => get_object_vars($race->failure),
                ],
                JSON_THROW_ON_ERROR,
            );

            foreach ([
                'UNIQUE constraint failed',
                self::TABLE,
                'assignment_kind',
                'scope_hash',
                'insert into',
                $scope->key(),
                $scope->hash(),
            ] as $leak) {
                $this->assertStringNotContainsString($leak, $graph);
            }
        }
    }

    public function test_an_unconfirmed_insert_failure_propagates_as_the_same_object(): void
    {
        $scope = AssignmentScope::forRole('user', 42, 7);
        // A duplicate key on a different constraint entirely: right statement,
        // wrong identity, so it is not this race.
        $planted = $this->throwOnStatement(
            'insert into',
            $this->queryException(
                '23000',
                19,
                'UNIQUE constraint failed: acl_roles.name, acl_roles.guard_name',
            ),
        );

        try {
            $this->acquire($scope);

            $this->fail('The unconfirmed failure should propagate.');
        } catch (QueryException $thrown) {
            $this->assertSame(
                $planted,
                $thrown,
                'The original exception object must propagate unchanged.',
            );
        }
    }

    public function test_other_unconfirmed_insert_failures_propagate(): void
    {
        $unconfirmed = [
            'another table' => ['23000', 19, 'UNIQUE constraint failed: '
                .'other_locks.assignment_kind, other_locks.scope_hash'],
            'not null' => ['23000', 19, 'NOT NULL constraint failed: '
                .self::TABLE.'.scope_hash'],
            'missing table' => ['HY000', 1, 'no such table: '.self::TABLE],
            'foreign key' => ['23000', 787, 'FOREIGN KEY constraint failed'],
            'wrong vendor code' => ['23000', 1555, 'UNIQUE constraint failed: '
                .self::TABLE.'.assignment_kind, '.self::TABLE.'.scope_hash'],
            'arbitrary failure' => ['HY000', 5, 'database is locked'],
        ];

        foreach ($unconfirmed as $label => [$sqlState, $code, $text]) {
            $this->refreshApplication();
            $this->artisan('migrate', ['--database' => 'testing'])
                ->assertExitCode(0);

            $scope = AssignmentScope::forRole('user', 42, 7);
            $planted = $this->throwOnStatement(
                'insert into',
                $this->queryException($sqlState, $code, $text),
            );

            try {
                $this->acquire($scope);

                $this->fail("[$label] should propagate unchanged.");
            } catch (QueryException $thrown) {
                $this->assertSame($planted, $thrown, $label);
            }
        }
    }

    public function test_the_initial_select_is_never_classified_as_a_race(): void
    {
        // The diagnostics would classify perfectly if they came from the
        // insert. They do not, so positional scoping must reject them.
        $planted = $this->throwOnStatement(
            'select',
            $this->queryException(
                '23000',
                19,
                'UNIQUE constraint failed: '.self::TABLE.'.assignment_kind, '
                    .self::TABLE.'.scope_hash',
            ),
        );

        try {
            $this->acquire(AssignmentScope::forRole('user', 42, 7));

            $this->fail('The select failure should propagate.');
        } catch (QueryException $thrown) {
            $this->assertSame($planted, $thrown);
        }
    }

    public function test_the_re_read_is_never_classified_as_a_race(): void
    {
        // Fail only the second select, so the first read and the insert both
        // succeed and the failure lands on the re-read.
        $planted = $this->queryException(
            '23000',
            19,
            'UNIQUE constraint failed: '.self::TABLE.'.assignment_kind, '
                .self::TABLE.'.scope_hash',
        );
        $selects = 0;
        $this->connection()->beforeExecuting(
            static function (string $query) use (&$selects, $planted): void {
                if (! str_starts_with($query, 'select')) {
                    return;
                }

                $selects++;

                if ($selects === 2) {
                    throw $planted;
                }
            },
        );

        try {
            $this->acquire(AssignmentScope::forRole('user', 42, 7));

            $this->fail('The re-read failure should propagate.');
        } catch (QueryException $thrown) {
            $this->assertSame($planted, $thrown);
            $this->assertSame(2, $selects, 'The insert must have succeeded.');
        }
    }

    public function test_a_scope_collision_still_fails_closed_unchanged(): void
    {
        $scope = AssignmentScope::forRolePermission(7, 9);

        $this->app['db']->table(self::TABLE)->insert([
            'assignment_kind' => $scope->kind()->value,
            'scope_hash' => $scope->hash(),
            'scope_key' => 'corrupted-key',
            'created_at' => '2026-08-12 12:00:00',
            'updated_at' => '2026-08-12 12:00:00',
        ]);

        $this->expectException(AssignmentScopeCollision::class);
        $this->expectExceptionMessage('scope collision detected');

        $this->acquire($scope);
    }

    public function test_a_normal_acquisition_is_unaffected(): void
    {
        $scope = AssignmentScope::forRole('user', 42, 7);

        $this->acquire($scope);
        $this->acquire($scope);

        $this->assertDatabaseCount(self::TABLE, 1);
        $this->assertDatabaseHas(self::TABLE, [
            'assignment_kind' => 'role',
            'scope_hash' => $scope->hash(),
            'scope_key' => $scope->key(),
        ]);
    }

    public function test_acquire_itself_contains_no_catch(): void
    {
        $source = $this->methodSource('acquire');

        $this->assertStringNotContainsString('try {', $source);
        $this->assertStringNotContainsString('catch', $source);

        // Exactly one catch exists in the whole class, and it guards the
        // insert statement alone.
        $class = (string) file_get_contents(
            __DIR__.'/../../src/Assignments/AssignmentLockStore.php',
        );

        $this->assertSame(
            1,
            substr_count($class, 'catch (QueryException'),
        );
        $this->assertStringNotContainsString('catch (Throwable', $class);

        $insert = $this->methodSource('insert');

        $this->assertStringContainsString('catch (QueryException', $insert);
        $this->assertStringContainsString(
            'CoordinationInsertRace::confirmed',
            $insert,
        );
        // The driver exception is dropped before the marker exists.
        $this->assertStringContainsString('unset($exception);', $insert);
    }

    private function methodSource(string $method): string
    {
        $reflection = new ReflectionMethod(AssignmentLockStore::class, $method);
        $file = (string) $reflection->getFileName();
        $lines = (array) file($file);
        $start = (int) $reflection->getStartLine();
        $end = (int) $reflection->getEndLine();

        return implode(
            '',
            array_slice($lines, $start - 1, $end - $start + 1),
        );
    }

    private function plant(AssignmentScope $scope): CoordinationRacePlanter
    {
        return (new CoordinationRacePlanter(
            $this->connection(),
            self::TABLE,
            [
                'assignment_kind' => $scope->kind()->value,
                'scope_hash' => $scope->hash(),
                'scope_key' => $scope->key(),
                'created_at' => '2026-08-12 11:59:00',
                'updated_at' => '2026-08-12 11:59:00',
            ],
        ))->attach();
    }

    private function throwOnStatement(
        string $prefix,
        QueryException $exception,
    ): QueryException {
        $thrown = false;
        $this->connection()->beforeExecuting(
            static function (string $query) use (
                $prefix,
                $exception,
                &$thrown,
            ): void {
                if ($thrown || ! str_starts_with($query, $prefix)) {
                    return;
                }

                $thrown = true;

                throw $exception;
            },
        );

        return $exception;
    }

    private function queryException(
        string $sqlState,
        int $code,
        string $text,
    ): QueryException {
        $previous = new PDOException($text);
        $previous->errorInfo = [$sqlState, $code, $text];

        return new QueryException(
            'testing',
            'insert into "'.self::TABLE.'" ("assignment_kind") values (?)',
            ['role'],
            $previous,
        );
    }

    private function acquire(AssignmentScope $scope): void
    {
        $store = app(AssignmentLockStore::class);

        $this->connection()->transaction(
            static fn () => $store->acquire(
                $scope,
                CarbonImmutable::parse('2026-08-12 12:00:00'),
            ),
        );
    }

    private function connection(): Connection
    {
        $connection = $this->app['db']->connection('testing');

        $this->assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}

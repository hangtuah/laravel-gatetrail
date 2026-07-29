<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Database;

use HangTuah\GateTrail\Database\UniqueConstraintViolationClassifier;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use PDOException;

final class UniqueConstraintViolationClassifierTest extends TestCase
{
    public function test_real_sqlite_duplicate_insert_is_classified(): void
    {
        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);
        $row = [
            'name' => 'reviewer',
            'guard_name' => 'web',
            'description' => null,
            'created_at' => '2026-07-29 12:00:00',
            'updated_at' => '2026-07-29 12:00:00',
            'deleted_at' => null,
        ];
        $this->app['db']->table('acl_roles')->insert($row);

        try {
            $this->app['db']->table('acl_roles')->insert($row);

            $this->fail('The duplicate insert should fail.');
        } catch (QueryException $exception) {
            $this->assertTrue(
                app(UniqueConstraintViolationClassifier::class)
                    ->isUniqueViolation($exception),
            );
        }
    }

    public function test_laravel_unique_constraint_exception_is_classified(): void
    {
        $this->assertTrue(
            (new UniqueConstraintViolationClassifier)->isUniqueViolation(
                new UniqueConstraintViolationException(
                    'testing',
                    'insert into "acl_roles" ...',
                    [],
                    new PDOException('duplicate'),
                ),
            ),
        );
    }

    public function test_vendor_unique_violation_codes_are_classified(): void
    {
        $classifier = new UniqueConstraintViolationClassifier;
        $unique = [
            'postgresql' => $this->exception(
                '23505',
                7,
                'duplicate key value violates unique constraint "acl_role_name_guard_uq"',
            ),
            'mysql' => $this->exception(
                '23000',
                1062,
                "Duplicate entry 'reviewer-web' for key 'acl_role_name_guard_uq'",
            ),
            'sqlserver-2601' => $this->exception(
                '23000',
                2601,
                'Cannot insert duplicate key row in object',
            ),
            'sqlserver-2627' => $this->exception(
                '23000',
                2627,
                'Violation of UNIQUE KEY constraint',
            ),
            'sqlite' => $this->exception(
                '23000',
                19,
                'UNIQUE constraint failed: acl_roles.name, acl_roles.guard_name',
            ),
            'oracle' => $this->exception(
                'HY000',
                1,
                'ORA-00001: unique constraint (ACL.ACL_ROLE_NAME_GUARD_UQ) violated',
            ),
        ];

        foreach ($unique as $engine => $exception) {
            $this->assertTrue(
                $classifier->isUniqueViolation($exception),
                sprintf('Engine [%s] should classify as unique.', $engine),
            );
        }
    }

    public function test_unrelated_database_failures_are_not_classified(): void
    {
        $classifier = new UniqueConstraintViolationClassifier;
        $unrelated = [
            'mysql-foreign-key' => $this->exception(
                '23000',
                1452,
                'Cannot add or update a child row: a foreign key constraint fails',
            ),
            'sqlite-not-null' => $this->exception(
                '23000',
                19,
                'NOT NULL constraint failed: acl_roles.name',
            ),
            'postgresql-deadlock' => $this->exception(
                '40P01',
                7,
                'deadlock detected',
            ),
            'missing-table' => $this->exception(
                '42S02',
                1146,
                "Table 'acl.missing_roles' doesn't exist",
            ),
            'oracle-other' => $this->exception(
                'HY000',
                942,
                'ORA-00942: table or view does not exist',
            ),
        ];

        foreach ($unrelated as $failure => $exception) {
            $this->assertFalse(
                $classifier->isUniqueViolation($exception),
                sprintf('Failure [%s] should not classify as unique.', $failure),
            );
        }
    }

    private function exception(
        string $sqlState,
        int $driverCode,
        string $message,
    ): QueryException {
        $previous = new PDOException($message);
        $previous->errorInfo = [$sqlState, $driverCode, $message];

        return new QueryException(
            'testing',
            'insert into "acl_roles" ...',
            [],
            $previous,
        );
    }
}

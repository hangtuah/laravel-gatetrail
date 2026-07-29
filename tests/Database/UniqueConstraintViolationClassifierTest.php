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
    private const CONSTRAINT = 'acl_role_name_guard_uq';

    private const TABLE = 'acl_roles';

    private const COLUMNS = ['name', 'guard_name'];

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
            $this->assertTrue($this->classify($exception));
        }
    }

    public function test_namespace_violation_is_classified_for_every_driver_shape(): void
    {
        $shapes = [
            'postgresql' => $this->exception(
                '23505',
                7,
                'duplicate key value violates unique constraint "acl_role_name_guard_uq"',
            ),
            'mysql-5.7' => $this->exception(
                '23000',
                1062,
                "Duplicate entry 'reviewer-web' for key 'acl_role_name_guard_uq'",
            ),
            'mysql-8-table-qualified' => $this->exception(
                '23000',
                1062,
                "Duplicate entry 'reviewer-web' for key 'acl_roles.acl_role_name_guard_uq'",
            ),
            'sqlserver-2601' => $this->exception(
                '23000',
                2601,
                "Cannot insert duplicate key row in object 'dbo.acl_roles' with unique index 'acl_role_name_guard_uq'",
            ),
            'sqlserver-2627' => $this->exception(
                '23000',
                2627,
                "Violation of UNIQUE KEY constraint 'acl_role_name_guard_uq'. Cannot insert duplicate key in object 'dbo.acl_roles'.",
            ),
            'oracle-schema-qualified' => $this->exception(
                'HY000',
                1,
                'ORA-00001: unique constraint (ACL.ACL_ROLE_NAME_GUARD_UQ) violated',
            ),
            'sqlite-columns' => $this->exception(
                '23000',
                19,
                'UNIQUE constraint failed: acl_roles.name, acl_roles.guard_name',
            ),
        ];

        foreach ($shapes as $driver => $exception) {
            $this->assertTrue(
                $this->classify($exception),
                sprintf('Driver shape [%s] should classify.', $driver),
            );
        }
    }

    public function test_primary_key_violations_are_not_translated(): void
    {
        $shapes = [
            'postgresql' => $this->exception(
                '23505',
                7,
                'duplicate key value violates unique constraint "acl_roles_pk"',
            ),
            'mysql' => $this->exception(
                '23000',
                1062,
                "Duplicate entry '1' for key 'PRIMARY'",
            ),
            'mysql-8-table-qualified' => $this->exception(
                '23000',
                1062,
                "Duplicate entry '1' for key 'acl_roles.PRIMARY'",
            ),
            'sqlserver' => $this->exception(
                '23000',
                2627,
                "Violation of PRIMARY KEY constraint 'acl_roles_pk'. Cannot insert duplicate key in object 'dbo.acl_roles'.",
            ),
            'oracle' => $this->exception(
                'HY000',
                1,
                'ORA-00001: unique constraint (ACL.ACL_ROLES_PK) violated',
            ),
            'sqlite' => $this->exception(
                '23000',
                19,
                'UNIQUE constraint failed: acl_roles.id',
            ),
        ];

        foreach ($shapes as $driver => $exception) {
            $this->assertFalse(
                $this->classify($exception),
                sprintf(
                    'Driver [%s] primary-key violation should not classify.',
                    $driver,
                ),
            );
        }
    }

    public function test_unrelated_unique_constraints_are_not_translated(): void
    {
        $shapes = [
            'postgresql-custom' => $this->exception(
                '23505',
                7,
                'duplicate key value violates unique constraint "acl_roles_custom_uq"',
            ),
            'mysql-custom' => $this->exception(
                '23000',
                1062,
                "Duplicate entry 'x' for key 'acl_roles_custom_uq'",
            ),
            'oracle-other-table' => $this->exception(
                'HY000',
                1,
                'ORA-00001: unique constraint (ACL.ACL_PERM_NAME_GUARD_UQ) violated',
            ),
            'similar-prefix' => $this->exception(
                '23505',
                7,
                'duplicate key value violates unique constraint "acl_role_name_guard_uq_v2"',
            ),
            'sqlite-single-column' => $this->exception(
                '23000',
                19,
                'UNIQUE constraint failed: acl_roles.name',
            ),
            'sqlite-other-table' => $this->exception(
                '23000',
                19,
                'UNIQUE constraint failed: other_roles.name, other_roles.guard_name',
            ),
            'sqlite-extra-column' => $this->exception(
                '23000',
                19,
                'UNIQUE constraint failed: acl_roles.name, acl_roles.guard_name, acl_roles.id',
            ),
        ];

        foreach ($shapes as $failure => $exception) {
            $this->assertFalse(
                $this->classify($exception),
                sprintf('Failure [%s] should not classify.', $failure),
            );
        }
    }

    public function test_laravel_unique_exception_requires_constraint_identity(): void
    {
        $unmatched = new UniqueConstraintViolationException(
            'testing',
            'insert into "acl_roles" ...',
            [],
            new PDOException(
                "Duplicate entry 'x' for key 'acl_roles_custom_uq'",
            ),
        );
        $matched = new UniqueConstraintViolationException(
            'testing',
            'insert into "acl_roles" ...',
            [],
            new PDOException(
                "Duplicate entry 'reviewer-web' for key 'acl_role_name_guard_uq'",
            ),
        );

        $this->assertFalse($this->classify($unmatched));
        $this->assertTrue($this->classify($matched));
    }

    public function test_non_unique_database_failures_are_not_classified(): void
    {
        $shapes = [
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

        foreach ($shapes as $failure => $exception) {
            $this->assertFalse(
                $this->classify($exception),
                sprintf('Failure [%s] should not classify.', $failure),
            );
        }
    }

    private function classify(QueryException $exception): bool
    {
        return (new UniqueConstraintViolationClassifier)->matchesConstraint(
            $exception,
            self::CONSTRAINT,
            self::TABLE,
            self::COLUMNS,
        );
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

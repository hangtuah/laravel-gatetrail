<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Concurrency;

use HangTuah\GateTrail\Concurrency\ClassifiedConcurrencyFailure;
use HangTuah\GateTrail\Concurrency\TransientFailureClassifier;
use HangTuah\GateTrail\Database\Schema\SchemaAdapterFactory;
use HangTuah\GateTrail\Exceptions\UnsupportedDatabaseDriver;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Golden-vector coverage for deadlock and serialization classification.
 *
 * Every vector models the PDO `errorInfo` triple its engine produces, not a
 * Laravel-formatted exception message, because the triple is what
 * classification actually reads. These are recorded driver *shapes*: they prove
 * the matrix is implemented as ADR 0011 accepted it. They are not live
 * portability evidence, and no claim of live portability rests on them.
 */
final class TransientFailureClassifierTest extends TestCase
{
    public function test_supported_driver_aliases_canonicalize(): void
    {
        $aliases = [
            'mysql' => 'mysql',
            'mariadb' => 'mysql',
            'oracle' => 'oracle',
            'oci8' => 'oracle',
            'pgsql' => 'pgsql',
            'sqlsrv' => 'sqlsrv',
        ];

        foreach ($aliases as $alias => $canonical) {
            $failure = $this->classify(
                $this->vectorFor($canonical),
                $alias,
            );

            $this->assertInstanceOf(
                ClassifiedConcurrencyFailure::class,
                $failure,
                sprintf('Alias [%s] should classify.', $alias),
            );
            $this->assertSame($canonical, $failure->driver);
        }
    }

    public function test_the_sqlite_alias_canonicalizes_but_classifies_nothing(): void
    {
        // SQLite has no accepted deadlock or serialization shape in this
        // increment, so the alias must resolve without inventing a category.
        $this->assertNull(
            $this->classify(
                $this->exception('40001', 1213, 'Deadlock found'),
                'sqlite',
            ),
        );
    }

    /**
     * @return array<string, array{string, string, int|string|null, string, string}>
     */
    public static function acceptedVectors(): array
    {
        return [
            'postgresql serialization failure' => [
                'pgsql', '40001', 7,
                'could not serialize access due to concurrent update',
                'serialization_failure',
            ],
            'postgresql deadlock detected' => [
                'pgsql', '40P01', 7,
                'deadlock detected',
                'deadlock',
            ],
            'mysql deadlock victim' => [
                'mysql', '40001', 1213,
                'Deadlock found when trying to get lock; try restarting transaction',
                'deadlock',
            ],
            'mariadb deadlock victim' => [
                'mariadb', '40001', 1213,
                'Deadlock found when trying to get lock; try restarting transaction',
                'deadlock',
            ],
            'sql server deadlock victim' => [
                'sqlsrv', '40001', 1205,
                'Transaction (Process ID 58) was deadlocked on lock resources '
                    .'with another process and has been chosen as the deadlock victim.',
                'deadlock',
            ],
            'oracle deadlock detected' => [
                'oracle', '61000', 60,
                'ORA-00060: deadlock detected while waiting for resource',
                'deadlock',
            ],
            'oci8 alias deadlock detected' => [
                'oci8', '61000', 60,
                'ORA-00060: deadlock detected while waiting for resource',
                'deadlock',
            ],
            'oracle cannot serialize access' => [
                'oracle', '72000', 8177,
                'ORA-08177: can\'t serialize access for this transaction',
                'serialization_failure',
            ],
        ];
    }

    public function test_every_accepted_transient_failure_classifies(): void
    {
        foreach (self::acceptedVectors() as $label => $vector) {
            [$driver, $sqlState, $vendorCode, $text, $category] = $vector;

            $failure = $this->classify(
                $this->exception($sqlState, $vendorCode, $text),
                $driver,
            );

            $this->assertInstanceOf(
                ClassifiedConcurrencyFailure::class,
                $failure,
                sprintf('Vector [%s] should classify.', $label),
            );
            $this->assertSame($category, $failure->category, $label);
            $this->assertSame($sqlState, $failure->sqlState, $label);
            $this->assertSame($vendorCode, $failure->vendorCode, $label);
        }
    }

    public function test_a_generic_40001_is_not_enough_for_every_driver(): void
    {
        // MySQL and SQL Server both use 40001, but only with their own vendor
        // code. Swapping them, or dropping the code, must not classify.
        $wrong = [
            'mysql with the sql server code' => ['mysql', '40001', 1205],
            'sql server with the mysql code' => ['sqlsrv', '40001', 1213],
            'mysql with no vendor code' => ['mysql', '40001', null],
            'sql server with no vendor code' => ['sqlsrv', '40001', null],
            'mysql with an unrelated code' => ['mysql', '40001', 1062],
            'oracle with the postgresql sqlstate' => ['oracle', '40001', 60],
            'oracle deadlock code on the wrong sqlstate' => ['oracle', '72000', 60],
            'oracle serialization code on the wrong sqlstate' => ['oracle', '61000', 8177],
            'sqlite deadlock shape' => ['sqlite', '40001', 1213],
        ];

        foreach ($wrong as $label => [$driver, $sqlState, $vendorCode]) {
            $this->assertNull(
                $this->classify(
                    $this->exception($sqlState, $vendorCode, 'deadlock'),
                    $driver,
                ),
                sprintf('[%s] should not classify.', $label),
            );
        }
    }

    public function test_postgresql_ignores_the_vendor_code_as_identity(): void
    {
        // libpq's code carries no identity, so PostgreSQL classifies on
        // SQLSTATE alone — but whatever it did report is still reported back.
        foreach ([7, 0, null, 1213] as $vendorCode) {
            $failure = $this->classify(
                $this->exception('40P01', $vendorCode, 'deadlock detected'),
                'pgsql',
            );

            $this->assertInstanceOf(
                ClassifiedConcurrencyFailure::class,
                $failure,
            );
            $this->assertSame('deadlock', $failure->category);
            $this->assertSame($vendorCode, $failure->vendorCode);
        }
    }

    /**
     * @return array<string, array{string, string, int|string|null, string}>
     */
    public static function rejectedVectors(): array
    {
        return [
            'mysql lock wait timeout' => [
                'mysql', 'HY000', 1205,
                'Lock wait timeout exceeded; try restarting transaction',
            ],
            'mysql lock wait timeout as 40001' => [
                'mysql', '40001', 1205,
                'Lock wait timeout exceeded; try restarting transaction',
            ],
            'sql server lock request timeout' => [
                'sqlsrv', 'HY000', 1222,
                'Lock request time out period exceeded.',
            ],
            'oracle resource busy' => [
                'oracle', 'HY000', 54,
                'ORA-00054: resource busy and acquire with NOWAIT specified',
            ],
            'sqlite busy' => [
                'sqlite', 'HY000', 5,
                'database is locked',
            ],
            'sqlite locked' => [
                'sqlite', 'HY000', 6,
                'database table is locked',
            ],
            'postgresql unique violation' => [
                'pgsql', '23505', 7,
                'duplicate key value violates unique constraint "acl_asgn_locks_pk"',
            ],
            'mysql duplicate entry' => [
                'mysql', '23000', 1062,
                "Duplicate entry 'role-abc' for key 'acl_assignment_locks.PRIMARY'",
            ],
            'sql server duplicate key' => [
                'sqlsrv', '23000', 2627,
                "Violation of PRIMARY KEY constraint 'acl_asgn_locks_pk'.",
            ],
            'oracle unique constraint' => [
                'oracle', '23000', 1,
                'ORA-00001: unique constraint (ACL.ACL_ASGN_LOCKS_PK) violated',
            ],
            'oracle unique constraint as hy000' => [
                'oracle', 'HY000', 1,
                'ORA-00001: unique constraint (ACL.ACL_ASGN_LOCKS_PK) violated',
            ],
            'sqlite unique violation' => [
                'sqlite', '23000', 19,
                'UNIQUE constraint failed: acl_assignment_locks.assignment_kind, '
                    .'acl_assignment_locks.scope_hash',
            ],
            'postgresql foreign key' => [
                'pgsql', '23503', 7,
                'insert or update on table violates foreign key constraint',
            ],
            'mysql foreign key' => [
                'mysql', '23000', 1452,
                'Cannot add or update a child row: a foreign key constraint fails',
            ],
            'oracle foreign key' => [
                'oracle', '23000', 2291,
                'ORA-02291: integrity constraint violated - parent key not found',
            ],
            'postgresql not null' => [
                'pgsql', '23502', 7,
                'null value in column violates not-null constraint',
            ],
            'sqlite not null' => [
                'sqlite', '23000', 19,
                'NOT NULL constraint failed: acl_assignment_locks.scope_hash',
            ],
            'postgresql check violation' => [
                'pgsql', '23514', 7,
                'new row violates check constraint "acl_ra_period_ck"',
            ],
            'oracle check violation' => [
                'oracle', '23000', 2290,
                'ORA-02290: check constraint violated',
            ],
            'syntax error' => [
                'mysql', '42000', 1064,
                'You have an error in your SQL syntax',
            ],
            'missing table' => [
                'mysql', '42S02', 1146,
                "Table 'acl.acl_assignment_locks' doesn't exist",
            ],
            'oracle missing table' => [
                'oracle', 'HY000', 942,
                'ORA-00942: table or view does not exist',
            ],
            'permission denied' => [
                'pgsql', '42501', 7,
                'permission denied for table acl_assignment_locks',
            ],
            'authentication failure' => [
                'pgsql', '28000', 7,
                'password authentication failed for user',
            ],
            'connectivity failure' => [
                'pgsql', '08006', 7,
                'server closed the connection unexpectedly',
            ],
            'aborted postgresql transaction' => [
                'pgsql', '25P02', 7,
                'current transaction is aborted, commands ignored',
            ],
        ];
    }

    public function test_every_rejected_failure_stays_unclassified(): void
    {
        foreach (self::rejectedVectors() as $label => $vector) {
            [$driver, $sqlState, $vendorCode, $text] = $vector;

            $this->assertNull(
                $this->classify(
                    $this->exception($sqlState, $vendorCode, $text),
                    $driver,
                ),
                sprintf('Vector [%s] must not classify.', $label),
            );
        }
    }

    public function test_deadlock_prose_alone_never_classifies(): void
    {
        // The single most dangerous false positive: Laravel's own transaction
        // retry matches messages, and this classifier deliberately does not.
        $prose = [
            'Deadlock found when trying to get lock; try restarting transaction',
            'deadlock detected',
            'was deadlocked on lock resources',
            'could not serialize access due to concurrent update',
            'ORA-00060: deadlock detected while waiting for resource',
        ];

        foreach ($prose as $message) {
            foreach (['mysql', 'pgsql', 'sqlsrv', 'oracle', 'sqlite'] as $driver) {
                $this->assertNull(
                    $this->classify(
                        $this->exception('HY000', 9999, $message),
                        $driver,
                    ),
                    sprintf('[%s] prose must not classify.', $driver),
                );
            }
        }
    }

    public function test_localized_prose_does_not_change_the_answer(): void
    {
        $localized = [
            'Deadlock found when trying to get lock; try restarting transaction',
            'Kebuntuan ditemui semasa cuba mendapatkan kunci',
            'Interbloqueo encontrado al intentar obtener el bloqueo',
            '',
        ];

        foreach ($localized as $message) {
            $failure = $this->classify(
                $this->exception('40001', 1213, $message),
                'mysql',
            );

            $this->assertInstanceOf(
                ClassifiedConcurrencyFailure::class,
                $failure,
            );
            $this->assertSame('deadlock', $failure->category);
        }
    }

    public function test_a_lowercase_driver_sqlstate_is_not_classified(): void
    {
        // Nothing is uppercased on the way in, so a noncanonical SQLSTATE is
        // simply not a value the matrix matches.
        foreach (['40p01', '40001 ', ' 40001'] as $sqlState) {
            $this->assertNull(
                $this->classify(
                    $this->exception($sqlState, 7, 'deadlock detected'),
                    'pgsql',
                ),
            );
        }
    }

    public function test_a_canonical_numeric_vendor_code_string_is_accepted(): void
    {
        $failure = $this->classify(
            $this->exception('40001', '1213', 'Deadlock found'),
            'mysql',
        );

        $this->assertInstanceOf(ClassifiedConcurrencyFailure::class, $failure);
        $this->assertSame(1213, $failure->vendorCode);
    }

    public function test_malformed_vendor_codes_are_rejected(): void
    {
        $malformed = [
            'signed positive' => '+1213',
            'signed negative' => '-1213',
            'leading space' => ' 1213',
            'trailing space' => '1213 ',
            'leading zero' => '01213',
            'decimal' => '1213.0',
            'exponent' => '1.213e3',
            'hex' => '0x4BD',
            'overflow' => '99999999999999999999999',
            'prose' => '1213 deadlock',
            'empty' => '',
            'float' => 1213.0,
            'true' => true,
            'array' => [1213],
        ];

        foreach ($malformed as $label => $vendorCode) {
            $this->assertNull(
                $this->classify(
                    $this->exception('40001', $vendorCode, 'Deadlock found'),
                    'mysql',
                ),
                sprintf('A [%s] vendor code must not classify.', $label),
            );
        }
    }

    public function test_a_missing_or_malformed_errorinfo_is_not_classified(): void
    {
        $previous = new PDOException('Deadlock found');
        $previous->errorInfo = null;

        $this->assertNull(
            $this->classify($this->wrap($previous), 'mysql'),
        );

        $empty = new PDOException('Deadlock found');
        $empty->errorInfo = [];

        $this->assertNull($this->classify($this->wrap($empty), 'mysql'));

        $wrongTypes = new PDOException('Deadlock found');
        $wrongTypes->errorInfo = [40001, '1213', null];

        $this->assertNull($this->classify($this->wrap($wrongTypes), 'mysql'));
    }

    public function test_an_arbitrary_query_exception_is_not_classified(): void
    {
        $exception = new QueryException(
            'testing',
            'insert into "acl_assignment_locks" ("scope_key") values (?)',
            ['administrator-scope'],
            new PDOException('Something went wrong'),
        );

        $this->assertNull($this->classify($exception, 'mysql'));
    }

    public function test_an_unsupported_driver_uses_the_existing_contract(): void
    {
        foreach (['mongodb', 'postgres', 'MySQL', 'firebird', ''] as $driver) {
            try {
                $this->classify(
                    $this->exception('40001', 1213, 'Deadlock found'),
                    $driver,
                );

                $this->fail("Driver [$driver] should be rejected.");
            } catch (UnsupportedDatabaseDriver $exception) {
                $this->assertStringContainsString(
                    'does not support',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_the_result_retains_nothing_from_the_exception(): void
    {
        $exception = $this->exception(
            '40001',
            1213,
            'Deadlock found while updating acl_assignment_locks',
        );
        $failure = $this->classify($exception, 'mysql');

        $this->assertInstanceOf(ClassifiedConcurrencyFailure::class, $failure);
        $this->assertSame(
            ['driver', 'category', 'sqlState', 'vendorCode'],
            array_keys(get_object_vars($failure)),
        );

        $graph = json_encode(get_object_vars($failure), JSON_THROW_ON_ERROR);

        foreach ([
            'acl_assignment_locks',
            'Deadlock found',
            'insert into',
            'administrator-scope',
        ] as $leak) {
            $this->assertStringNotContainsString($leak, $graph);
        }
    }

    public function test_the_classifier_holds_no_state_from_a_classification(): void
    {
        $classifier = new TransientFailureClassifier(new SchemaAdapterFactory);
        $properties = static function (object $subject): array {
            $reflection = new ReflectionClass($subject);
            $values = [];

            foreach ($reflection->getProperties() as $property) {
                $values[$property->getName()] = $property->getValue($subject);
            }

            return $values;
        };

        $before = $properties($classifier);

        $classifier->classify(
            $this->exception('40001', 1213, 'Deadlock found'),
            'mysql',
        );

        // The only property is the injected adapter factory: no exception, no
        // message and no diagnostic text is kept between classifications.
        $this->assertSame(['adapters'], array_keys($before));
        $this->assertInstanceOf(
            SchemaAdapterFactory::class,
            $before['adapters'],
        );
        $this->assertEquals($before, $properties($classifier));
    }

    private function classify(
        QueryException $exception,
        string $driver,
    ): ?ClassifiedConcurrencyFailure {
        return (new TransientFailureClassifier(new SchemaAdapterFactory))
            ->classify($exception, $driver);
    }

    private function vectorFor(string $canonicalDriver): QueryException
    {
        return match ($canonicalDriver) {
            'mysql' => $this->exception('40001', 1213, 'Deadlock found'),
            'pgsql' => $this->exception('40P01', 7, 'deadlock detected'),
            'sqlsrv' => $this->exception('40001', 1205, 'deadlock victim'),
            'oracle' => $this->exception('61000', 60, 'ORA-00060: deadlock detected'),
            default => $this->exception('40001', 1213, 'Deadlock found'),
        };
    }

    private function exception(
        string $sqlState,
        mixed $vendorCode,
        string $text,
    ): QueryException {
        $previous = new PDOException($text);
        $previous->errorInfo = [$sqlState, $vendorCode, $text];

        return $this->wrap($previous);
    }

    private function wrap(PDOException $previous): QueryException
    {
        return new QueryException(
            'testing',
            'insert into "acl_assignment_locks" '
                .'("assignment_kind", "scope_hash") values (?, ?)',
            ['role', 'a1b2c3'],
            $previous,
        );
    }
}

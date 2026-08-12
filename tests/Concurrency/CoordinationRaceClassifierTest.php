<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Concurrency;

use HangTuah\GateTrail\Concurrency\ClassifiedConcurrencyFailure;
use HangTuah\GateTrail\Concurrency\CoordinationRaceClassifier;
use HangTuah\GateTrail\Database\Schema\IdentifierValidator;
use HangTuah\GateTrail\Database\Schema\Manifest\SchemaManifest;
use HangTuah\GateTrail\Database\Schema\SchemaAdapterFactory;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Exceptions\UnsupportedDatabaseDriver;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Golden-vector coverage for coordination-race confirmation.
 *
 * Every vector models the PDO `errorInfo` triple its engine produces. These are
 * recorded driver *shapes* and prove the identity rules are implemented as
 * ADR 0011 accepted them, including its owner-approved Oracle SQLSTATE
 * clarification of 2026-08-12. They are **not** live portability evidence: no
 * claim that a real Oracle, SQL Server, MySQL or PostgreSQL server produces a
 * given triple rests on this file. Live evidence is the province of the
 * disposable MySQL and Oracle integration suites.
 *
 * Every case here also assumes the positional precondition the classifier
 * documents: the caller has already narrowed the failure to the one
 * coordination-row `INSERT`. In this increment that is a caller contract, and
 * these tests exercise it as one. It becomes structural in Increment 3, when
 * `AssignmentLockStore` gains the narrow catch and the retry orchestrator lands
 * with it.
 */
final class CoordinationRaceClassifierTest extends TestCase
{
    private const LOCK_TABLE = 'acl_assignment_locks';

    private const CONSTRAINT = 'acl_asgn_locks_pk';

    public function test_supported_driver_aliases_canonicalize(): void
    {
        $aliases = [
            'mysql' => 'mysql',
            'mariadb' => 'mysql',
            'oracle' => 'oracle',
            'oci8' => 'oracle',
            'pgsql' => 'pgsql',
            'sqlsrv' => 'sqlsrv',
            'sqlite' => 'sqlite',
        ];

        foreach ($aliases as $alias => $canonical) {
            $failure = $this->classify($this->vectorFor($canonical), $alias);

            $this->assertInstanceOf(
                ClassifiedConcurrencyFailure::class,
                $failure,
                sprintf('Alias [%s] should classify.', $alias),
            );
            $this->assertSame($canonical, $failure->driver);
            $this->assertSame('coordination_race', $failure->category);
        }
    }

    public function test_an_unsupported_driver_uses_the_existing_contract(): void
    {
        foreach (['mongodb', 'postgres', 'Oracle', ''] as $driver) {
            try {
                $this->classify($this->vectorFor('pgsql'), $driver);

                $this->fail("Driver [$driver] should be rejected.");
            } catch (UnsupportedDatabaseDriver $exception) {
                $this->assertStringContainsString(
                    'does not support',
                    $exception->getMessage(),
                );
            }
        }
    }

    // ---------------------------------------------------------------- pgsql

    public function test_postgresql_confirms_the_exact_logical_constraint(): void
    {
        $accepted = [
            'quoted' => 'duplicate key value violates unique constraint '
                .'"acl_asgn_locks_pk"',
            'unquoted' => 'duplicate key value violates unique constraint '
                .'acl_asgn_locks_pk',
            'schema qualified' => 'duplicate key value violates unique '
                .'constraint "gatetrail.acl_asgn_locks_pk"',
            'upper cased' => 'duplicate key value violates unique constraint '
                .'"ACL_ASGN_LOCKS_PK"',
            'localized prose' => 'doppelter Schlüsselwert verletzt '
                .'Unique-Constraint »acl_asgn_locks_pk«',
            'reworded prose' => 'acl_asgn_locks_pk',
        ];

        foreach ($accepted as $label => $text) {
            $failure = $this->classify(
                $this->exception('23505', 7, $text),
                'pgsql',
            );

            $this->assertInstanceOf(
                ClassifiedConcurrencyFailure::class,
                $failure,
                sprintf('PostgreSQL [%s] should classify.', $label),
            );
            $this->assertSame('23505', $failure->sqlState);
            // The vendor code is reported but is never identity on PostgreSQL.
            $this->assertSame(7, $failure->vendorCode);
        }
    }

    public function test_postgresql_ignores_the_vendor_code_as_identity(): void
    {
        foreach ([7, 0, null] as $vendorCode) {
            $failure = $this->classify(
                $this->exception(
                    '23505',
                    $vendorCode,
                    'duplicate key value violates unique constraint '
                        .'"acl_asgn_locks_pk"',
                ),
                'pgsql',
            );

            $this->assertInstanceOf(
                ClassifiedConcurrencyFailure::class,
                $failure,
            );
            $this->assertSame($vendorCode, $failure->vendorCode);
        }
    }

    public function test_postgresql_rejects_every_other_constraint(): void
    {
        $rejected = [
            'similar suffix' => 'acl_asgn_locks_pk_2',
            'similar prefix' => 'x_acl_asgn_locks_pk',
            'pkey spelling' => 'acl_asgn_locks_pkey',
            'another gatetrail constraint' => 'acl_role_name_guard_uq',
            'another gatetrail primary key' => 'acl_roles_pk',
            'consumer constraint' => 'users_email_unique',
        ];

        foreach ($rejected as $label => $constraint) {
            $this->assertNull(
                $this->classify(
                    $this->exception(
                        '23505',
                        7,
                        sprintf(
                            'duplicate key value violates unique constraint "%s"',
                            $constraint,
                        ),
                    ),
                    'pgsql',
                ),
                sprintf('PostgreSQL [%s] must not classify.', $label),
            );
        }
    }

    public function test_postgresql_requires_its_own_sqlstate(): void
    {
        $text = 'duplicate key value violates unique constraint '
            .'"acl_asgn_locks_pk"';

        foreach (['23000', '23503', '40001', 'HY000', '23504'] as $sqlState) {
            $this->assertNull(
                $this->classify(
                    $this->exception($sqlState, 7, $text),
                    'pgsql',
                ),
                sprintf('PostgreSQL [%s] must not classify.', $sqlState),
            );
        }
    }

    // --------------------------------------------------------------- oracle

    public function test_oracle_confirms_both_accepted_sqlstates(): void
    {
        $text = 'ORA-00001: unique constraint (ACL.ACL_ASGN_LOCKS_PK) violated';

        foreach (['23000', 'HY000'] as $sqlState) {
            $failure = $this->classify(
                $this->exception($sqlState, 1, $text),
                'oracle',
            );

            $this->assertInstanceOf(
                ClassifiedConcurrencyFailure::class,
                $failure,
                sprintf('Oracle [%s] should classify.', $sqlState),
            );
            $this->assertSame($sqlState, $failure->sqlState);
            $this->assertSame(1, $failure->vendorCode);
            $this->assertSame('oracle', $failure->driver);
        }
    }

    public function test_oracle_accepts_qualified_and_quoted_identity_forms(): void
    {
        $accepted = [
            'schema qualified' => 'ORA-00001: unique constraint '
                .'(ACL.ACL_ASGN_LOCKS_PK) violated',
            'unqualified' => 'ORA-00001: unique constraint '
                .'(ACL_ASGN_LOCKS_PK) violated',
            'double quoted' => 'ORA-00001: unique constraint '
                .'("ACL"."ACL_ASGN_LOCKS_PK") violated',
            'lower cased' => 'ORA-00001: unique constraint '
                .'(acl.acl_asgn_locks_pk) violated',
            'localized prose' => 'ORA-00001: kekangan unik '
                .'(ACL.ACL_ASGN_LOCKS_PK) dilanggar',
        ];

        foreach ($accepted as $label => $text) {
            $this->assertInstanceOf(
                ClassifiedConcurrencyFailure::class,
                $this->classify(
                    $this->exception('HY000', 1, $text),
                    'oracle',
                ),
                sprintf('Oracle [%s] should classify.', $label),
            );
        }
    }

    public function test_an_arbitrary_hy000_failure_is_never_a_race(): void
    {
        // HY000 is Oracle's catch-all. Widening the accepted SQLSTATE must not
        // widen what classifies.
        $rejected = [
            'missing table' => [942, 'ORA-00942: table or view does not exist'],
            'resource busy' => [54, 'ORA-00054: resource busy and acquire '
                .'with NOWAIT specified'],
            'deadlock' => [60, 'ORA-00060: deadlock detected while waiting '
                .'for resource'],
            'cannot serialize' => [8177, 'ORA-08177: can\'t serialize access '
                .'for this transaction'],
            'invalid identifier' => [904, 'ORA-00904: invalid identifier'],
            'no code at all' => [null, 'ORA-00001: unique constraint '
                .'(ACL.ACL_ASGN_LOCKS_PK) violated'],
            'right constraint, wrong code' => [2291, 'ORA-02291: integrity '
                .'constraint (ACL.ACL_ASGN_LOCKS_PK) violated'],
        ];

        foreach ($rejected as $label => [$vendorCode, $text]) {
            $this->assertNull(
                $this->classify(
                    $this->exception('HY000', $vendorCode, $text),
                    'oracle',
                ),
                sprintf('Oracle [%s] must not classify.', $label),
            );
        }
    }

    public function test_oracle_requires_the_bounded_duplicate_token(): void
    {
        $rejected = [
            'longer code' => 'ORA-000010: unique constraint '
                .'(ACL.ACL_ASGN_LOCKS_PK) violated',
            'embedded in a word' => 'XORA-00001: unique constraint '
                .'(ACL.ACL_ASGN_LOCKS_PK) violated',
            'no code token' => 'unique constraint '
                .'(ACL.ACL_ASGN_LOCKS_PK) violated',
            'different code' => 'ORA-00002: unique constraint '
                .'(ACL.ACL_ASGN_LOCKS_PK) violated',
        ];

        foreach ($rejected as $label => $text) {
            $this->assertNull(
                $this->classify(
                    $this->exception('23000', 1, $text),
                    'oracle',
                ),
                sprintf('Oracle [%s] must not classify.', $label),
            );
        }
    }

    public function test_oracle_rejects_every_other_constraint(): void
    {
        $rejected = [
            'similar suffix' => 'ACL_ASGN_LOCKS_PK_2',
            'similar prefix' => 'X_ACL_ASGN_LOCKS_PK',
            'pkey spelling' => 'ACL_ASGN_LOCKS_PKEY',
            'another gatetrail constraint' => 'ACL_ROLE_NAME_GUARD_UQ',
            'another gatetrail primary key' => 'ACL_ROLES_PK',
            'consumer constraint' => 'USERS_EMAIL_UQ',
        ];

        foreach ($rejected as $label => $constraint) {
            foreach (['23000', 'HY000'] as $sqlState) {
                $this->assertNull(
                    $this->classify(
                        $this->exception(
                            $sqlState,
                            1,
                            sprintf(
                                'ORA-00001: unique constraint (ACL.%s) violated',
                                $constraint,
                            ),
                        ),
                        'oracle',
                    ),
                    sprintf('Oracle [%s/%s] must not classify.', $label, $sqlState),
                );
            }
        }
    }

    public function test_oracle_rejects_every_other_sqlstate(): void
    {
        $text = 'ORA-00001: unique constraint (ACL.ACL_ASGN_LOCKS_PK) violated';

        foreach (['23505', '40001', '61000', '72000', '00000'] as $sqlState) {
            $this->assertNull(
                $this->classify(
                    $this->exception($sqlState, 1, $text),
                    'oracle',
                ),
                sprintf('Oracle [%s] must not classify.', $sqlState),
            );
        }
    }

    // --------------------------------------------------------------- sqlsrv

    public function test_sql_server_confirms_the_named_primary_key(): void
    {
        $accepted = [
            'single quoted' => 'Violation of PRIMARY KEY constraint '
                ."'acl_asgn_locks_pk'. Cannot insert duplicate key in object "
                ."'dbo.acl_assignment_locks'.",
            'bracketed' => 'Violation of PRIMARY KEY constraint '
                .'[acl_asgn_locks_pk].',
            'double quoted' => 'Violation of PRIMARY KEY constraint '
                .'"acl_asgn_locks_pk".',
            'schema qualified' => 'Violation of PRIMARY KEY constraint '
                ."'dbo.acl_asgn_locks_pk'.",
            'upper cased' => 'Violation of PRIMARY KEY constraint '
                ."'ACL_ASGN_LOCKS_PK'.",
            'localized prose' => 'Pelanggaran kekangan PRIMARY KEY '
                ."'acl_asgn_locks_pk'.",
        ];

        foreach ($accepted as $label => $text) {
            $failure = $this->classify(
                $this->exception('23000', 2627, $text),
                'sqlsrv',
            );

            $this->assertInstanceOf(
                ClassifiedConcurrencyFailure::class,
                $failure,
                sprintf('SQL Server [%s] should classify.', $label),
            );
            $this->assertSame('23000', $failure->sqlState);
            $this->assertSame(2627, $failure->vendorCode);
        }
    }

    public function test_sql_server_rejects_2601_and_every_other_code(): void
    {
        $text = "Violation of PRIMARY KEY constraint 'acl_asgn_locks_pk'.";

        foreach ([2601, 1205, 1222, 1062, 19, 1, null] as $vendorCode) {
            $this->assertNull(
                $this->classify(
                    $this->exception('23000', $vendorCode, $text),
                    'sqlsrv',
                ),
                sprintf(
                    'SQL Server code [%s] must not classify.',
                    var_export($vendorCode, true),
                ),
            );
        }
    }

    public function test_sql_server_rejects_every_other_constraint(): void
    {
        $rejected = [
            'similar suffix' => 'acl_asgn_locks_pk_2',
            'similar prefix' => 'x_acl_asgn_locks_pk',
            'pkey spelling' => 'acl_asgn_locks_pkey',
            'another gatetrail constraint' => 'acl_role_name_guard_uq',
            'another gatetrail primary key' => 'acl_role_asgn_pk',
            'consumer constraint' => 'PK_users',
        ];

        foreach ($rejected as $label => $constraint) {
            $this->assertNull(
                $this->classify(
                    $this->exception(
                        '23000',
                        2627,
                        sprintf(
                            "Violation of PRIMARY KEY constraint '%s'. Cannot "
                                ."insert duplicate key in object 'dbo.other'.",
                            $constraint,
                        ),
                    ),
                    'sqlsrv',
                ),
                sprintf('SQL Server [%s] must not classify.', $label),
            );
        }
    }

    // --------------------------------------------------------------- sqlite

    public function test_sqlite_requires_the_exact_column_set(): void
    {
        $accepted = [
            'declared order' => 'UNIQUE constraint failed: '
                .'acl_assignment_locks.assignment_kind, '
                .'acl_assignment_locks.scope_hash',
            'reversed order' => 'UNIQUE constraint failed: '
                .'acl_assignment_locks.scope_hash, '
                .'acl_assignment_locks.assignment_kind',
            'quoted' => 'UNIQUE constraint failed: '
                .'"acl_assignment_locks.assignment_kind", '
                .'"acl_assignment_locks.scope_hash"',
        ];

        foreach ($accepted as $label => $text) {
            $failure = $this->classify(
                $this->exception('23000', 19, $text),
                'sqlite',
            );

            $this->assertInstanceOf(
                ClassifiedConcurrencyFailure::class,
                $failure,
                sprintf('SQLite [%s] should classify.', $label),
            );
            $this->assertSame('23000', $failure->sqlState);
            $this->assertSame(19, $failure->vendorCode);
        }
    }

    public function test_sqlite_rejects_any_other_column_report(): void
    {
        $rejected = [
            'missing column' => 'UNIQUE constraint failed: '
                .'acl_assignment_locks.assignment_kind',
            'extra column' => 'UNIQUE constraint failed: '
                .'acl_assignment_locks.assignment_kind, '
                .'acl_assignment_locks.scope_hash, '
                .'acl_assignment_locks.scope_key',
            'duplicate column' => 'UNIQUE constraint failed: '
                .'acl_assignment_locks.assignment_kind, '
                .'acl_assignment_locks.assignment_kind',
            'other table' => 'UNIQUE constraint failed: '
                .'other_locks.assignment_kind, other_locks.scope_hash',
            'mixed tables' => 'UNIQUE constraint failed: '
                .'acl_assignment_locks.assignment_kind, '
                .'other_locks.scope_hash',
            'other columns' => 'UNIQUE constraint failed: '
                .'acl_assignment_locks.scope_key, '
                .'acl_assignment_locks.created_at',
            'unqualified columns' => 'UNIQUE constraint failed: '
                .'assignment_kind, scope_hash',
            'not null' => 'NOT NULL constraint failed: '
                .'acl_assignment_locks.scope_hash',
            'check' => 'CHECK constraint failed: acl_asgn_locks_ck',
            'generic code 19' => 'constraint failed',
            'empty text' => '',
        ];

        foreach ($rejected as $label => $text) {
            $this->assertNull(
                $this->classify(
                    $this->exception('23000', 19, $text),
                    'sqlite',
                ),
                sprintf('SQLite [%s] must not classify.', $label),
            );
        }
    }

    public function test_sqlite_requires_its_own_code_and_sqlstate(): void
    {
        $text = 'UNIQUE constraint failed: '
            .'acl_assignment_locks.assignment_kind, '
            .'acl_assignment_locks.scope_hash';

        foreach ([5, 6, 1, 1062, 2067, null] as $vendorCode) {
            $this->assertNull(
                $this->classify(
                    $this->exception('23000', $vendorCode, $text),
                    'sqlite',
                ),
            );
        }

        foreach (['HY000', '23505', '40001'] as $sqlState) {
            $this->assertNull(
                $this->classify(
                    $this->exception($sqlState, 19, $text),
                    'sqlite',
                ),
            );
        }
    }

    // ---------------------------------------------------------------- mysql

    public function test_mysql_accepts_the_qualified_and_bare_primary_key(): void
    {
        $accepted = [
            'qualified 8.0.19 form' => "Duplicate entry 'role-a1b2' for key "
                ."'acl_assignment_locks.PRIMARY'",
            'backtick qualified' => 'Duplicate entry \'role-a1b2\' for key '
                .'`acl_assignment_locks`.`PRIMARY`',
            'double quote qualified' => 'Duplicate entry \'role-a1b2\' for key '
                .'"acl_assignment_locks"."PRIMARY"',
            'upper cased table' => "Duplicate entry 'role-a1b2' for key "
                ."'ACL_ASSIGNMENT_LOCKS.PRIMARY'",
            'bare legacy form' => "Duplicate entry 'role-a1b2' for key 'PRIMARY'",
            'bare and localized' => "Entri berganda 'role-a1b2' untuk kunci 'PRIMARY'",
            'bare with no prose at all' => 'PRIMARY',
        ];

        foreach ($accepted as $label => $text) {
            $failure = $this->classify(
                $this->exception('23000', 1062, $text),
                'mysql',
            );

            $this->assertInstanceOf(
                ClassifiedConcurrencyFailure::class,
                $failure,
                sprintf('MySQL [%s] should classify.', $label),
            );
            $this->assertSame('23000', $failure->sqlState);
            $this->assertSame(1062, $failure->vendorCode);
            $this->assertSame('mysql', $failure->driver);
        }
    }

    public function test_mysql_rejects_a_qualified_primary_key_of_another_table(): void
    {
        $rejected = [
            'another gatetrail table' => "Duplicate entry '1' for key "
                ."'acl_roles.PRIMARY'",
            'consumer table' => "Duplicate entry '1' for key 'users.PRIMARY'",
            'backticked other table' => 'Duplicate entry \'1\' for key '
                .'`acl_role_assignments`.`PRIMARY`',
            'other table qualified beside a bare mention' => 'PRIMARY key '
                ."violated: Duplicate entry '1' for key 'acl_roles.PRIMARY'",
        ];

        foreach ($rejected as $label => $text) {
            $this->assertNull(
                $this->classify(
                    $this->exception('23000', 1062, $text),
                    'mysql',
                ),
                sprintf('MySQL [%s] must not classify.', $label),
            );
        }
    }

    public function test_mysql_rejects_another_named_key(): void
    {
        $rejected = [
            'another gatetrail key' => "Duplicate entry 'reviewer-web' for key "
                ."'acl_role_name_guard_uq'",
            'qualified other key' => "Duplicate entry 'reviewer-web' for key "
                ."'acl_assignment_locks.acl_asgn_locks_alt_uq'",
            'similar suffix' => "Duplicate entry '1' for key 'PRIMARY_2'",
            'similar prefix' => "Duplicate entry '1' for key 'X_PRIMARY'",
            'consumer key' => "Duplicate entry 'a@b.test' for key "
                ."'users_email_unique'",
        ];

        foreach ($rejected as $label => $text) {
            $this->assertNull(
                $this->classify(
                    $this->exception('23000', 1062, $text),
                    'mysql',
                ),
                sprintf('MySQL [%s] must not classify.', $label),
            );
        }
    }

    public function test_mysql_requires_its_own_code_and_sqlstate(): void
    {
        $text = "Duplicate entry 'role-a1b2' for key "
            ."'acl_assignment_locks.PRIMARY'";

        foreach ([1586, 1213, 1205, 2627, 19, null] as $vendorCode) {
            $this->assertNull(
                $this->classify(
                    $this->exception('23000', $vendorCode, $text),
                    'mysql',
                ),
            );
        }

        foreach (['23505', '40001', 'HY000'] as $sqlState) {
            $this->assertNull(
                $this->classify(
                    $this->exception($sqlState, 1062, $text),
                    'mysql',
                ),
            );
        }
    }

    // ------------------------------------------------- resolved identity

    public function test_a_configured_table_prefix_is_honoured(): void
    {
        $prefixed = $this->classifier(prefix: 'gt_');

        $this->assertInstanceOf(
            ClassifiedConcurrencyFailure::class,
            $prefixed->classify(
                $this->exception(
                    '23000',
                    1062,
                    "Duplicate entry 'role-a1b2' for key "
                        ."'gt_acl_assignment_locks.PRIMARY'",
                ),
                'mysql',
            ),
        );

        $this->assertInstanceOf(
            ClassifiedConcurrencyFailure::class,
            $prefixed->classify(
                $this->exception(
                    '23000',
                    19,
                    'UNIQUE constraint failed: '
                        .'gt_acl_assignment_locks.assignment_kind, '
                        .'gt_acl_assignment_locks.scope_hash',
                ),
                'sqlite',
            ),
        );

        // The unprefixed table is now a different table.
        $this->assertNull(
            $prefixed->classify(
                $this->exception(
                    '23000',
                    1062,
                    "Duplicate entry 'role-a1b2' for key "
                        ."'acl_assignment_locks.PRIMARY'",
                ),
                'mysql',
            ),
        );
        $this->assertNull(
            $prefixed->classify(
                $this->exception(
                    '23000',
                    19,
                    'UNIQUE constraint failed: '
                        .'acl_assignment_locks.assignment_kind, '
                        .'acl_assignment_locks.scope_hash',
                ),
                'sqlite',
            ),
        );
    }

    public function test_a_configured_schema_does_not_break_identity(): void
    {
        $qualified = $this->classifier(schema: 'gatetrail');

        // PostgreSQL and Oracle may qualify the constraint; the token match
        // accepts the qualifier through its separating dot.
        $this->assertInstanceOf(
            ClassifiedConcurrencyFailure::class,
            $qualified->classify(
                $this->exception(
                    '23505',
                    7,
                    'duplicate key value violates unique constraint '
                        .'"gatetrail.acl_asgn_locks_pk"',
                ),
                'pgsql',
            ),
        );

        // SQLite reports `table.column` with no schema, so identity must still
        // resolve from the unqualified table name.
        $this->assertInstanceOf(
            ClassifiedConcurrencyFailure::class,
            $qualified->classify(
                $this->exception(
                    '23000',
                    19,
                    'UNIQUE constraint failed: '
                        .'acl_assignment_locks.assignment_kind, '
                        .'acl_assignment_locks.scope_hash',
                ),
                'sqlite',
            ),
        );
    }

    public function test_identity_comes_from_the_manifest_and_not_a_literal(): void
    {
        // The expected names are the manifest's own, so this test fails the
        // moment a second hardcoded manifest appears.
        $primaryKey = null;

        foreach (
            SchemaManifest::gateTrail()->table('assignment_locks')->constraints as $constraint
        ) {
            if ($constraint->type->value === 'primary') {
                $primaryKey = $constraint;
            }
        }

        $this->assertNotNull($primaryKey);
        $this->assertSame(self::CONSTRAINT, $primaryKey->name);
        $this->assertSame(
            ['assignment_kind', 'scope_hash'],
            $primaryKey->columns,
        );
        $this->assertSame(
            self::LOCK_TABLE,
            (new SchemaContext(
                self::configuration(),
                new IdentifierValidator,
                SchemaManifest::gateTrail(),
            ))->tableName('assignment_locks'),
        );
    }

    // ------------------------------------------------------- safety rules

    public function test_only_the_driver_diagnostic_field_is_searched(): void
    {
        // The constraint name is planted in the SQL and the bindings, which is
        // exactly where Laravel's formatted message would find it.
        $previous = new PDOException(
            'duplicate key value violates unique constraint "consumer_uq"',
        );
        $previous->errorInfo = [
            '23505',
            7,
            'duplicate key value violates unique constraint "consumer_uq"',
        ];
        $exception = new QueryException(
            'testing',
            'insert into "acl_assignment_locks" /* acl_asgn_locks_pk */ '
                .'("assignment_kind") values (?)',
            ['acl_asgn_locks_pk'],
            $previous,
        );

        $this->assertStringContainsString(
            self::CONSTRAINT,
            $exception->getMessage(),
            'The planted name must really be in the formatted message.',
        );
        $this->assertNull($this->classify($exception, 'pgsql'));
    }

    public function test_diagnostic_text_alone_is_never_sufficient(): void
    {
        $text = 'duplicate key value violates unique constraint '
            .'"acl_asgn_locks_pk"';

        // Perfect identity, wrong structured agreement, on every driver.
        foreach (['pgsql', 'mysql', 'sqlsrv', 'oracle', 'sqlite'] as $driver) {
            $this->assertNull(
                $this->classify(
                    $this->exception('00000', 0, $text),
                    $driver,
                ),
                sprintf('[%s] must not classify on text alone.', $driver),
            );
        }
    }

    public function test_a_malformed_or_missing_errorinfo_is_not_classified(): void
    {
        foreach ([null, [], ['23505'], [23505, 7, 'x'], ['23505', 7, null]] as $errorInfo) {
            $previous = new PDOException('duplicate key');
            /** @var array<int, mixed>|null $errorInfo */
            $previous->errorInfo = $errorInfo;

            $this->assertNull(
                $this->classify(
                    new QueryException('testing', 'insert', [], $previous),
                    'pgsql',
                ),
            );
        }
    }

    public function test_malformed_vendor_codes_are_rejected(): void
    {
        $text = 'ORA-00001: unique constraint (ACL.ACL_ASGN_LOCKS_PK) violated';

        foreach ([
            'signed' => '+1',
            'negative' => '-1',
            'padded' => ' 1',
            'leading zero' => '01',
            'decimal' => '1.0',
            'exponent' => '1e0',
            'hex' => '0x1',
            'overflow' => '99999999999999999999999',
            'float' => 1.0,
            'true' => true,
        ] as $label => $vendorCode) {
            $this->assertNull(
                $this->classify(
                    $this->exception('HY000', $vendorCode, $text),
                    'oracle',
                ),
                sprintf('A [%s] vendor code must not classify.', $label),
            );
        }

        // The canonical decimal string of the same number still works.
        $this->assertInstanceOf(
            ClassifiedConcurrencyFailure::class,
            $this->classify(
                $this->exception('HY000', '1', $text),
                'oracle',
            ),
        );
    }

    public function test_a_lowercase_driver_sqlstate_is_not_classified(): void
    {
        $this->assertNull(
            $this->classify(
                $this->exception(
                    'hy000',
                    1,
                    'ORA-00001: unique constraint (ACL.ACL_ASGN_LOCKS_PK) violated',
                ),
                'oracle',
            ),
        );
    }

    public function test_an_arbitrary_query_exception_is_not_classified(): void
    {
        $this->assertNull(
            $this->classify(
                new QueryException(
                    'testing',
                    'insert into "acl_assignment_locks" values (?)',
                    ['administrator scope'],
                    new PDOException('Something went wrong'),
                ),
                'pgsql',
            ),
        );
    }

    public function test_a_confirmed_race_returns_only_canonical_safe_scalars(): void
    {
        $failure = $this->classify(
            $this->exception(
                'HY000',
                1,
                'ORA-00001: unique constraint (ACL.ACL_ASGN_LOCKS_PK) violated '
                    ."while inserting scope 'administrator' for hunter2",
            ),
            'oci8',
        );

        $this->assertInstanceOf(ClassifiedConcurrencyFailure::class, $failure);
        $this->assertSame(
            ['driver', 'category', 'sqlState', 'vendorCode'],
            array_keys(get_object_vars($failure)),
        );
        $this->assertSame('oracle', $failure->driver);
        $this->assertSame('coordination_race', $failure->category);
        $this->assertSame('HY000', $failure->sqlState);
        $this->assertSame(1, $failure->vendorCode);

        $graph = json_encode(get_object_vars($failure), JSON_THROW_ON_ERROR);

        foreach ([
            'ORA-00001',
            'ACL_ASGN_LOCKS_PK',
            'acl_assignment_locks',
            'administrator',
            'hunter2',
            'insert into',
        ] as $leak) {
            $this->assertStringNotContainsString($leak, $graph);
        }
    }

    public function test_the_classifier_holds_no_state_from_a_classification(): void
    {
        $classifier = $this->classifier();
        $properties = static function (object $subject): array {
            $values = [];

            foreach ((new ReflectionClass($subject))->getProperties() as $property) {
                $values[$property->getName()] = $property->getValue($subject);
            }

            return $values;
        };

        $before = $properties($classifier);

        $classifier->classify($this->vectorFor('pgsql'), 'pgsql');

        $this->assertSame(
            ['schema', 'manifest', 'adapters'],
            array_keys($before),
        );
        $this->assertEquals($before, $properties($classifier));
    }

    // ------------------------------------------------------------- helpers

    private function classify(
        QueryException $exception,
        string $driver,
    ): ?ClassifiedConcurrencyFailure {
        return $this->classifier()->classify($exception, $driver);
    }

    private function classifier(
        ?string $prefix = null,
        ?string $schema = null,
    ): CoordinationRaceClassifier {
        $manifest = SchemaManifest::gateTrail();
        $config = self::configuration();
        $config['database']['prefix'] = $prefix;
        $config['database']['schema'] = $schema;

        return new CoordinationRaceClassifier(
            new SchemaContext($config, new IdentifierValidator, $manifest),
            $manifest,
            new SchemaAdapterFactory,
        );
    }

    /**
     * The shipped configuration, so the expected table names are never a
     * second copy maintained inside the test suite.
     *
     * @return array<string, mixed>
     */
    private static function configuration(): array
    {
        /** @var array<string, mixed> $config */
        $config = require __DIR__.'/../../config/gatetrail.php';

        return $config;
    }

    private function vectorFor(string $canonicalDriver): QueryException
    {
        return match ($canonicalDriver) {
            'mysql' => $this->exception(
                '23000',
                1062,
                "Duplicate entry 'role-a1b2' for key "
                    ."'acl_assignment_locks.PRIMARY'",
            ),
            'oracle' => $this->exception(
                'HY000',
                1,
                'ORA-00001: unique constraint (ACL.ACL_ASGN_LOCKS_PK) violated',
            ),
            'sqlsrv' => $this->exception(
                '23000',
                2627,
                "Violation of PRIMARY KEY constraint 'acl_asgn_locks_pk'.",
            ),
            'sqlite' => $this->exception(
                '23000',
                19,
                'UNIQUE constraint failed: '
                    .'acl_assignment_locks.assignment_kind, '
                    .'acl_assignment_locks.scope_hash',
            ),
            default => $this->exception(
                '23505',
                7,
                'duplicate key value violates unique constraint '
                    .'"acl_asgn_locks_pk"',
            ),
        };
    }

    private function exception(
        string $sqlState,
        mixed $vendorCode,
        string $text,
    ): QueryException {
        $previous = new PDOException($text);
        $previous->errorInfo = [$sqlState, $vendorCode, $text];

        return new QueryException(
            'testing',
            'insert into "acl_assignment_locks" '
                .'("assignment_kind", "scope_hash") values (?, ?)',
            ['role', 'a1b2c3'],
            $previous,
        );
    }
}

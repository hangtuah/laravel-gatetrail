<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Database;

use HangTuah\GateTrail\Database\Schema\Inspection\PostgreSql\PgCatalogPostgreSqlCatalogReader;
use HangTuah\GateTrail\Database\Schema\Inspection\PostgreSql\PostgreSqlIdentityGeneration;
use HangTuah\GateTrail\Database\Schema\Inspection\PostgreSql\PostgreSqlSchemaInspector;
use HangTuah\GateTrail\Database\Schema\Inspection\SchemaInspectorFactory;
use HangTuah\GateTrail\Database\Schema\Manifest\SchemaManifest;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Tests\Concerns\AssertsReadOnlySql;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\ConnectionInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class PostgreSqlCatalogReaderTest extends TestCase
{
    use AssertsReadOnlySql;

    /**
     * The reader issues exactly five statements: the current schema, the seven
     * configured tables, their columns, their indexes and their constraints.
     * Generated-key metadata is projected from the column rows rather than
     * read again.
     */
    private const QUERY_COUNT = 5;

    public function test_catalog_reader_executes_only_read_queries(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->exactly(self::QUERY_COUNT))
            ->method('select')
            ->with(
                $this->callback(
                    static fn (string $query): bool => str_starts_with(
                        strtoupper(ltrim($query)),
                        'SELECT',
                    ),
                ),
                $this->isType('array'),
                true,
            )
            ->willReturnOnConsecutiveCalls(
                [(object) ['schema_name' => 'public']],
                [],
                [],
                [],
                [],
            );
        $connection->expects($this->never())->method('statement');
        $connection->expects($this->never())->method('affectingStatement');
        $connection->expects($this->never())->method('unprepared');
        $connection->expects($this->never())->method('insert');
        $connection->expects($this->never())->method('update');
        $connection->expects($this->never())->method('delete');
        $connection->expects($this->never())->method('beginTransaction');

        $snapshot = $this->reader($connection)->read(
            $this->manifest(),
            $this->context(),
        );

        $this->assertSame('public', $snapshot->schema);
        $this->assertSame([], $snapshot->tables);
        $this->assertSame([], $snapshot->columns);
        $this->assertSame([], $snapshot->indexes);
        $this->assertSame([], $snapshot->constraints);
        $this->assertSame([], $snapshot->identities);
    }

    public function test_no_statement_mutates_the_schema_or_its_data(): void
    {
        $queries = [];
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('select')
            ->willReturnCallback(
                static function (string $query) use (&$queries): array {
                    $queries[] = $query;

                    return str_contains($query, 'current_schema()')
                        ? [(object) ['schema_name' => 'public']]
                        : [];
                },
            );

        $this->reader($connection)->read(
            $this->manifest(),
            $this->context(),
        );

        $this->assertCount(self::QUERY_COUNT, $queries);
        $this->assertReadOnlyStatements($queries);
    }

    public function test_every_binding_is_a_validated_identifier_and_never_inlined(): void
    {
        $bindings = [];
        $queries = [];
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('select')
            ->willReturnCallback(
                static function (
                    string $query,
                    array $values,
                ) use (&$queries, &$bindings): array {
                    $queries[] = $query;
                    $bindings[] = $values;

                    return str_contains($query, 'current_schema()')
                        ? [(object) ['schema_name' => 'public']]
                        : [];
                },
            );

        $this->reader($connection)->read(
            $this->manifest(),
            $this->context(),
        );

        $this->assertSame([], $bindings[0]);

        foreach (array_slice($bindings, 1) as $values) {
            $this->assertSame(
                [
                    'public',
                    'acl_roles',
                    'acl_permissions',
                    'acl_assignment_locks',
                    'acl_role_assignments',
                    'acl_permission_assignments',
                    'acl_role_permissions',
                    'acl_access_audits',
                ],
                $values,
            );
        }

        foreach (array_slice($queries, 1) as $query) {
            $this->assertStringNotContainsString('acl_', $query);
            $this->assertStringNotContainsString("'public'", $query);
            $this->assertSame(8, substr_count($query, '?'));
        }
    }

    public function test_a_configured_schema_and_prefix_replace_the_current_schema_lookup(): void
    {
        $this->app['config']->set('gatetrail.database.schema', 'security');
        $this->app['config']->set('gatetrail.database.prefix', 'gt_');

        $bindings = [];
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->exactly(self::QUERY_COUNT - 1))
            ->method('select')
            ->willReturnCallback(
                static function (
                    string $query,
                    array $values,
                ) use (&$bindings): array {
                    $bindings[] = $values;

                    return [];
                },
            );

        $snapshot = $this->reader($connection)->read(
            $this->manifest(),
            $this->context(),
        );

        $this->assertSame('security', $snapshot->schema);

        foreach ($bindings as $values) {
            $this->assertSame('security', $values[0]);
            $this->assertSame('gt_acl_roles', $values[1]);
            $this->assertSame('gt_acl_access_audits', $values[7]);
        }
    }

    public function test_an_unresolvable_current_schema_fails_closed(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('select')->willReturn([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'GateTrail could not resolve the current PostgreSQL schema.',
        );

        $this->reader($connection)->read(
            $this->manifest(),
            $this->context(),
        );
    }

    public function test_catalog_rows_are_normalized_and_grouped(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->exactly(self::QUERY_COUNT))
            ->method('select')
            ->willReturnOnConsecutiveCalls(
                [(object) ['schema_name' => 'public']],
                [(object) ['table_name' => 'acl_roles']],
                [
                    (object) [
                        'table_name' => 'acl_roles',
                        'column_name' => 'id',
                        'data_type' => 'bigint',
                        'is_nullable' => 'NO',
                        'character_maximum_length' => null,
                        'datetime_precision' => null,
                        'collation_name' => null,
                        'column_default' => null,
                        'is_identity' => 'YES',
                        'identity_generation' => 'BY DEFAULT',
                    ],
                    (object) [
                        'table_name' => 'acl_roles',
                        'column_name' => 'name',
                        'data_type' => 'character varying',
                        'is_nullable' => 'NO',
                        'character_maximum_length' => '191',
                        'datetime_precision' => null,
                        'collation_name' => 'C',
                        'column_default' => null,
                        'is_identity' => 'NO',
                        'identity_generation' => null,
                    ],
                    (object) [
                        'table_name' => 'acl_roles',
                        'column_name' => 'created_at',
                        'data_type' => 'timestamp without time zone',
                        'is_nullable' => 'NO',
                        'character_maximum_length' => null,
                        'datetime_precision' => '0',
                        'collation_name' => null,
                        'column_default' => null,
                        'is_identity' => 'NO',
                        'identity_generation' => null,
                    ],
                ],
                [
                    $this->indexRow('acl_role_name_guard_uq', 'name', [
                        'is_unique' => true,
                        'constraint_name' => 'acl_role_name_guard_uq',
                    ]),
                    $this->indexRow('acl_role_name_guard_uq', 'guard_name', [
                        'is_unique' => true,
                        'constraint_name' => 'acl_role_name_guard_uq',
                    ]),
                    $this->indexRow('acl_roles_name_ix', 'name'),
                ],
                [
                    $this->constraintRow('acl_role_name_guard_uq', 'u', [
                        'column_name' => 'name',
                    ]),
                    $this->constraintRow('acl_role_name_guard_uq', 'u', [
                        'column_name' => 'guard_name',
                    ]),
                ],
            );

        $snapshot = $this->reader($connection)->read(
            $this->manifest(),
            $this->context(),
        );

        $this->assertSame(['acl_roles'], $snapshot->tables);

        $name = $snapshot->column('acl_roles', 'name');
        $this->assertNotNull($name);
        $this->assertSame('character varying', $name->dataType);
        $this->assertSame(191, $name->characterLength);
        $this->assertSame('C', $name->collation);
        $this->assertFalse($name->nullable);

        $createdAt = $snapshot->column('acl_roles', 'created_at');
        $this->assertNotNull($createdAt);
        $this->assertSame(0, $createdAt->datetimePrecision);
        $this->assertNull($createdAt->collation);

        $unique = $snapshot->index('acl_roles', 'acl_role_name_guard_uq');
        $this->assertNotNull($unique);
        $this->assertSame(['name', 'guard_name'], $unique->columns);
        $this->assertTrue($unique->unique);
        $this->assertSame('acl_role_name_guard_uq', $unique->constraint);
        $this->assertTrue($unique->complete);

        $explicit = $snapshot->index('acl_roles', 'acl_roles_name_ix');
        $this->assertNotNull($explicit);
        $this->assertNull($explicit->constraint);
        $this->assertFalse($explicit->unique);

        $constraint = $snapshot->constraint(
            'acl_roles',
            'acl_role_name_guard_uq',
        );
        $this->assertNotNull($constraint);
        $this->assertSame('u', $constraint->type);
        $this->assertSame(['name', 'guard_name'], $constraint->columns);
        $this->assertTrue($constraint->validated);
        $this->assertNull($constraint->deleteRule);

        $identity = $snapshot->identity('acl_roles', 'id');
        $this->assertNotNull($identity);
        $this->assertSame(
            PostgreSqlIdentityGeneration::ByDefault,
            $identity->generation,
        );
    }

    public function test_a_bigserial_default_is_normalized_to_a_sequence_identity(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('select')->willReturnOnConsecutiveCalls(
            [(object) ['schema_name' => 'public']],
            [(object) ['table_name' => 'acl_roles']],
            [
                (object) [
                    'table_name' => 'acl_roles',
                    'column_name' => 'id',
                    'data_type' => 'bigint',
                    'is_nullable' => 'NO',
                    'character_maximum_length' => null,
                    'datetime_precision' => null,
                    'collation_name' => null,
                    'column_default' => "nextval('acl_roles_id_seq'::regclass)",
                    'is_identity' => 'NO',
                    'identity_generation' => null,
                ],
            ],
            [],
            [],
        );

        $snapshot = $this->reader($connection)->read(
            $this->manifest(),
            $this->context(),
        );
        $identity = $snapshot->identity('acl_roles', 'id');

        $this->assertNotNull($identity);
        $this->assertSame(
            PostgreSqlIdentityGeneration::SequenceDefault,
            $identity->generation,
        );
        $this->assertSame('acl_roles_id_seq', $identity->sequence);
        $this->assertTrue($identity->generation->acceptsExplicitValue());
    }

    public function test_an_always_identity_is_recorded_and_rejects_explicit_values(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('select')->willReturnOnConsecutiveCalls(
            [(object) ['schema_name' => 'public']],
            [(object) ['table_name' => 'acl_roles']],
            [
                (object) [
                    'table_name' => 'acl_roles',
                    'column_name' => 'id',
                    'data_type' => 'bigint',
                    'is_nullable' => 'NO',
                    'character_maximum_length' => null,
                    'datetime_precision' => null,
                    'collation_name' => null,
                    'column_default' => null,
                    'is_identity' => 'YES',
                    'identity_generation' => 'ALWAYS',
                ],
            ],
            [],
            [],
        );

        $identity = $this->reader($connection)
            ->read($this->manifest(), $this->context())
            ->identity('acl_roles', 'id');

        $this->assertNotNull($identity);
        $this->assertSame(
            PostgreSqlIdentityGeneration::Always,
            $identity->generation,
        );
        $this->assertFalse($identity->generation->acceptsExplicitValue());
    }

    public function test_referential_actions_and_check_expressions_are_normalized(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('select')->willReturnOnConsecutiveCalls(
            [(object) ['schema_name' => 'public']],
            [],
            [],
            [],
            [
                $this->constraintRow('acl_ra_role_fk', 'f', [
                    'table_name' => 'acl_role_assignments',
                    'column_name' => 'role_id',
                    'referenced_schema' => 'public',
                    'referenced_table' => 'acl_roles',
                    'referenced_column' => 'id',
                    'delete_action' => 'r',
                    'update_action' => 'a',
                    'definition' => 'FOREIGN KEY (role_id) REFERENCES acl_roles(id)',
                ]),
                $this->constraintRow('acl_ra_period_ck', 'c', [
                    'table_name' => 'acl_role_assignments',
                    'column_name' => 'expires_at',
                    'definition' => 'CHECK (((expires_at IS NULL) OR (expires_at > COALESCE(starts_at, assigned_at))))',
                ]),
                $this->constraintRow('acl_ra_bad_ck', 'c', [
                    'table_name' => 'acl_role_assignments',
                    'column_name' => 'expires_at',
                    'definition' => 'NOT A CHECK CLAUSE',
                ]),
                $this->constraintRow('acl_ra_odd_fk', 'f', [
                    'table_name' => 'acl_role_assignments',
                    'column_name' => 'role_id',
                    'referenced_schema' => 'public',
                    'referenced_table' => 'acl_roles',
                    'referenced_column' => 'id',
                    'delete_action' => 'z',
                    'update_action' => 'c',
                ]),
            ],
        );

        $snapshot = $this->reader($connection)->read(
            $this->manifest(),
            $this->context(),
        );

        $foreign = $snapshot->constraint(
            'acl_role_assignments',
            'acl_ra_role_fk',
        );
        $this->assertNotNull($foreign);
        $this->assertSame('RESTRICT', $foreign->deleteRule);
        $this->assertSame('NO ACTION', $foreign->updateRule);
        $this->assertSame('public', $foreign->referencedSchema);
        $this->assertSame('acl_roles', $foreign->referencedTable);
        $this->assertSame(['id'], $foreign->referencedColumns);
        $this->assertNull($foreign->checkExpression);
        $this->assertFalse($foreign->deferrable);
        $this->assertFalse($foreign->deferred);

        $check = $snapshot->constraint(
            'acl_role_assignments',
            'acl_ra_period_ck',
        );
        $this->assertNotNull($check);
        $this->assertSame(
            '((expires_at IS NULL) OR (expires_at > COALESCE(starts_at, assigned_at)))',
            $check->checkExpression,
        );
        $this->assertNull($check->deleteRule);

        $unreadable = $snapshot->constraint(
            'acl_role_assignments',
            'acl_ra_bad_ck',
        );
        $this->assertNotNull($unreadable);
        $this->assertNull($unreadable->checkExpression);

        $unknownAction = $snapshot->constraint(
            'acl_role_assignments',
            'acl_ra_odd_fk',
        );
        $this->assertNotNull($unknownAction);
        $this->assertNull($unknownAction->deleteRule);
        $this->assertSame('CASCADE', $unknownAction->updateRule);
    }

    public function test_an_expression_or_unresolvable_index_key_is_marked_incomplete(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('select')->willReturnOnConsecutiveCalls(
            [(object) ['schema_name' => 'public']],
            [],
            [],
            [
                $this->indexRow('acl_roles_lower_ix', 'name', [
                    'has_expressions' => true,
                ]),
                $this->indexRow('acl_roles_partial_ix', 'name', [
                    'is_partial' => true,
                ]),
                $this->indexRow('acl_roles_opaque_ix', null),
                $this->indexRow('acl_roles_invalid_ix', 'name', [
                    'is_valid' => false,
                    'is_live' => false,
                ]),
            ],
            [],
        );

        $snapshot = $this->reader($connection)->read(
            $this->manifest(),
            $this->context(),
        );

        $expression = $snapshot->index('acl_roles', 'acl_roles_lower_ix');
        $this->assertNotNull($expression);
        $this->assertFalse($expression->complete);

        $partial = $snapshot->index('acl_roles', 'acl_roles_partial_ix');
        $this->assertNotNull($partial);
        $this->assertFalse($partial->complete);

        $opaque = $snapshot->index('acl_roles', 'acl_roles_opaque_ix');
        $this->assertNotNull($opaque);
        $this->assertFalse($opaque->complete);
        $this->assertSame([], $opaque->columns);

        $invalid = $snapshot->index('acl_roles', 'acl_roles_invalid_ix');
        $this->assertNotNull($invalid);
        $this->assertTrue($invalid->complete);
        $this->assertFalse($invalid->valid);
        $this->assertFalse($invalid->live);
    }

    public function test_driver_boolean_representations_are_all_understood(): void
    {
        foreach ([
            ['t', 'f'],
            [true, false],
            [1, 0],
        ] as [$true, $false]) {
            $connection = $this->createMock(ConnectionInterface::class);
            $connection->method('select')->willReturnOnConsecutiveCalls(
                [(object) ['schema_name' => 'public']],
                [],
                [],
                [
                    (object) [
                        'table_name' => 'acl_roles',
                        'index_name' => 'acl_roles_pk',
                        'is_unique' => $true,
                        'is_primary' => $true,
                        'is_valid' => $true,
                        'is_live' => $true,
                        'is_partial' => $false,
                        'has_expressions' => $false,
                        'constraint_name' => 'acl_roles_pk',
                        'column_name' => 'id',
                    ],
                ],
                [],
            );

            $index = $this->reader($connection)
                ->read($this->manifest(), $this->context())
                ->index('acl_roles', 'acl_roles_pk');

            $this->assertNotNull($index);
            $this->assertTrue($index->unique);
            $this->assertTrue($index->primary);
            $this->assertTrue($index->valid);
            $this->assertTrue($index->live);
            $this->assertTrue($index->complete);
        }
    }

    public function test_the_referenced_schema_is_read_and_mapped(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('select')->willReturnOnConsecutiveCalls(
            [(object) ['schema_name' => 'security']],
            [],
            [],
            [],
            [
                $this->constraintRow('acl_ra_role_fk', 'f', [
                    'table_name' => 'acl_role_assignments',
                    'column_name' => 'role_id',
                    'referenced_schema' => 'security',
                    'referenced_table' => 'acl_roles',
                    'referenced_column' => 'id',
                    'delete_action' => 'r',
                    'update_action' => 'r',
                ]),
                $this->constraintRow('acl_ra_decoy_fk', 'f', [
                    'table_name' => 'acl_role_assignments',
                    'column_name' => 'role_id',
                    'referenced_schema' => 'public',
                    'referenced_table' => 'acl_roles',
                    'referenced_column' => 'id',
                    'delete_action' => 'r',
                    'update_action' => 'r',
                ]),
            ],
        );

        $snapshot = $this->reader($connection)->read(
            $this->manifest(),
            $this->context(),
        );

        $canonical = $snapshot->constraint(
            'acl_role_assignments',
            'acl_ra_role_fk',
        );
        $this->assertNotNull($canonical);
        $this->assertSame('security', $canonical->referencedSchema);

        $decoy = $snapshot->constraint(
            'acl_role_assignments',
            'acl_ra_decoy_fk',
        );
        $this->assertNotNull($decoy);
        $this->assertSame('public', $decoy->referencedSchema);

        // Same table and columns; only the namespace separates them.
        $this->assertSame(
            $canonical->referencedTable,
            $decoy->referencedTable,
        );
        $this->assertSame(
            $canonical->referencedColumns,
            $decoy->referencedColumns,
        );
    }

    public function test_the_constraint_query_reads_the_referenced_namespace_and_deferrability(): void
    {
        $queries = [];
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('select')->willReturnCallback(
            static function (string $query) use (&$queries): array {
                $queries[] = $query;

                return str_contains($query, 'current_schema()')
                    ? [(object) ['schema_name' => 'public']]
                    : [];
            },
        );

        $this->reader($connection)->read(
            $this->manifest(),
            $this->context(),
        );

        $constraintQuery = $queries[4];

        $this->assertStringContainsString(
            'rn.nspname AS referenced_schema',
            $constraintQuery,
        );
        $this->assertStringContainsString(
            'con.condeferrable AS is_deferrable',
            $constraintQuery,
        );
        $this->assertStringContainsString(
            'con.condeferred AS is_deferred',
            $constraintQuery,
        );
        $this->assertStringContainsString(
            'LEFT JOIN pg_catalog.pg_namespace rn',
            $constraintQuery,
            'The referenced namespace must join through LEFT JOIN so non-foreign constraints survive.',
        );
        $this->assertReadOnlyStatement($constraintQuery);
    }

    /**
     * @return array<string, array{mixed, mixed, bool, bool}>
     */
    public static function deferrabilityRows(): array
    {
        return [
            'NOT DEFERRABLE' => ['f', 'f', false, false],
            'DEFERRABLE INITIALLY IMMEDIATE' => ['t', 'f', true, false],
            'DEFERRABLE INITIALLY DEFERRED' => ['t', 't', true, true],
            'native booleans, deferred' => [true, true, true, true],
            'integer booleans, immediate' => [1, 0, true, false],
        ];
    }

    #[DataProvider('deferrabilityRows')]
    public function test_deferrability_flags_are_normalized_as_booleans(
        mixed $deferrable,
        mixed $deferred,
        bool $expectedDeferrable,
        bool $expectedDeferred,
    ): void {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('select')->willReturnOnConsecutiveCalls(
            [(object) ['schema_name' => 'public']],
            [],
            [],
            [],
            [
                $this->constraintRow('acl_ra_role_fk', 'f', [
                    'table_name' => 'acl_role_assignments',
                    'column_name' => 'role_id',
                    'referenced_schema' => 'public',
                    'referenced_table' => 'acl_roles',
                    'referenced_column' => 'id',
                    'delete_action' => 'a',
                    'update_action' => 'a',
                    'is_deferrable' => $deferrable,
                    'is_deferred' => $deferred,
                ]),
            ],
        );

        $constraint = $this->reader($connection)
            ->read($this->manifest(), $this->context())
            ->constraint('acl_role_assignments', 'acl_ra_role_fk');

        $this->assertNotNull($constraint);
        $this->assertSame($expectedDeferrable, $constraint->deferrable);
        $this->assertSame($expectedDeferred, $constraint->deferred);
        $this->assertSame('NO ACTION', $constraint->deleteRule);
    }

    /**
     * A non-foreign constraint has no referenced namespace to report, and
     * PostgreSQL reports it as neither deferrable nor deferred.
     */
    public function test_non_foreign_constraint_metadata_is_nullable_and_defaulted(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('select')->willReturnOnConsecutiveCalls(
            [(object) ['schema_name' => 'public']],
            [],
            [],
            [],
            [
                $this->constraintRow('acl_roles_pk', 'p', [
                    'column_name' => 'id',
                ]),
                $this->constraintRow('acl_role_name_guard_uq', 'u', [
                    'column_name' => 'name',
                ]),
                $this->constraintRow('acl_audit_subject_ck', 'c', [
                    'column_name' => 'subject_type',
                    'definition' => 'CHECK ((subject_type IS NOT NULL))',
                ]),
            ],
        );

        $snapshot = $this->reader($connection)->read(
            $this->manifest(),
            $this->context(),
        );

        foreach ([
            'acl_roles_pk',
            'acl_role_name_guard_uq',
            'acl_audit_subject_ck',
        ] as $name) {
            $constraint = $snapshot->constraint('acl_roles', $name);

            $this->assertNotNull($constraint, $name);
            $this->assertNull($constraint->referencedSchema, $name);
            $this->assertNull($constraint->referencedTable, $name);
            $this->assertSame([], $constraint->referencedColumns, $name);
            $this->assertNull($constraint->deleteRule, $name);
            $this->assertNull($constraint->updateRule, $name);
            $this->assertFalse($constraint->deferrable, $name);
            $this->assertFalse($constraint->deferred, $name);
        }
    }

    public function test_factory_builds_the_postgresql_inspector(): void
    {
        $inspector = $this->app->make(SchemaInspectorFactory::class)
            ->forDriver(
                'pgsql',
                $this->createMock(ConnectionInterface::class),
            );

        $this->assertInstanceOf(PostgreSqlSchemaInspector::class, $inspector);
    }

    private function reader(
        ConnectionInterface $connection,
    ): PgCatalogPostgreSqlCatalogReader {
        return new PgCatalogPostgreSqlCatalogReader($connection);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function indexRow(
        string $index,
        ?string $column,
        array $overrides = [],
    ): object {
        return (object) [
            'table_name' => 'acl_roles',
            'index_name' => $index,
            'is_unique' => false,
            'is_primary' => false,
            'is_valid' => true,
            'is_live' => true,
            'is_partial' => false,
            'has_expressions' => false,
            'constraint_name' => null,
            'column_name' => $column,
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function constraintRow(
        string $name,
        string $type,
        array $overrides = [],
    ): object {
        return (object) [
            'table_name' => 'acl_roles',
            'constraint_name' => $name,
            'constraint_type' => $type,
            'is_validated' => true,
            'is_deferrable' => false,
            'is_deferred' => false,
            'delete_action' => null,
            'update_action' => null,
            'referenced_schema' => null,
            'referenced_table' => null,
            'definition' => null,
            'column_name' => null,
            'referenced_column' => null,
            ...$overrides,
        ];
    }

    private function context(): SchemaContext
    {
        return $this->app->make(SchemaContext::class);
    }

    private function manifest(): SchemaManifest
    {
        return $this->app->make(SchemaManifest::class);
    }
}

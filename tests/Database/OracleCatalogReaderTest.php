<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Database;

use HangTuah\GateTrail\Database\Schema\Inspection\Oracle\AllViewsOracleCatalogReader;
use HangTuah\GateTrail\Database\Schema\Inspection\Oracle\OracleSchemaInspector;
use HangTuah\GateTrail\Database\Schema\Inspection\SchemaInspectorFactory;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\ConnectionInterface;

final class OracleCatalogReaderTest extends TestCase
{
    public function test_catalog_reader_executes_only_read_queries(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->exactly(8))
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
                [(object) ['schema_name' => 'GATETRAIL']],
                [],
                [],
                [],
                [],
                [],
                [],
                [],
            );
        $connection->expects($this->never())->method('statement');
        $connection->expects($this->never())->method('affectingStatement');
        $connection->expects($this->never())->method('unprepared');

        $snapshot = (new AllViewsOracleCatalogReader($connection))
            ->read($this->app->make(SchemaContext::class));

        $this->assertSame('GATETRAIL', $snapshot->owner);
        $this->assertSame([], $snapshot->tables);
        $this->assertSame([], $snapshot->columns);
        $this->assertSame([], $snapshot->indexes);
        $this->assertSame([], $snapshot->constraints);
        $this->assertSame([], $snapshot->identities);
        $this->assertSame([], $snapshot->sequences);
        $this->assertSame([], $snapshot->triggers);
    }

    public function test_catalog_rows_are_normalized_and_grouped(): void
    {
        $this->app['config']->set('gatetrail.database.schema', 'security');
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->exactly(7))
            ->method('select')
            ->willReturnOnConsecutiveCalls(
                [(object) ['table_name' => 'ACL_ROLES']],
                [
                    (object) [
                        'table_name' => 'ACL_ROLES',
                        'column_name' => 'ID',
                        'data_type' => 'NUMBER',
                        'nullable' => 'N',
                        'char_length' => null,
                        'char_used' => null,
                        'data_precision' => '19',
                        'data_scale' => '0',
                        'identity_column' => 'NO',
                        'default_on_null' => 'NO',
                    ],
                ],
                [
                    (object) [
                        'table_name' => 'ACL_ROLES',
                        'index_name' => 'ACL_ROLE_NAME_GUARD_UQ',
                        'uniqueness' => 'UNIQUE',
                        'status' => 'VALID',
                        'column_name' => 'NAME',
                    ],
                    (object) [
                        'table_name' => 'ACL_ROLES',
                        'index_name' => 'ACL_ROLE_NAME_GUARD_UQ',
                        'uniqueness' => 'UNIQUE',
                        'status' => 'VALID',
                        'column_name' => 'GUARD_NAME',
                    ],
                ],
                [
                    (object) [
                        'table_name' => 'ACL_ROLES',
                        'constraint_name' => 'ACL_ROLE_NAME_GUARD_UQ',
                        'constraint_type' => 'U',
                        'column_name' => 'NAME',
                        'referenced_table_name' => null,
                        'referenced_column_name' => null,
                        'delete_rule' => null,
                        'status' => 'ENABLED',
                        'validated' => 'VALIDATED',
                        'search_condition_vc' => null,
                    ],
                    (object) [
                        'table_name' => 'ACL_ROLES',
                        'constraint_name' => 'ACL_ROLE_NAME_GUARD_UQ',
                        'constraint_type' => 'U',
                        'column_name' => 'GUARD_NAME',
                        'referenced_table_name' => null,
                        'referenced_column_name' => null,
                        'delete_rule' => null,
                        'status' => 'ENABLED',
                        'validated' => 'VALIDATED',
                        'search_condition_vc' => null,
                    ],
                ],
                [
                    (object) [
                        'table_name' => 'ACL_ROLES',
                        'column_name' => 'ID',
                        'generation_type' => 'BY DEFAULT',
                    ],
                ],
                [
                    (object) [
                        'sequence_name' => 'ACL_ROLES_SEQ',
                        'increment_by' => '1',
                        'cycle_flag' => 'N',
                    ],
                ],
                [
                    (object) [
                        'trigger_name' => 'ACL_ROLES_TRG',
                        'table_name' => 'ACL_ROLES',
                        'trigger_type' => 'BEFORE EACH ROW',
                        'triggering_event' => 'INSERT',
                        'status' => 'ENABLED',
                        'trigger_body' => ':NEW.ID := ACL_ROLES_SEQ.NEXTVAL;',
                    ],
                ],
            );

        $snapshot = (new AllViewsOracleCatalogReader($connection))
            ->read($this->app->make(SchemaContext::class));

        $this->assertSame('SECURITY', $snapshot->owner);
        $this->assertSame(['ACL_ROLES'], $snapshot->tables);
        $this->assertSame(19, $snapshot->columns[0]->precision);
        $this->assertSame(
            ['NAME', 'GUARD_NAME'],
            $snapshot->indexes[0]->columns,
        );
        $this->assertTrue($snapshot->indexes[0]->unique);
        $this->assertSame(
            ['NAME', 'GUARD_NAME'],
            $snapshot->constraints[0]->columns,
        );
        $this->assertSame(
            'BY DEFAULT',
            $snapshot->identities[0]->generationType,
        );
        $this->assertSame(1, $snapshot->sequences[0]->incrementBy);
        $this->assertSame('ENABLED', $snapshot->triggers[0]->status);
    }

    public function test_factory_builds_the_oracle_inspector(): void
    {
        $inspector = $this->app->make(SchemaInspectorFactory::class)
            ->forDriver(
                'oracle',
                $this->createMock(ConnectionInterface::class),
            );

        $this->assertInstanceOf(OracleSchemaInspector::class, $inspector);
    }
}

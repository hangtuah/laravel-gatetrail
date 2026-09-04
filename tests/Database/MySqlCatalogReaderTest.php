<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Database;

use HangTuah\GateTrail\Database\Schema\Inspection\MySql\InformationSchemaMySqlCatalogReader;
use HangTuah\GateTrail\Database\Schema\Inspection\MySql\MySqlSchemaInspector;
use HangTuah\GateTrail\Database\Schema\Inspection\SchemaInspectorFactory;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Exceptions\SchemaInspectorUnavailable;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\ConnectionInterface;

final class MySqlCatalogReaderTest extends TestCase
{
    public function test_catalog_reader_executes_only_read_queries(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())
            ->method('getDatabaseName')
            ->willReturn('gatetrail');
        $connection->expects($this->exactly(4))
            ->method('select')
            ->with(
                $this->callback(
                    static fn (string $query): bool => str_starts_with(
                        ltrim($query),
                        'SELECT',
                    ),
                ),
                ['gatetrail'],
                true,
            )
            ->willReturn([]);
        $connection->expects($this->never())->method('statement');
        $connection->expects($this->never())->method('affectingStatement');
        $connection->expects($this->never())->method('unprepared');

        $snapshot = (new InformationSchemaMySqlCatalogReader($connection))
            ->read($this->app->make(SchemaContext::class));

        $this->assertSame([], $snapshot->tables);
        $this->assertSame([], $snapshot->columns);
        $this->assertSame([], $snapshot->indexes);
        $this->assertSame([], $snapshot->constraints);
    }

    public function test_catalog_rows_are_normalized_and_grouped(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('getDatabaseName')->willReturn('gatetrail');
        $connection->expects($this->exactly(4))
            ->method('select')
            ->willReturnOnConsecutiveCalls(
                [
                    (object) ['table_name' => 'acl_roles'],
                ],
                [
                    (object) [
                        'table_name' => 'acl_roles',
                        'column_name' => 'name',
                        'data_type' => 'varchar',
                        'column_type' => 'varchar(191)',
                        'is_nullable' => 'NO',
                        'character_maximum_length' => '191',
                        'extra' => '',
                        'collation_name' => 'utf8mb4_bin',
                    ],
                ],
                [
                    (object) [
                        'table_name' => 'acl_roles',
                        'index_name' => 'acl_role_name_guard_uq',
                        'non_unique' => 0,
                        'column_name' => 'name',
                    ],
                    (object) [
                        'table_name' => 'acl_roles',
                        'index_name' => 'acl_role_name_guard_uq',
                        'non_unique' => 0,
                        'column_name' => 'guard_name',
                    ],
                ],
                [
                    (object) [
                        'table_name' => 'acl_roles',
                        'constraint_name' => 'acl_role_name_guard_uq',
                        'constraint_type' => 'UNIQUE',
                        'column_name' => 'name',
                        'referenced_table_name' => null,
                        'referenced_column_name' => null,
                        'delete_rule' => null,
                        'update_rule' => null,
                        'check_clause' => null,
                    ],
                    (object) [
                        'table_name' => 'acl_roles',
                        'constraint_name' => 'acl_role_name_guard_uq',
                        'constraint_type' => 'UNIQUE',
                        'column_name' => 'guard_name',
                        'referenced_table_name' => null,
                        'referenced_column_name' => null,
                        'delete_rule' => null,
                        'update_rule' => null,
                        'check_clause' => null,
                    ],
                ],
            );

        $snapshot = (new InformationSchemaMySqlCatalogReader($connection))
            ->read($this->app->make(SchemaContext::class));

        $this->assertSame(['acl_roles'], $snapshot->tables);
        $this->assertSame(191, $snapshot->columns[0]->length);
        $this->assertSame(
            ['name', 'guard_name'],
            $snapshot->indexes[0]->columns,
        );
        $this->assertTrue($snapshot->indexes[0]->unique);
        $this->assertSame(
            ['name', 'guard_name'],
            $snapshot->constraints[0]->columns,
        );
    }

    public function test_factory_builds_the_mysql_inspector(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);

        $inspector = $this->app->make(SchemaInspectorFactory::class)
            ->forDriver('mysql', $connection);

        $this->assertInstanceOf(MySqlSchemaInspector::class, $inspector);
    }

    public function test_configured_schema_is_used_as_the_catalog_database(): void
    {
        $this->app['config']->set(
            'gatetrail.database.schema',
            'security',
        );
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->never())->method('getDatabaseName');
        $connection->expects($this->exactly(4))
            ->method('select')
            ->with(
                $this->isType('string'),
                ['security'],
                true,
            )
            ->willReturn([]);

        (new InformationSchemaMySqlCatalogReader($connection))
            ->read($this->app->make(SchemaContext::class));
    }

    public function test_factory_rejects_an_unavailable_driver(): void
    {
        $this->expectException(SchemaInspectorUnavailable::class);
        $this->expectExceptionMessage(
            'GateTrail does not yet provide a schema inspector for [sqlsrv].',
        );

        $this->app->make(SchemaInspectorFactory::class)->forDriver(
            'sqlsrv',
            $this->createMock(ConnectionInterface::class),
        );
    }
}

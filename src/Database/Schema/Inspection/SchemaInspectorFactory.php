<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Inspection;

use HangTuah\GateTrail\Database\Schema\Inspection\MySql\InformationSchemaMySqlCatalogReader;
use HangTuah\GateTrail\Database\Schema\Inspection\MySql\MySqlSchemaInspector;
use HangTuah\GateTrail\Database\Schema\Inspection\Oracle\AllViewsOracleCatalogReader;
use HangTuah\GateTrail\Database\Schema\Inspection\Oracle\OracleSchemaInspector;
use HangTuah\GateTrail\Database\Schema\Inspection\PostgreSql\PgCatalogPostgreSqlCatalogReader;
use HangTuah\GateTrail\Database\Schema\Inspection\PostgreSql\PostgreSqlSchemaInspector;
use HangTuah\GateTrail\Exceptions\SchemaInspectorUnavailable;
use Illuminate\Database\ConnectionInterface;

/**
 * @internal
 */
final class SchemaInspectorFactory
{
    public function forDriver(
        string $driver,
        ConnectionInterface $connection,
    ): SchemaInspector {
        return match ($driver) {
            'mysql' => new MySqlSchemaInspector(
                new InformationSchemaMySqlCatalogReader($connection),
            ),
            'oracle' => new OracleSchemaInspector(
                new AllViewsOracleCatalogReader($connection),
            ),
            'pgsql' => new PostgreSqlSchemaInspector(
                new PgCatalogPostgreSqlCatalogReader($connection),
            ),
            default => throw SchemaInspectorUnavailable::forDriver($driver),
        };
    }
}

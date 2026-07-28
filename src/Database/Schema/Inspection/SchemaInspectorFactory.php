<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Inspection;

use HangTuah\GateTrail\Database\Schema\Inspection\MySql\InformationSchemaMySqlCatalogReader;
use HangTuah\GateTrail\Database\Schema\Inspection\MySql\MySqlSchemaInspector;
use HangTuah\GateTrail\Exceptions\SchemaInspectorUnavailable;
use Illuminate\Database\ConnectionInterface;

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
            default => throw SchemaInspectorUnavailable::forDriver($driver),
        };
    }
}

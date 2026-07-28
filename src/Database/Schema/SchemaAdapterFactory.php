<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema;

use HangTuah\GateTrail\Database\Schema\Adapters\MySqlAdapter;
use HangTuah\GateTrail\Database\Schema\Adapters\OracleAdapter;
use HangTuah\GateTrail\Database\Schema\Adapters\PostgreSqlAdapter;
use HangTuah\GateTrail\Database\Schema\Adapters\SQLiteAdapter;
use HangTuah\GateTrail\Database\Schema\Adapters\SqlServerAdapter;
use HangTuah\GateTrail\Exceptions\UnsupportedDatabaseDriver;

final class SchemaAdapterFactory
{
    public function forDriver(string $driver): SchemaAdapter
    {
        return match ($driver) {
            'sqlite' => new SQLiteAdapter,
            'mysql', 'mariadb' => new MySqlAdapter,
            'pgsql' => new PostgreSqlAdapter,
            'sqlsrv' => new SqlServerAdapter,
            'oracle', 'oci8' => new OracleAdapter,
            default => throw UnsupportedDatabaseDriver::named($driver),
        };
    }
}

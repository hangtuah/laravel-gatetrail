<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Database;

use HangTuah\GateTrail\Database\Schema\SchemaAdapterFactory;
use HangTuah\GateTrail\Exceptions\UnsupportedDatabaseDriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SchemaAdapterFactoryTest extends TestCase
{
    #[DataProvider('drivers')]
    public function test_it_resolves_supported_drivers(
        string $driver,
        string $expected,
    ): void {
        $adapter = (new SchemaAdapterFactory)->forDriver($driver);

        $this->assertSame($expected, $adapter->driver());
    }

    public function test_primary_key_naming_follows_adapter_capabilities(): void
    {
        $factory = new SchemaAdapterFactory;

        $this->assertNull(
            $factory->forDriver('sqlite')->physicalPrimaryKeyName('acl_roles_pk'),
        );
        $this->assertSame(
            'PRIMARY',
            $factory->forDriver('mysql')->physicalPrimaryKeyName('acl_roles_pk'),
        );
        $this->assertSame(
            'acl_roles_pk',
            $factory->forDriver('pgsql')->physicalPrimaryKeyName('acl_roles_pk'),
        );
    }

    public function test_it_rejects_an_unsupported_driver(): void
    {
        $this->expectException(UnsupportedDatabaseDriver::class);

        (new SchemaAdapterFactory)->forDriver('mongodb');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function drivers(): array
    {
        return [
            'SQLite' => ['sqlite', 'sqlite'],
            'MySQL' => ['mysql', 'mysql'],
            'MariaDB' => ['mariadb', 'mysql'],
            'PostgreSQL' => ['pgsql', 'pgsql'],
            'SQL Server' => ['sqlsrv', 'sqlsrv'],
            'Oracle' => ['oracle', 'oracle'],
            'Laravel OCI8' => ['oci8', 'oracle'],
        ];
    }
}

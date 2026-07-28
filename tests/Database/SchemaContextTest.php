<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Database;

use HangTuah\GateTrail\Database\Schema\IdentifierValidator;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Exceptions\InvalidDatabaseIdentifier;
use PHPUnit\Framework\TestCase;

final class SchemaContextTest extends TestCase
{
    public function test_it_resolves_schema_prefix_and_table_name(): void
    {
        $context = new SchemaContext(
            $this->config([
                'schema' => 'security',
                'prefix' => 'app_',
            ]),
            new IdentifierValidator,
        );

        $this->assertSame(
            'security.app_acl_roles',
            $context->table('roles'),
        );
    }

    public function test_it_validates_every_configured_table_before_ddl(): void
    {
        $config = $this->config();
        $config['tables']['role_assignments'] = str_repeat('a', 31);

        $context = new SchemaContext($config, new IdentifierValidator);

        $this->expectException(InvalidDatabaseIdentifier::class);
        $this->expectExceptionMessage('table [role_assignments]');

        $context->validateAll();
    }

    /**
     * @param  array<string, mixed>  $database
     * @return array<string, mixed>
     */
    private function config(array $database = []): array
    {
        return [
            'database' => array_replace([
                'connection' => null,
                'schema' => null,
                'prefix' => null,
                'provisioning' => 'package',
            ], $database),
            'tables' => [
                'roles' => 'acl_roles',
                'permissions' => 'acl_permissions',
                'role_assignments' => 'acl_role_assignments',
                'permission_assignments' => 'acl_permission_assignments',
                'role_permissions' => 'acl_role_permissions',
                'assignment_locks' => 'acl_assignment_locks',
                'access_audits' => 'acl_access_audits',
            ],
        ];
    }
}

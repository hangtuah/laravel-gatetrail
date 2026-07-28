<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Database;

use HangTuah\GateTrail\Database\Schema\Ddl\Oracle\OracleGeneratedKeyStrategy;
use HangTuah\GateTrail\Database\Schema\IdentifierValidator;
use HangTuah\GateTrail\Database\Schema\Manifest\SchemaManifest;
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
            SchemaManifest::gateTrail(),
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

        $context = new SchemaContext(
            $config,
            new IdentifierValidator,
            SchemaManifest::gateTrail(),
        );

        $this->expectException(InvalidDatabaseIdentifier::class);
        $this->expectExceptionMessage('table [role_assignments]');

        $context->validateAll();
    }

    public function test_it_resolves_the_default_oracle_generated_key_strategy(): void
    {
        $context = new SchemaContext(
            $this->config(),
            new IdentifierValidator,
            SchemaManifest::gateTrail(),
        );

        $this->assertSame(
            OracleGeneratedKeyStrategy::SequenceTrigger,
            $context->oracleOptions()->generatedKeys,
        );
    }

    public function test_it_rejects_an_invalid_oracle_generated_key_strategy(): void
    {
        $config = $this->config();
        $config['oracle']['generated_keys'] = 'automatic';
        $context = new SchemaContext(
            $config,
            new IdentifierValidator,
            SchemaManifest::gateTrail(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'GateTrail oracle.generated_keys must be [identity] or [sequence_trigger].',
        );

        $context->oracleOptions();
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
            'oracle' => [
                'generated_keys' => 'sequence_trigger',
                'sequences' => [],
                'triggers' => [],
            ],
        ];
    }
}

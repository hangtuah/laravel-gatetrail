<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Database;

use HangTuah\GateTrail\Database\Schema\Ddl\DdlCompiler;
use HangTuah\GateTrail\Database\Schema\Ddl\DdlCompilerFactory;
use HangTuah\GateTrail\Database\Schema\Manifest\SchemaManifest;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Exceptions\DdlCompilerUnavailable;
use HangTuah\GateTrail\Tests\TestCase;

final class MySqlDdlCompilerTest extends TestCase
{
    public function test_install_ddl_follows_manifest_dependency_order(): void
    {
        $statements = $this->compiler()->compileInstall(
            $this->manifest(),
            $this->context(),
        );

        $this->assertCount(7, $statements);
        $this->assertStringContainsString(
            'CREATE TABLE `acl_roles`',
            $statements[0],
        );
        $this->assertStringContainsString(
            'CREATE TABLE `acl_permissions`',
            $statements[1],
        );
        $this->assertStringContainsString(
            'CREATE TABLE `acl_role_permissions`',
            $statements[5],
        );
        $this->assertStringContainsString(
            'REFERENCES `acl_roles` (`id`)',
            $statements[5],
        );
        $this->assertStringContainsString(
            'REFERENCES `acl_permissions` (`id`)',
            $statements[5],
        );
    }

    public function test_mysql_ddl_uses_engine_native_primary_key_name(): void
    {
        $roles = $this->compiler()->compileInstall(
            $this->manifest(),
            $this->context(),
        )[0];

        $this->assertStringContainsString('PRIMARY KEY (`id`)', $roles);
        $this->assertStringNotContainsString('acl_roles_pk', $roles);
    }

    public function test_mysql_ddl_contains_exact_collation_and_history_safety(): void
    {
        $statements = $this->compiler()->compileInstall(
            $this->manifest(),
            $this->context(),
        );
        $roleAssignments = $statements[3];

        $this->assertStringContainsString(
            '`assigned_by_id` VARCHAR(191) COLLATE utf8mb4_bin NOT NULL',
            $roleAssignments,
        );
        $this->assertStringContainsString(
            'CONSTRAINT `acl_ra_period_ck` CHECK',
            $roleAssignments,
        );
        $this->assertStringContainsString(
            'ON DELETE RESTRICT ON UPDATE RESTRICT',
            $roleAssignments,
        );
        $this->assertStringNotContainsString('CASCADE', $roleAssignments);
    }

    public function test_rollback_ddl_is_in_reverse_dependency_order(): void
    {
        $statements = $this->compiler()->compileRollback(
            $this->manifest(),
            $this->context(),
        );

        $this->assertSame(
            'DROP TABLE `acl_access_audits`;',
            $statements[0],
        );
        $this->assertSame(
            'DROP TABLE `acl_roles`;',
            $statements[6],
        );
    }

    public function test_schema_and_prefix_are_quoted_per_identifier(): void
    {
        $this->app['config']->set('gatetrail.database.schema', 'security');
        $this->app['config']->set('gatetrail.database.prefix', 'app_');

        $statements = $this->compiler()->compileInstall(
            $this->manifest(),
            $this->context(),
        );

        $this->assertStringContainsString(
            'CREATE TABLE `security`.`app_acl_roles`',
            $statements[0],
        );
    }

    public function test_factory_rejects_a_driver_without_a_compiler(): void
    {
        $this->expectException(DdlCompilerUnavailable::class);
        $this->expectExceptionMessage(
            'GateTrail does not yet provide a DBA DDL compiler for [sqlite].',
        );

        $this->app->make(DdlCompilerFactory::class)
            ->forDriver('sqlite');
    }

    private function compiler(): DdlCompiler
    {
        return $this->app->make(DdlCompilerFactory::class)
            ->forDriver('mysql');
    }

    private function manifest(): SchemaManifest
    {
        return $this->app->make(SchemaManifest::class);
    }

    private function context(): SchemaContext
    {
        return $this->app->make(SchemaContext::class);
    }
}

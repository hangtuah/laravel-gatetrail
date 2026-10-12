<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Console;

use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command;

final class DdlCommandTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir()
            .'/gatetrail-ddl-'
            .bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach (['install.sql', 'rollback.sql'] as $filename) {
            $path = $this->temporaryDirectory.'/'.$filename;

            if (is_file($path)) {
                unlink($path);
            }
        }

        if (is_dir($this->temporaryDirectory)) {
            rmdir($this->temporaryDirectory);
        }

        parent::tearDown();
    }

    public function test_command_is_registered_in_console_context(): void
    {
        $this->assertArrayHasKey('gatetrail:ddl', Artisan::all());
    }

    public function test_oracle_artifacts_use_the_resolved_consumer_configuration(): void
    {
        config()->set('gatetrail.tables', [
            'roles' => 'gt_roles',
            'permissions' => 'gt_permissions',
            'assignment_locks' => 'gt_assignment_locks',
            'role_assignments' => 'gt_role_assignments',
            'permission_assignments' => 'gt_permission_assignments',
            'role_permissions' => 'gt_role_permissions',
            'access_audits' => 'gt_access_audits',
        ]);
        config()->set('gatetrail.oracle.generated_keys', 'sequence_trigger');
        config()->set('gatetrail.oracle.sequences', [
            'roles' => 'gt_roles_seq',
            'permissions' => 'gt_permissions_seq',
            'role_assignments' => 'gt_role_asgn_seq',
            'permission_assignments' => 'gt_perm_asgn_seq',
            'role_permissions' => 'gt_role_perm_seq',
            'access_audits' => 'gt_access_audit_seq',
        ]);
        config()->set('gatetrail.oracle.triggers', [
            'roles' => 'gt_roles_trg',
            'permissions' => 'gt_permissions_trg',
            'role_assignments' => 'gt_role_asgn_trg',
            'permission_assignments' => 'gt_perm_asgn_trg',
            'role_permissions' => 'gt_role_perm_trg',
            'access_audits' => 'gt_access_audit_trg',
        ]);

        $exit = Artisan::call('gatetrail:ddl', [
            '--driver' => 'oracle',
            '--output' => $this->temporaryDirectory,
        ]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertFileExists($this->temporaryDirectory.'/install.sql');
        $this->assertFileExists($this->temporaryDirectory.'/rollback.sql');

        $install = file_get_contents($this->temporaryDirectory.'/install.sql');
        $rollback = file_get_contents($this->temporaryDirectory.'/rollback.sql');

        $this->assertIsString($install);
        $this->assertIsString($rollback);
        $this->assertStringContainsString('-- Database driver: oracle', $install);
        $this->assertStringContainsString(
            '-- Generated-key strategy: sequence_trigger',
            $install,
        );
        $this->assertStringContainsString('CREATE TABLE gt_roles', $install);
        $this->assertStringContainsString('CREATE TABLE gt_permissions', $install);
        $this->assertStringContainsString('CREATE SEQUENCE gt_roles_seq', $install);
        $this->assertStringContainsString('CREATE TRIGGER gt_roles_trg', $install);
        $this->assertStringNotContainsString('CREATE TABLE acl_', $install);
        $this->assertStringNotContainsString('CREATE SEQUENCE acl_', $install);
        $this->assertStringNotContainsString('CREATE TRIGGER acl_', $install);
        $this->assertStringContainsString('DROP TABLE gt_roles', $rollback);
        $this->assertStringContainsString(
            'this command did not connect to a database',
            Artisan::output(),
        );
    }

    public function test_existing_artifacts_are_not_overwritten_without_force(): void
    {
        mkdir($this->temporaryDirectory, 0700, true);
        file_put_contents($this->temporaryDirectory.'/install.sql', 'sentinel install');
        file_put_contents($this->temporaryDirectory.'/rollback.sql', 'sentinel rollback');

        $exit = Artisan::call('gatetrail:ddl', [
            '--driver' => 'mysql',
            '--output' => $this->temporaryDirectory,
        ]);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertSame(
            'sentinel install',
            file_get_contents($this->temporaryDirectory.'/install.sql'),
        );
        $this->assertSame(
            'sentinel rollback',
            file_get_contents($this->temporaryDirectory.'/rollback.sql'),
        );
        $this->assertStringContainsString('use --force', Artisan::output());
    }

    public function test_force_overwrites_both_existing_artifacts(): void
    {
        mkdir($this->temporaryDirectory, 0700, true);
        file_put_contents($this->temporaryDirectory.'/install.sql', 'sentinel install');
        file_put_contents($this->temporaryDirectory.'/rollback.sql', 'sentinel rollback');

        $exit = Artisan::call('gatetrail:ddl', [
            '--driver' => 'mysql',
            '--output' => $this->temporaryDirectory,
            '--force' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringStartsWith(
            '-- GateTrail schema release 0001',
            (string) file_get_contents($this->temporaryDirectory.'/install.sql'),
        );
        $this->assertStringStartsWith(
            '-- GateTrail schema release 0001',
            (string) file_get_contents($this->temporaryDirectory.'/rollback.sql'),
        );
    }

    public function test_missing_output_fails_without_creating_a_directory(): void
    {
        $exit = Artisan::call('gatetrail:ddl', [
            '--driver' => 'mysql',
        ]);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertDirectoryDoesNotExist($this->temporaryDirectory);
        $this->assertStringContainsString(
            'requires an explicit --output directory',
            Artisan::output(),
        );
    }

    public function test_invalid_configuration_fails_before_creating_output(): void
    {
        config()->set('gatetrail.tables.roles', 'invalid-name');

        $exit = Artisan::call('gatetrail:ddl', [
            '--driver' => 'mysql',
            '--output' => $this->temporaryDirectory,
        ]);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertDirectoryDoesNotExist($this->temporaryDirectory);
        $this->assertStringContainsString(
            'only ASCII letters, digits, and underscores are portable',
            Artisan::output(),
        );
    }

    public function test_driver_can_be_inferred_without_a_database_connection(): void
    {
        config()->set('gatetrail.database.connection', 'ddl_target');
        config()->set('database.connections.ddl_target', [
            'driver' => 'pgsql',
        ]);
        $statements = [];
        DB::listen(static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $exit = Artisan::call('gatetrail:ddl', [
            '--output' => $this->temporaryDirectory,
        ]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertSame([], $statements);
        $this->assertStringContainsString(
            '-- Database driver: pgsql',
            (string) file_get_contents($this->temporaryDirectory.'/install.sql'),
        );
    }
}

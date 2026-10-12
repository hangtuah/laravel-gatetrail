<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Console;

use HangTuah\GateTrail\GateTrailServiceProvider;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Console\Command\Command;

final class InstallCommandTest extends TestCase
{
    /** @var array<class-string, array<string, string>> */
    private array $originalPublishes;

    /** @var array<string, array<string, string>> */
    private array $originalPublishGroups;

    private string $temporaryDirectory;

    private string $publishedConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalPublishes = ServiceProvider::$publishes;
        $this->originalPublishGroups = ServiceProvider::$publishGroups;
        $this->temporaryDirectory = sys_get_temp_dir().'/gatetrail-install-'.bin2hex(random_bytes(8));
        $this->publishedConfig = $this->temporaryDirectory.'/gatetrail.php';

        mkdir($this->temporaryDirectory, 0700, true);

        $source = realpath(__DIR__.'/../../config/gatetrail.php');
        $this->assertIsString($source);

        ServiceProvider::$publishes[GateTrailServiceProvider::class] = [
            $source => $this->publishedConfig,
        ];
        ServiceProvider::$publishGroups['gatetrail-config'] = [
            $source => $this->publishedConfig,
        ];
    }

    protected function tearDown(): void
    {
        ServiceProvider::$publishes = $this->originalPublishes;
        ServiceProvider::$publishGroups = $this->originalPublishGroups;

        if (is_file($this->publishedConfig)) {
            unlink($this->publishedConfig);
        }

        if (is_dir($this->temporaryDirectory)) {
            rmdir($this->temporaryDirectory);
        }

        parent::tearDown();
    }

    public function test_command_is_registered_in_console_context(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('gatetrail:install', $commands);
    }

    public function test_no_migrate_publishes_config_without_creating_schema(): void
    {
        $exit = Artisan::call('gatetrail:install', ['--no-migrate' => true]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertFileExists($this->publishedConfig);
        $this->assertFalse(Schema::hasTable('acl_roles'));
        $this->assertStringContainsString(
            'package migrations were not run',
            Artisan::output(),
        );
    }

    public function test_existing_published_config_is_never_overwritten(): void
    {
        file_put_contents($this->publishedConfig, "<?php return ['sentinel' => true];\n");

        $exit = Artisan::call('gatetrail:install', ['--no-migrate' => true]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertSame(
            "<?php return ['sentinel' => true];\n",
            file_get_contents($this->publishedConfig),
        );
    }

    public function test_package_mode_runs_only_gatetrail_migrations(): void
    {
        $exit = Artisan::call('gatetrail:install');

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertFileExists($this->publishedConfig);

        foreach ([
            'acl_roles',
            'acl_permissions',
            'acl_assignment_locks',
            'acl_role_assignments',
            'acl_permission_assignments',
            'acl_role_permissions',
            'acl_access_audits',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table.' was not created.');
        }

        $recorded = DB::table('migrations')
            ->orderBy('migration')
            ->pluck('migration')
            ->all();
        $expected = array_map(
            static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME),
            glob(__DIR__.'/../../database/migrations/*.php') ?: [],
        );

        $this->assertSame($expected, $recorded);
        $this->assertStringContainsString('GateTrail is installed', Artisan::output());
    }

    public function test_dba_mode_never_runs_package_migrations(): void
    {
        $this->app->make('config')->set('gatetrail.database.provisioning', 'dba');

        $exit = Artisan::call('gatetrail:install');

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertFileExists($this->publishedConfig);
        $this->assertFalse(Schema::hasTable('acl_roles'));
        $this->assertStringContainsString(
            'DBA-managed provisioning is enabled',
            Artisan::output(),
        );
    }

    public function test_invalid_schema_configuration_fails_before_publication(): void
    {
        $this->app->make('config')->set(
            'gatetrail.database.provisioning',
            'automatic',
        );

        $exit = Artisan::call('gatetrail:install');

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertFileDoesNotExist($this->publishedConfig);
        $this->assertStringContainsString(
            'must be [package] or [dba]',
            Artisan::output(),
        );
    }
}

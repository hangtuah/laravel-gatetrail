<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Console;

use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\GateTrailServiceProvider;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * @internal
 */
final class InstallCommand extends Command
{
    protected $signature = 'gatetrail:install
        {--no-migrate : Publish configuration without running package-managed migrations}
        {--force : Force package-managed migrations to run in production}';

    protected $description = 'Publish GateTrail configuration and provision its package-managed schema';

    public function __construct(
        private readonly SchemaContext $schema,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $provisioning = $this->schema->provisioning();
            $this->schema->connectionName();
            $this->schema->validateAll();
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $publishResult = $this->call('vendor:publish', [
            '--provider' => GateTrailServiceProvider::class,
            '--tag' => ['gatetrail-config'],
        ]);

        if ($publishResult !== self::SUCCESS) {
            $this->components->error('GateTrail configuration could not be published.');

            return self::FAILURE;
        }

        if ($provisioning === 'dba') {
            $this->components->warn(
                'DBA-managed provisioning is enabled; no GateTrail migration was run.',
            );
            $this->line(
                'Apply and verify the matching versioned artifact under database/ddl before enabling traffic.',
            );

            return self::SUCCESS;
        }

        if ((bool) $this->option('no-migrate')) {
            $this->components->warn(
                'GateTrail configuration is ready; package migrations were not run.',
            );

            return self::SUCCESS;
        }

        $migrationPath = realpath(__DIR__.'/../../database/migrations');

        if ($migrationPath === false) {
            $this->components->error('GateTrail migration files could not be located.');

            return self::FAILURE;
        }

        $migrationResult = $this->call('migrate', [
            '--path' => [$migrationPath],
            '--realpath' => true,
            '--force' => (bool) $this->option('force'),
        ]);

        if ($migrationResult !== self::SUCCESS) {
            $this->components->error('GateTrail package migrations did not complete.');

            return self::FAILURE;
        }

        $this->components->info('GateTrail is installed.');

        return self::SUCCESS;
    }
}

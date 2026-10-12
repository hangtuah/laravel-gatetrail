<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Console;

use HangTuah\GateTrail\Database\Schema\Ddl\DdlArtifactGenerator;
use HangTuah\GateTrail\Database\Schema\Manifest\SchemaManifest;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * @internal
 */
final class DdlCommand extends Command
{
    protected $signature = 'gatetrail:ddl
        {--driver= : Laravel database driver: mysql, oracle, pgsql or sqlsrv}
        {--output= : Directory that will receive install.sql and rollback.sql}
        {--force : Overwrite existing install.sql and rollback.sql files}';

    protected $description = 'Generate reviewed DBA DDL artifacts from the resolved GateTrail configuration';

    public function __construct(
        private readonly DdlArtifactGenerator $artifacts,
        private readonly SchemaManifest $manifest,
        private readonly SchemaContext $schema,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $driver = $this->driver();
            $directory = $this->outputDirectory();
            $strategy = $driver === 'oracle'
                ? $this->schema->oracleOptions()->generatedKeys->value
                : null;

            $this->schema->connectionName();
            $this->schema->validateAll();

            $contents = [
                'install.sql' => $this->artifacts->install(
                    $driver,
                    $this->manifest,
                    $this->schema,
                    $strategy,
                ),
                'rollback.sql' => $this->artifacts->rollback(
                    $driver,
                    $this->manifest,
                    $this->schema,
                    $strategy,
                ),
            ];

            $this->writeArtifacts($directory, $contents);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('GateTrail DBA artifacts generated.');
        $this->line(sprintf('Driver: %s', $driver));

        if ($strategy !== null) {
            $this->line(sprintf('Generated-key strategy: %s', $strategy));
        }

        $this->line(sprintf('Output: %s', $directory));
        $this->components->warn(
            'Review both files before a database owner applies them; this command did not connect to a database.',
        );

        return self::SUCCESS;
    }

    private function driver(): string
    {
        $option = $this->option('driver');

        if ($option !== null && (! is_string($option) || $option === '')) {
            throw new InvalidArgumentException(
                'GateTrail DDL driver must be a non-empty string.',
            );
        }

        if (is_string($option)) {
            return $option;
        }

        $connection = $this->schema->connectionName()
            ?? config('database.default');

        if (! is_string($connection) || $connection === '') {
            throw new InvalidArgumentException(
                'GateTrail cannot infer a DDL driver without a configured database connection.',
            );
        }

        $driver = config(sprintf('database.connections.%s.driver', $connection));

        if (! is_string($driver) || $driver === '') {
            throw new InvalidArgumentException(sprintf(
                'GateTrail database connection [%s] has no valid driver.',
                $connection,
            ));
        }

        return $driver;
    }

    private function outputDirectory(): string
    {
        $output = $this->option('output');

        if (! is_string($output) || trim($output) === '') {
            throw new InvalidArgumentException(
                'GateTrail DDL generation requires an explicit --output directory.',
            );
        }

        $output = rtrim($output, '\\/');

        if ($output === '') {
            throw new InvalidArgumentException(
                'GateTrail DDL output directory must not be a filesystem root.',
            );
        }

        if ($this->isAbsolutePath($output)) {
            return $output;
        }

        return base_path($output);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    /**
     * @param  array<string, string>  $contents
     */
    private function writeArtifacts(string $directory, array $contents): void
    {
        if (file_exists($directory) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf(
                'GateTrail DDL output [%s] exists and is not a directory.',
                $directory,
            ));
        }

        $paths = [];

        foreach (array_keys($contents) as $filename) {
            $path = $directory.DIRECTORY_SEPARATOR.$filename;
            $paths[$filename] = $path;

            if (file_exists($path) && ! (bool) $this->option('force')) {
                throw new RuntimeException(sprintf(
                    'GateTrail DDL artifact [%s] already exists; use --force to overwrite both artifacts.',
                    $path,
                ));
            }
        }

        if (! is_dir($directory) && ! mkdir($directory, 0755, true)) {
            throw new RuntimeException(sprintf(
                'GateTrail DDL output directory [%s] could not be created.',
                $directory,
            ));
        }

        foreach ($contents as $filename => $artifact) {
            if (file_put_contents($paths[$filename], $artifact, LOCK_EX) === false) {
                throw new RuntimeException(sprintf(
                    'GateTrail DDL artifact [%s] could not be written.',
                    $paths[$filename],
                ));
            }
        }
    }
}

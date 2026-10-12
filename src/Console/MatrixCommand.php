<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Console;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;

/**
 * @internal
 */
final class MatrixCommand extends Command
{
    protected $signature = 'gatetrail:matrix
        {--json : Emit stable machine-readable JSON output}';

    protected $description = 'Show GateTrail compatibility targets and recorded evidence';

    /**
     * @var list<array{
     *     engine: string,
     *     driver: string,
     *     target: string,
     *     evidence: string,
     *     verified: string,
     *     runtime_verified: bool,
     *     production: bool,
     *     extensions: list<string>
     * }>
     */
    private const DATABASES = [
        [
            'engine' => 'SQLite',
            'driver' => 'sqlite',
            'target' => 'Current supported version',
            'evidence' => 'Full behavioural suite on Laravel 12 and 13',
            'verified' => 'CI test database',
            'runtime_verified' => true,
            'production' => false,
            'extensions' => ['pdo_sqlite'],
        ],
        [
            'engine' => 'MySQL',
            'driver' => 'mysql',
            'target' => '8.0.16 or later',
            'evidence' => 'Live behavioural matrix on Laravel 12 and 13',
            'verified' => '8.0.16 and 8.0.45',
            'runtime_verified' => true,
            'production' => true,
            'extensions' => ['pdo_mysql'],
        ],
        [
            'engine' => 'Oracle Database',
            'driver' => 'oracle',
            'target' => '12c Release 2 or later',
            'evidence' => 'Targeted live matrix on Laravel 12 and 13',
            'verified' => 'Oracle Free 23.26.1; declared minimum not live-tested',
            'runtime_verified' => true,
            'production' => true,
            'extensions' => ['oci8'],
        ],
        [
            'engine' => 'PostgreSQL',
            'driver' => 'pgsql',
            'target' => '14 or later',
            'evidence' => 'Live DDL and schema inspection only',
            'verified' => 'PostgreSQL 14 schema; application runtime unverified',
            'runtime_verified' => false,
            'production' => true,
            'extensions' => ['pdo_pgsql'],
        ],
        [
            'engine' => 'SQL Server',
            'driver' => 'sqlsrv',
            'target' => '2017 or later',
            'evidence' => 'Live DBA DDL smoke test only',
            'verified' => 'SQL Server 2022 schema; application runtime unverified',
            'runtime_verified' => false,
            'production' => true,
            'extensions' => ['sqlsrv', 'pdo_sqlsrv'],
        ],
    ];

    public function __construct(
        private readonly Application $app,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $report = $this->report();

        if ((bool) $this->option('json')) {
            $this->line(json_encode(
                $report,
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));

            return $report['exit_code'];
        }

        $this->components->info('GateTrail compatibility matrix');
        $this->line(sprintf(
            'PHP %s [%s]; Laravel %s [%s]; GateTrail %s',
            $report['runtime']['php']['version'],
            $report['runtime']['php']['supported'] ? 'supported' : 'unsupported',
            $report['runtime']['laravel']['version'],
            $report['runtime']['laravel']['supported'] ? 'supported' : 'unsupported',
            $report['runtime']['gatetrail'],
        ));
        $this->line(sprintf(
            'Configured database: %s (%s) [%s]',
            $report['configured_database']['engine'],
            $report['configured_database']['connection'],
            $report['configured_database']['ready'] ? 'driver ready' : 'not ready',
        ));

        $this->table(
            ['Engine', 'Target', 'Recorded evidence', 'Runtime claim'],
            array_map(
                static fn (array $database): array => [
                    $database['engine'],
                    $database['target'],
                    $database['evidence'].'; '.$database['verified'],
                    $database['runtime_verified']
                        ? ($database['production'] ? 'verified' : 'test only')
                        : 'unverified',
                ],
                $report['databases'],
            ),
        );

        if (! $report['configured_database']['ready']) {
            $missing = $report['configured_database']['missing_extensions'];
            $reason = $missing === []
                ? 'the configured driver is not in the GateTrail matrix'
                : 'missing PHP extensions: '.implode(', ', $missing);

            $this->components->error(
                'Configured database is not ready: '.$reason.'.',
            );
        }

        $this->components->warn($report['limitation']);

        return $report['exit_code'];
    }

    /**
     * @return array{
     *     command: string,
     *     status: string,
     *     exit_code: int,
     *     runtime: array{
     *         php: array{version: string, constraint: string, supported: bool},
     *         laravel: array{version: string, constraint: string, supported: bool},
     *         gatetrail: string
     *     },
     *     configured_database: array{
     *         connection: string,
     *         driver: string,
     *         engine: string,
     *         ready: bool,
     *         required_extensions: list<string>,
     *         missing_extensions: list<string>
     *     },
     *     databases: list<array{
     *         engine: string,
     *         driver: string,
     *         target: string,
     *         evidence: string,
     *         verified: string,
     *         runtime_verified: bool,
     *         production: bool,
     *         extensions: list<string>
     *     }>,
     *     limitation: string
     * }
     */
    private function report(): array
    {
        $laravel = $this->app->version();
        $laravelMajor = (int) explode('.', $laravel)[0];
        $phpSupported = $this->supportsPhp(PHP_VERSION);
        $laravelSupported = in_array($laravelMajor, [12, 13], true);
        $connection = config('gatetrail.database.connection');
        $connection = is_string($connection) && $connection !== ''
            ? $connection
            : config('database.default');
        $connection = is_string($connection) && $connection !== ''
            ? $connection
            : 'unresolved';
        $driver = config('database.connections.'.$connection.'.driver');
        $driver = is_string($driver) && $driver !== ''
            ? $this->normalizeDriver($driver)
            : 'unresolved';
        $database = $this->databaseFor($driver);
        $requiredExtensions = $database['extensions'] ?? [];
        $missingExtensions = array_values(array_filter(
            $requiredExtensions,
            static fn (string $extension): bool => ! extension_loaded($extension),
        ));
        $databaseReady = $database !== null && $missingExtensions === [];
        $ready = $phpSupported && $laravelSupported && $databaseReady;

        return [
            'command' => 'gatetrail:matrix',
            'status' => $ready ? 'ready' : 'not_ready',
            'exit_code' => $ready ? self::SUCCESS : self::FAILURE,
            'runtime' => [
                'php' => [
                    'version' => PHP_VERSION,
                    'constraint' => '^8.3',
                    'supported' => $phpSupported,
                ],
                'laravel' => [
                    'version' => $laravel,
                    'constraint' => '^12.0 || ^13.0',
                    'supported' => $laravelSupported,
                ],
                'gatetrail' => $this->gateTrailVersion(),
            ],
            'configured_database' => [
                'connection' => $connection,
                'driver' => $driver,
                'engine' => $database['engine'] ?? 'Unknown',
                'ready' => $databaseReady,
                'required_extensions' => $requiredExtensions,
                'missing_extensions' => $missingExtensions,
            ],
            'databases' => self::DATABASES,
            'limitation' => self::limitation(),
        ];
    }

    private function normalizeDriver(string $driver): string
    {
        return match (strtolower($driver)) {
            'oci', 'oci8' => 'oracle',
            'postgres', 'postgresql' => 'pgsql',
            'mssql' => 'sqlsrv',
            default => strtolower($driver),
        };
    }

    private function supportsPhp(string $version): bool
    {
        return version_compare($version, '8.3.0', '>=')
            && version_compare($version, '9.0.0', '<');
    }

    /**
     * @return array{
     *     engine: string,
     *     driver: string,
     *     target: string,
     *     evidence: string,
     *     verified: string,
     *     runtime_verified: bool,
     *     production: bool,
     *     extensions: list<string>
     * }|null
     */
    private function databaseFor(string $driver): ?array
    {
        foreach (self::DATABASES as $database) {
            if ($database['driver'] === $driver) {
                return $database;
            }
        }

        return null;
    }

    private function gateTrailVersion(): string
    {
        if (! class_exists(InstalledVersions::class)) {
            return 'unreleased';
        }

        return InstalledVersions::getPrettyVersion(
            'hangtuah/laravel-gatetrail',
        ) ?? 'unreleased';
    }

    private static function limitation(): string
    {
        return 'This command reports declared compatibility, local driver availability, and recorded project evidence; it does not connect to a database or verify its server version.';
    }
}

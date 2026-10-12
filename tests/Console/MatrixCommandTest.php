<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Console;

use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command;

final class MatrixCommandTest extends TestCase
{
    public function test_command_is_registered_in_console_context(): void
    {
        $this->assertArrayHasKey('gatetrail:matrix', Artisan::all());
    }

    public function test_human_output_reports_runtime_targets_evidence_and_limit(): void
    {
        $exit = Artisan::call('gatetrail:matrix');
        $output = Artisan::output();

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('GateTrail compatibility matrix', $output);
        $this->assertStringContainsString('MySQL', $output);
        $this->assertStringContainsString('8.0.16 or later', $output);
        $this->assertStringContainsString('Oracle Free 23.26.1', $output);
        $this->assertStringContainsString('application runtime unverified', $output);
        $this->assertStringContainsString('does not connect to a database', $output);
    }

    public function test_json_output_is_stable_machine_readable_and_honest(): void
    {
        $exit = Artisan::call('gatetrail:matrix', ['--json' => true]);
        $decoded = json_decode(
            trim(Artisan::output()),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertSame('gatetrail:matrix', $decoded['command']);
        $this->assertSame('ready', $decoded['status']);
        $this->assertSame(0, $decoded['exit_code']);
        $this->assertSame('^8.3', $decoded['runtime']['php']['constraint']);
        $this->assertTrue($decoded['runtime']['php']['supported']);
        $this->assertSame('^12.0 || ^13.0', $decoded['runtime']['laravel']['constraint']);
        $this->assertTrue($decoded['runtime']['laravel']['supported']);
        $this->assertSame('testing', $decoded['configured_database']['connection']);
        $this->assertSame('sqlite', $decoded['configured_database']['driver']);
        $this->assertTrue($decoded['configured_database']['ready']);
        $this->assertCount(5, $decoded['databases']);
        $this->assertFalse($decoded['databases'][3]['runtime_verified']);
        $this->assertFalse($decoded['databases'][4]['runtime_verified']);
        $this->assertStringContainsString(
            'does not connect to a database',
            $decoded['limitation'],
        );
    }

    public function test_unknown_configured_driver_fails_without_claiming_readiness(): void
    {
        config()->set('database.default', 'unknown');
        config()->set('database.connections.unknown', [
            'driver' => 'mongodb',
        ]);

        $exit = Artisan::call('gatetrail:matrix', ['--json' => true]);
        $decoded = json_decode(
            trim(Artisan::output()),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertSame('not_ready', $decoded['status']);
        $this->assertSame('mongodb', $decoded['configured_database']['driver']);
        $this->assertSame('Unknown', $decoded['configured_database']['engine']);
        $this->assertFalse($decoded['configured_database']['ready']);
        $this->assertSame([], $decoded['configured_database']['missing_extensions']);
    }

    public function test_command_executes_no_database_statements(): void
    {
        $statements = [];
        DB::listen(static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $exit = Artisan::call('gatetrail:matrix');

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertSame([], $statements);
    }
}

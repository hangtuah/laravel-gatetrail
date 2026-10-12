<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Console;

use HangTuah\GateTrail\Tests\Concerns\AssertsReadOnlySql;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command;

final class DoctorCommandTest extends TestCase
{
    use AssertsReadOnlySql;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);
    }

    public function test_command_is_registered_in_console_context(): void
    {
        $this->assertArrayHasKey('gatetrail:doctor', Artisan::all());
    }

    public function test_human_output_identifies_incomplete_schema_coverage(): void
    {
        $exit = Artisan::call('gatetrail:doctor');
        $output = Artisan::output();

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString(
            '[incomplete] schema.physical',
            $output,
        );
        $this->assertStringContainsString(
            'schema.inspector_unavailable',
            $output,
        );
        $this->assertStringContainsString(
            'Inspection was incomplete; this result is not clean.',
            $output,
        );
        $this->assertStringContainsString(
            'cannot prove the absence of direct database writes',
            $output,
        );
    }

    public function test_json_output_is_stable_machine_readable_and_privacy_safe(): void
    {
        $exit = Artisan::call('gatetrail:doctor', ['--json' => true]);
        $decoded = json_decode(
            trim(Artisan::output()),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertSame('gatetrail:doctor', $decoded['command']);
        $this->assertSame('incomplete', $decoded['status']);
        $this->assertFalse($decoded['complete']);
        $this->assertSame(1, $decoded['exit_code']);
        $this->assertFalse($decoded['coverage']['schema.physical']);
        $this->assertTrue($decoded['coverage']['data.assignments']);
        $this->assertSame([
            'error' => 0,
            'warning' => 1,
            'informational' => 0,
        ], $decoded['counts']);
        $this->assertSame(
            'schema.inspector_unavailable',
            $decoded['findings'][0]['code'],
        );
        $this->assertNull($decoded['findings'][0]['reference']);
        $this->assertStringNotContainsString('sqlite', Artisan::output());
        $this->assertStringNotContainsString('select ', strtolower(Artisan::output()));
    }

    public function test_command_executes_only_read_queries(): void
    {
        $statements = [];
        DB::listen(static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        Artisan::call('gatetrail:doctor');

        $this->assertNotEmpty($statements);
        $this->assertReadOnlyStatements($statements);
    }
}

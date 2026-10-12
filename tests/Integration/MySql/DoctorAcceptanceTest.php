<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Integration\MySql;

use HangTuah\GateTrail\Diagnostics\GateTrailDoctor;

final class DoctorAcceptanceTest extends MySqlIntegrationTestCase
{
    public function test_fresh_package_schema_produces_a_complete_clean_report(): void
    {
        $this->artisan('migrate', [
            '--database' => self::CONNECTION,
        ])->assertExitCode(0);

        $report = $this->app->make(GateTrailDoctor::class)->run();

        $this->assertTrue($report->isComplete());
        $this->assertTrue($report->isClean());
        $this->assertSame('complete', $report->status());
        $this->assertSame(0, $report->exitCode());
        $this->assertSame([], $report->findings);
        $this->assertSame([
            'data.assignments' => true,
            'data.coordination' => true,
            'data.definitions' => true,
            'data.subject_guards' => true,
            'schema.physical' => true,
        ], $report->coverage);
    }
}

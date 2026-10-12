<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Diagnostics;

use HangTuah\GateTrail\Diagnostics\GateTrailDoctor;
use HangTuah\GateTrail\Tests\TestCase;

final class GateTrailDoctorTest extends TestCase
{
    public function test_unavailable_schema_and_data_inspection_are_both_reported(): void
    {
        $report = app(GateTrailDoctor::class)->run();

        $this->assertFalse($report->isComplete());
        $this->assertSame('incomplete', $report->status());
        $this->assertSame([
            'data.assignments' => false,
            'data.coordination' => false,
            'data.definitions' => false,
            'data.subject_guards' => false,
            'schema.physical' => false,
        ], $report->coverage);
        $this->assertSame([
            'data.scan_unavailable',
            'schema.inspector_unavailable',
        ], array_map(
            static fn ($finding): string => $finding->code,
            $report->findings,
        ));
    }
}

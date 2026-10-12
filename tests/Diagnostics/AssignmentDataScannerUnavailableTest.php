<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Diagnostics;

use HangTuah\GateTrail\Diagnostics\AssignmentDataScanner;
use HangTuah\GateTrail\Tests\TestCase;

final class AssignmentDataScannerUnavailableTest extends TestCase
{
    public function test_missing_schema_is_incomplete_and_never_clean(): void
    {
        $report = app(AssignmentDataScanner::class)->scan();

        $this->assertFalse($report->isComplete());
        $this->assertFalse($report->isClean());
        $this->assertSame('incomplete', $report->status());
        $this->assertSame(1, $report->exitCode());
        $this->assertSame('data.scan_unavailable', $report->findings[0]->code);
        $this->assertSame([
            'data.assignments' => false,
            'data.coordination' => false,
            'data.definitions' => false,
            'data.subject_guards' => false,
        ], $report->coverage);
    }
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Diagnostics;

use HangTuah\GateTrail\Diagnostics\DiagnosticFinding;
use HangTuah\GateTrail\Diagnostics\DiagnosticReport;
use HangTuah\GateTrail\Diagnostics\DiagnosticSeverity;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DiagnosticReportTest extends TestCase
{
    public function test_complete_empty_report_is_clean_and_successful(): void
    {
        $report = new DiagnosticReport([], [
            'data' => true,
            'schema' => true,
        ]);

        $this->assertTrue($report->isComplete());
        $this->assertTrue($report->isClean());
        $this->assertFalse($report->hasErrors());
        $this->assertSame('complete', $report->status());
        $this->assertSame(0, $report->exitCode());
        $this->assertSame([
            'error' => 0,
            'warning' => 0,
            'informational' => 0,
        ], $report->counts());
    }

    public function test_error_finding_makes_a_complete_report_unsuccessful(): void
    {
        $report = new DiagnosticReport([
            new DiagnosticFinding(
                'assignment.overlap',
                DiagnosticSeverity::Error,
                'Equivalent assignment periods overlap.',
            ),
        ], ['data' => true]);

        $this->assertTrue($report->isComplete());
        $this->assertFalse($report->isClean());
        $this->assertTrue($report->hasErrors());
        $this->assertSame('errors', $report->status());
        $this->assertSame(1, $report->exitCode());
    }

    public function test_warning_and_information_do_not_fail_a_complete_run(): void
    {
        $report = new DiagnosticReport([
            new DiagnosticFinding(
                'definition.deactivated',
                DiagnosticSeverity::Informational,
                'An assignment references a deactivated definition.',
            ),
            new DiagnosticFinding(
                'schema.optional_index',
                DiagnosticSeverity::Warning,
                'An optional schema optimization is absent.',
            ),
        ], ['data' => true]);

        $this->assertFalse($report->isClean());
        $this->assertFalse($report->hasErrors());
        $this->assertSame('complete', $report->status());
        $this->assertSame(0, $report->exitCode());
        $this->assertSame([
            'error' => 0,
            'warning' => 1,
            'informational' => 1,
        ], $report->counts());
    }

    public function test_incomplete_run_can_never_report_clean_or_success(): void
    {
        $report = new DiagnosticReport([], [
            'data' => true,
            'schema' => false,
        ]);

        $this->assertFalse($report->isComplete());
        $this->assertFalse($report->isClean());
        $this->assertSame('incomplete', $report->status());
        $this->assertSame(1, $report->exitCode());
    }

    public function test_findings_and_coverage_are_deterministically_ordered(): void
    {
        $report = new DiagnosticReport([
            new DiagnosticFinding(
                'definition.deactivated',
                DiagnosticSeverity::Informational,
                'Informational.',
            ),
            new DiagnosticFinding(
                'schema.warning',
                DiagnosticSeverity::Warning,
                'Warning.',
            ),
            new DiagnosticFinding(
                'assignment.zeta',
                DiagnosticSeverity::Error,
                'Zeta error.',
            ),
            new DiagnosticFinding(
                'assignment.alpha',
                DiagnosticSeverity::Error,
                'Alpha error.',
            ),
        ], [
            'schema' => true,
            'data' => true,
        ]);

        $this->assertSame(
            [
                'assignment.alpha',
                'assignment.zeta',
                'schema.warning',
                'definition.deactivated',
            ],
            array_map(
                static fn (DiagnosticFinding $finding): string => $finding->code,
                $report->findings,
            ),
        );
        $this->assertSame(['data', 'schema'], array_keys($report->coverage));
    }

    public function test_report_requires_declared_boolean_coverage(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DiagnosticReport([], []);
    }

    public function test_report_rejects_an_invalid_coverage_identifier(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DiagnosticReport([], ['Schema check' => true]);
    }
}

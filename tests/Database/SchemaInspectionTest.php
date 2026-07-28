<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Database;

use HangTuah\GateTrail\Database\Schema\Inspection\InspectionSeverity;
use HangTuah\GateTrail\Database\Schema\Inspection\SchemaInspection;
use HangTuah\GateTrail\Database\Schema\Inspection\SchemaIssue;
use PHPUnit\Framework\TestCase;

final class SchemaInspectionTest extends TestCase
{
    public function test_an_empty_inspection_passes(): void
    {
        $this->assertTrue((new SchemaInspection([]))->passes());
    }

    public function test_a_warning_does_not_fail_the_inspection(): void
    {
        $inspection = new SchemaInspection([
            new SchemaIssue(
                code: 'optional_index',
                object: 'acl_roles',
                message: 'An optional index is absent.',
                severity: InspectionSeverity::Warning,
            ),
        ]);

        $this->assertTrue($inspection->passes());
    }

    public function test_an_error_fails_the_inspection(): void
    {
        $inspection = new SchemaInspection([
            new SchemaIssue(
                code: 'missing_table',
                object: 'acl_roles',
                message: 'The required table is absent.',
            ),
        ]);

        $this->assertFalse($inspection->passes());
    }
}

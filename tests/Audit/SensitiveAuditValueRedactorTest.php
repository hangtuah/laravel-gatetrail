<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Audit;

use HangTuah\GateTrail\Audit\SensitiveAuditValueRedactor;
use HangTuah\GateTrail\Audit\SensitiveMetadataRedactor;
use HangTuah\GateTrail\Tests\TestCase;

final class SensitiveAuditValueRedactorTest extends TestCase
{
    public function test_assignment_and_audit_reasons_are_independent_paths(): void
    {
        config()->set('gatetrail.sensitive_fields', [
            'assignment_reason',
        ]);
        $redactor = app(SensitiveAuditValueRedactor::class);

        $this->assertSame(
            SensitiveMetadataRedactor::MARKER,
            $redactor->assignmentReason(
                'Sensitive assignment reason',
                'after',
            ),
        );
        $this->assertSame(
            'Visible audit reason',
            $redactor->auditReason('Visible audit reason'),
        );
    }

    public function test_dotted_paths_are_supported(): void
    {
        config()->set('gatetrail.sensitive_fields', [
            'after.assignment_reason',
            'audit.reason',
        ]);
        $redactor = app(SensitiveAuditValueRedactor::class);

        $this->assertSame(
            SensitiveMetadataRedactor::MARKER,
            $redactor->assignmentReason(
                'Sensitive assignment reason',
                'after',
            ),
        );
        $this->assertSame(
            SensitiveMetadataRedactor::MARKER,
            $redactor->auditReason('Sensitive audit reason'),
        );
    }

    public function test_null_reason_remains_null(): void
    {
        config()->set('gatetrail.sensitive_fields', [
            'assignment_reason',
            'reason',
        ]);

        $this->assertNull(
            app(SensitiveAuditValueRedactor::class)
                ->assignmentReason(null, 'after'),
        );
        $this->assertNull(
            app(SensitiveAuditValueRedactor::class)
                ->auditReason(null),
        );
    }

    public function test_dotted_path_only_redacts_its_snapshot_side(): void
    {
        config()->set('gatetrail.sensitive_fields', [
            'before.assignment_reason',
        ]);

        $this->assertSame(
            'Visible after reason',
            app(SensitiveAuditValueRedactor::class)->assignmentReason(
                'Visible after reason',
                'after',
            ),
        );
    }
}

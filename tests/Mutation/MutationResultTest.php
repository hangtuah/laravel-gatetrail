<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\MutationResult;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MutationResultTest extends TestCase
{
    /**
     * @return iterable<string, array{MutationOutcome}>
     */
    public static function changedOutcomes(): iterable
    {
        yield 'created' => [MutationOutcome::Created];
        yield 'updated' => [MutationOutcome::Updated];
        yield 'deactivated' => [MutationOutcome::Deactivated];
        yield 'restored' => [MutationOutcome::Restored];
        yield 'revoked' => [MutationOutcome::Revoked];
    }

    #[DataProvider('changedOutcomes')]
    public function test_changed_outcomes_report_a_change(
        MutationOutcome $outcome,
    ): void {
        $resource = new Role;
        $result = new MutationResult(
            $resource,
            $outcome,
            42,
        );

        $this->assertSame($resource, $result->resource);
        $this->assertSame($outcome, $result->outcome);
        $this->assertSame(42, $result->auditId);
        $this->assertTrue($result->changed());
    }

    public function test_unchanged_outcome_reports_no_change(): void
    {
        $resource = new Role;
        $result = new MutationResult(
            $resource,
            MutationOutcome::Unchanged,
            null,
        );

        $this->assertSame($resource, $result->resource);
        $this->assertFalse($result->changed());
        $this->assertNull($result->auditId);
    }

    public function test_unchanged_outcome_rejects_an_audit_identifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot have an audit identifier');

        new MutationResult(
            new Role,
            MutationOutcome::Unchanged,
            'audit-42',
        );
    }
}

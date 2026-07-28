<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use HangTuah\GateTrail\Exceptions\ReasonRequired;
use HangTuah\GateTrail\Mutations\MutationReasonValidator;
use HangTuah\GateTrail\Tests\TestCase;

final class MutationReasonValidatorTest extends TestCase
{
    public function test_required_assignment_reason_is_trimmed(): void
    {
        $validator = new MutationReasonValidator(true);

        $this->assertSame(
            'Assigned by administrator',
            $validator->assignment('  Assigned by administrator  '),
        );
    }

    public function test_required_assignment_reason_rejects_null(): void
    {
        $this->expectException(ReasonRequired::class);
        $this->expectExceptionMessage('non-empty reason is required');

        (new MutationReasonValidator(true))->assignment(null);
    }

    public function test_required_assignment_reason_rejects_whitespace(): void
    {
        $this->expectException(ReasonRequired::class);
        $this->expectExceptionMessage('non-empty reason is required');

        (new MutationReasonValidator(true))->assignment('   ');
    }

    public function test_optional_blank_assignment_reason_becomes_null(): void
    {
        $validator = new MutationReasonValidator(false);

        $this->assertNull($validator->assignment(null));
        $this->assertNull($validator->assignment('   '));
    }

    public function test_container_binding_honors_relaxed_reason_configuration(): void
    {
        config()->set('gatetrail.assignments.require_reason', false);
        app()->forgetInstance(MutationReasonValidator::class);

        $this->assertNull(
            app(MutationReasonValidator::class)->assignment('   '),
        );
    }
}

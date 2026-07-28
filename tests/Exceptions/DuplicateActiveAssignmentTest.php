<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Exceptions;

use HangTuah\GateTrail\Assignments\AssignmentKind;
use HangTuah\GateTrail\Assignments\AssignmentScope;
use HangTuah\GateTrail\Exceptions\DuplicateActiveAssignment;
use PHPUnit\Framework\TestCase;

final class DuplicateActiveAssignmentTest extends TestCase
{
    public function test_exception_exposes_only_safe_scope_context(): void
    {
        $scope = AssignmentScope::forRole(
            'sensitive-user-alias',
            4242,
            7,
        );
        $exception = DuplicateActiveAssignment::forScope($scope);

        $this->assertSame(
            AssignmentKind::Role,
            $exception->assignmentKind,
        );
        $this->assertSame($scope->hash(), $exception->scopeHash);
        $this->assertStringContainsString(
            $scope->hash(),
            $exception->getMessage(),
        );
        $this->assertStringNotContainsString(
            'sensitive-user-alias',
            $exception->getMessage(),
        );
        $this->assertStringNotContainsString(
            '4242',
            $exception->getMessage(),
        );
    }
}

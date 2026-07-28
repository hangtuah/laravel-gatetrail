<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Exceptions;

use HangTuah\GateTrail\Assignments\AssignmentKind;
use HangTuah\GateTrail\Assignments\AssignmentScope;
use RuntimeException;

final class DuplicateActiveAssignment extends RuntimeException
{
    private function __construct(
        public readonly AssignmentKind $assignmentKind,
        public readonly string $scopeHash,
    ) {
        parent::__construct(
            sprintf(
                'A conflicting active GateTrail [%s] assignment exists for scope hash [%s].',
                $assignmentKind->value,
                $scopeHash,
            ),
        );
    }

    public static function forScope(AssignmentScope $scope): self
    {
        return new self(
            $scope->kind(),
            $scope->hash(),
        );
    }
}

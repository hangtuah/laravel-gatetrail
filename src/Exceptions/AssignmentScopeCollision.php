<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Exceptions;

use HangTuah\GateTrail\Assignments\AssignmentScope;
use RuntimeException;

final class AssignmentScopeCollision extends RuntimeException
{
    public static function forScope(AssignmentScope $scope): self
    {
        return new self(
            sprintf(
                'GateTrail assignment scope collision detected for [%s:%s].',
                $scope->kind()->value,
                $scope->hash(),
            ),
        );
    }
}

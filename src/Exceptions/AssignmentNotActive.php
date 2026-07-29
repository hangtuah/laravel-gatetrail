<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Exceptions;

use RuntimeException;

final class AssignmentNotActive extends RuntimeException
{
    private function __construct(
        public readonly string $assignmentKind,
        public readonly string $referenceHash,
    ) {
        parent::__construct(
            sprintf(
                'The GateTrail [%s] assignment is not active for reference hash [%s].',
                $assignmentKind,
                $referenceHash,
            ),
        );
    }

    public static function forRole(int|string $reference): self
    {
        return new self(
            'role',
            hash('sha256', (string) $reference),
        );
    }
}

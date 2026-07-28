<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations;

use HangTuah\GateTrail\Exceptions\ReasonRequired;

final readonly class MutationReasonValidator
{
    public function __construct(
        private bool $assignmentReasonRequired,
    ) {}

    public function assignment(?string $reason): ?string
    {
        $normalized = $reason === null ? null : trim($reason);

        if (
            $this->assignmentReasonRequired
            && ($normalized === null || $normalized === '')
        ) {
            throw ReasonRequired::forAssignment();
        }

        return $normalized === '' ? null : $normalized;
    }
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations;

use HangTuah\GateTrail\Exceptions\ReasonRequired;

/**
 * @internal
 */
final readonly class MutationReasonValidator
{
    public function __construct(
        private bool $assignmentReasonRequired,
        private bool $definitionReasonRequired = false,
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

    public function definition(?string $reason): ?string
    {
        $normalized = $reason === null ? null : trim($reason);

        if (
            $this->definitionReasonRequired
            && ($normalized === null || $normalized === '')
        ) {
            throw ReasonRequired::forDefinition();
        }

        return $normalized === '' ? null : $normalized;
    }
}

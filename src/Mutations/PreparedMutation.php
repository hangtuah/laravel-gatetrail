<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations;

use HangTuah\GateTrail\Audit\PendingAuditRecord;
use HangTuah\GateTrail\MutationOutcome;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * One completed domain mutation together with the audit evidence it produced.
 *
 * Handlers return this from inside the mutation transaction. The audit record
 * is already normalized and immutable, so the surrounding orchestration can
 * persist it in the same transaction (strict) or after the outermost commit
 * (non-strict) without re-reading mutated models.
 *
 * @template TResource of Model
 *
 * @internal
 */
final readonly class PreparedMutation
{
    /**
     * @param  TResource  $resource
     */
    public function __construct(
        public Model $resource,
        public MutationOutcome $outcome,
        public ?PendingAuditRecord $audit,
    ) {
        if (
            $outcome === MutationOutcome::Unchanged
            && $audit !== null
        ) {
            throw new InvalidArgumentException(
                'An unchanged GateTrail mutation cannot carry audit evidence.',
            );
        }

        if (
            $outcome !== MutationOutcome::Unchanged
            && $audit === null
        ) {
            throw new InvalidArgumentException(
                'A changed GateTrail mutation must carry audit evidence.',
            );
        }
    }
}

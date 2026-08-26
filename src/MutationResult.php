<?php

declare(strict_types=1);

namespace HangTuah\GateTrail;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * @template-covariant TResource of Model
 */
final readonly class MutationResult
{
    /**
     * @param  TResource  $resource
     *
     * @internal
     */
    public function __construct(
        public Model $resource,
        public MutationOutcome $outcome,
        public int|string|null $auditId,
    ) {
        if (
            $outcome === MutationOutcome::Unchanged
            && $auditId !== null
        ) {
            throw new InvalidArgumentException(
                'An unchanged GateTrail mutation cannot have an audit identifier.',
            );
        }
    }

    public function changed(): bool
    {
        return $this->outcome !== MutationOutcome::Unchanged;
    }
}

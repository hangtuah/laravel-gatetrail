<?php

declare(strict_types=1);

namespace HangTuah\GateTrail;

use InvalidArgumentException;

/**
 * An explicit set of role-definition changes.
 *
 * Every instance represents at least one deliberate change, so an empty or
 * ambiguous changes object cannot be constructed. Role name and guard are
 * immutable in the initial public API and have no changes factory.
 */
final readonly class RoleChanges
{
    private function __construct(
        public ?string $description,
    ) {}

    /**
     * Applies an explicit description change. Passing null clears the
     * persisted description; passing an empty string preserves it.
     */
    public static function description(?string $description): self
    {
        if (
            $description !== null
            && ! mb_check_encoding($description, 'UTF-8')
        ) {
            throw new InvalidArgumentException(
                'GateTrail definition description must be valid UTF-8.',
            );
        }

        return new self($description);
    }
}

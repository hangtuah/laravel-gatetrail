<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Exceptions;

use RuntimeException;

final class MigrationDependencyError extends RuntimeException
{
    public static function missing(string $child, string $parent): self
    {
        return new self(
            sprintf(
                'GateTrail cannot create [%s]: required parent table [%s] does not exist.',
                $child,
                $parent,
            ),
        );
    }

    public static function stillReferenced(string $parent, string $child): self
    {
        return new self(
            sprintf(
                'GateTrail cannot drop [%s]: dependent child table [%s] still exists.',
                $parent,
                $child,
            ),
        );
    }
}

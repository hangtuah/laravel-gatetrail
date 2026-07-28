<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Exceptions;

use RuntimeException;

final class SchemaInspectorUnavailable extends RuntimeException
{
    public static function forDriver(string $driver): self
    {
        return new self(
            sprintf(
                'GateTrail does not yet provide a schema inspector for [%s].',
                $driver,
            ),
        );
    }
}

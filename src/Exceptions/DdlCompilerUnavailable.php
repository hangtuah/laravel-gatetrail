<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Exceptions;

use RuntimeException;

final class DdlCompilerUnavailable extends RuntimeException
{
    public static function forDriver(string $driver): self
    {
        return new self(
            sprintf(
                'GateTrail does not yet provide a DBA DDL compiler for [%s].',
                $driver,
            ),
        );
    }
}

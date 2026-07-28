<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Exceptions;

use RuntimeException;

final class UnsupportedDatabaseDriver extends RuntimeException
{
    public static function named(string $driver): self
    {
        return new self(
            sprintf('GateTrail does not support the [%s] database driver.', $driver),
        );
    }
}

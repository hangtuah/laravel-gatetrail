<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Exceptions;

use InvalidArgumentException;
use Throwable;

final class InvalidAuditMetadata extends InvalidArgumentException
{
    private function __construct(
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function topLevelMustBeObject(): self
    {
        return new self(
            'GateTrail metadata must be a top-level associative object.',
        );
    }

    public static function notJsonSafe(?Throwable $previous = null): self
    {
        return new self(
            'GateTrail metadata contains a value that is not JSON-safe.',
            0,
            $previous,
        );
    }

    public static function reservedMarker(): self
    {
        return new self(
            'GateTrail metadata cannot contain the reserved redaction marker.',
        );
    }

    public static function tooLarge(int $maxBytes): self
    {
        return new self(
            sprintf(
                'GateTrail metadata exceeds the configured %d-byte limit.',
                $maxBytes,
            ),
        );
    }

    public static function invalidLimit(string $key): self
    {
        return new self(
            sprintf(
                'GateTrail metadata limit [%s] must be a positive integer.',
                $key,
            ),
        );
    }

    public static function invalidSensitivePath(): self
    {
        return new self(
            'GateTrail sensitive field paths must be non-empty strings.',
        );
    }
}

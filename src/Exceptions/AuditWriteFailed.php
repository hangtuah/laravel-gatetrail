<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Exceptions;

use RuntimeException;
use Throwable;

final class AuditWriteFailed extends RuntimeException
{
    private function __construct(
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function forEvent(
        string $event,
        Throwable $previous,
    ): self {
        return new self(
            sprintf(
                'GateTrail could not persist audit event [%s].',
                $event,
            ),
            0,
            $previous,
        );
    }
}

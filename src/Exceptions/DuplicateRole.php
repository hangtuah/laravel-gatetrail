<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Exceptions;

use RuntimeException;

final class DuplicateRole extends RuntimeException
{
    private function __construct(
        public readonly string $namespaceHash,
    ) {
        parent::__construct(
            sprintf(
                'A GateTrail role definition already exists for namespace hash [%s].',
                $namespaceHash,
            ),
        );
    }

    public static function forNamespace(string $name, string $guard): self
    {
        return new self(
            hash('sha256', (string) json_encode([$guard, $name])),
        );
    }
}

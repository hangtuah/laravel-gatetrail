<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Exceptions;

use InvalidArgumentException;

final class InvalidActor extends InvalidArgumentException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function emptySystemName(): self
    {
        return new self('GateTrail system actor name cannot be empty.');
    }

    public static function missingModelKey(): self
    {
        return new self(
            'GateTrail human actor must have an integer or string model key.',
        );
    }

    public static function reservedMorphAlias(): self
    {
        return new self(
            'GateTrail human actor cannot use the reserved [gatetrail.system] morph alias.',
        );
    }

    public static function emptyValue(string $label): self
    {
        return new self(
            sprintf('GateTrail %s cannot be empty.', $label),
        );
    }

    public static function valueTooLong(string $label): self
    {
        return new self(
            sprintf('GateTrail %s cannot exceed 191 characters.', $label),
        );
    }
}

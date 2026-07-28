<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Ddl\Oracle;

use InvalidArgumentException;

enum OracleGeneratedKeyStrategy: string
{
    case Identity = 'identity';
    case SequenceTrigger = 'sequence_trigger';

    public static function fromConfig(mixed $value): self
    {
        if ($value === null) {
            return self::SequenceTrigger;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                'GateTrail oracle.generated_keys must be a string.',
            );
        }

        $strategy = self::tryFrom($value);

        if ($strategy === null) {
            throw new InvalidArgumentException(
                'GateTrail oracle.generated_keys must be [identity] or [sequence_trigger].',
            );
        }

        return $strategy;
    }
}

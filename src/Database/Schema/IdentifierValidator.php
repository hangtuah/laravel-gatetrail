<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema;

use HangTuah\GateTrail\Exceptions\InvalidDatabaseIdentifier;

/**
 * @internal
 */
final class IdentifierValidator
{
    public const MAX_LENGTH = 30;

    public function validate(string $identifier, string $label = 'identifier'): void
    {
        if ($identifier === '') {
            throw InvalidDatabaseIdentifier::because(
                $label,
                $identifier,
                'the value must not be empty',
            );
        }

        if (strlen($identifier) > self::MAX_LENGTH) {
            throw InvalidDatabaseIdentifier::because(
                $label,
                $identifier,
                sprintf('the value exceeds %d characters', self::MAX_LENGTH),
            );
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw InvalidDatabaseIdentifier::because(
                $label,
                $identifier,
                'only ASCII letters, digits, and underscores are portable',
            );
        }
    }
}

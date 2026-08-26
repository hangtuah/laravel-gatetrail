<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations;

use Illuminate\Database\Eloquent\Model;

/**
 * @internal
 */
final readonly class DefinitionIdentifierNormalizer
{
    /**
     * Returns the identifier in the binding type required by the definition
     * model's primary-key type, or null when the identifier can never match a
     * row and must not reach the database driver.
     */
    public function normalize(
        Model $model,
        int|string $identifier,
    ): int|string|null {
        if (! in_array($model->getKeyType(), ['int', 'integer'], true)) {
            return $identifier;
        }

        if (is_int($identifier)) {
            return $identifier;
        }

        if (preg_match('/^\d+$/D', $identifier) !== 1) {
            return null;
        }

        $canonical = ltrim($identifier, '0');
        $canonical = $canonical === '' ? '0' : $canonical;
        $normalized = (int) $identifier;

        if ((string) $normalized !== $canonical) {
            return null;
        }

        return $normalized;
    }
}

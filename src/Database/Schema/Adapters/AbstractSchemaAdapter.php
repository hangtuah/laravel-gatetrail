<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Adapters;

use HangTuah\GateTrail\Database\Schema\SchemaAdapter;

/**
 * @internal
 */
abstract class AbstractSchemaAdapter implements SchemaAdapter
{
    public function physicalPrimaryKeyName(string $logicalName): ?string
    {
        return $logicalName;
    }

    public function caseSensitiveCollation(): ?string
    {
        return null;
    }

    public function requiresInlineCheckConstraints(): bool
    {
        return false;
    }
}

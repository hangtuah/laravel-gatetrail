<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Adapters;

final class SQLiteAdapter extends AbstractSchemaAdapter
{
    public function driver(): string
    {
        return 'sqlite';
    }

    public function physicalPrimaryKeyName(string $logicalName): ?string
    {
        return null;
    }

    public function caseSensitiveCollation(): string
    {
        return 'BINARY';
    }

    public function requiresInlineCheckConstraints(): bool
    {
        return true;
    }
}

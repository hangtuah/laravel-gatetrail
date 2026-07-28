<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Adapters;

final class MySqlAdapter extends AbstractSchemaAdapter
{
    public function driver(): string
    {
        return 'mysql';
    }

    public function physicalPrimaryKeyName(string $logicalName): string
    {
        return 'PRIMARY';
    }

    public function caseSensitiveCollation(): string
    {
        return 'utf8mb4_bin';
    }
}

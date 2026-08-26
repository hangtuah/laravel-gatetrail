<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Adapters;

/**
 * @internal
 */
final class PostgreSqlAdapter extends AbstractSchemaAdapter
{
    public function driver(): string
    {
        return 'pgsql';
    }

    public function caseSensitiveCollation(): string
    {
        return 'C';
    }
}

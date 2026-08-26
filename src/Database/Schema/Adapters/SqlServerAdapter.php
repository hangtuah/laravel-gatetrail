<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Adapters;

/**
 * @internal
 */
final class SqlServerAdapter extends AbstractSchemaAdapter
{
    public function driver(): string
    {
        return 'sqlsrv';
    }

    public function caseSensitiveCollation(): string
    {
        return 'Latin1_General_100_BIN2';
    }
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Adapters;

/**
 * @internal
 */
final class OracleAdapter extends AbstractSchemaAdapter
{
    public function driver(): string
    {
        return 'oracle';
    }
}

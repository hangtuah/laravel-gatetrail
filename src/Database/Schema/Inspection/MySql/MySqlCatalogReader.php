<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Inspection\MySql;

use HangTuah\GateTrail\Database\Schema\SchemaContext;

/**
 * @internal
 */
interface MySqlCatalogReader
{
    public function read(SchemaContext $context): MySqlCatalogSnapshot;
}

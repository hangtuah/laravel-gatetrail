<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Inspection\Oracle;

use HangTuah\GateTrail\Database\Schema\SchemaContext;

/**
 * @internal
 */
interface OracleCatalogReader
{
    public function read(SchemaContext $context): OracleCatalogSnapshot;
}

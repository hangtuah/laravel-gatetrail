<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Inspection\PostgreSql;

use HangTuah\GateTrail\Database\Schema\Manifest\SchemaManifest;
use HangTuah\GateTrail\Database\Schema\SchemaContext;

/**
 * Reads the installed PostgreSQL catalog for the GateTrail tables the manifest
 * names, resolved through the configured schema, prefix and table names.
 *
 * The manifest is a parameter rather than a constructor dependency because it
 * is what bounds the read: an implementation restricts every catalog query to
 * the seven manifest tables instead of scanning the whole schema.
 *
 * @internal
 */
interface PostgreSqlCatalogReader
{
    public function read(
        SchemaManifest $manifest,
        SchemaContext $context,
    ): PostgreSqlCatalogSnapshot;
}

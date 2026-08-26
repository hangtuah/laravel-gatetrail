<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Inspection;

use HangTuah\GateTrail\Database\Schema\Manifest\SchemaManifest;
use HangTuah\GateTrail\Database\Schema\SchemaContext;

/**
 * @internal
 */
interface SchemaInspector
{
    public function inspect(
        SchemaManifest $manifest,
        SchemaContext $context,
    ): SchemaInspection;
}

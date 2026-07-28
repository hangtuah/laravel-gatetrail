<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Ddl;

use HangTuah\GateTrail\Database\Schema\Manifest\SchemaManifest;
use HangTuah\GateTrail\Database\Schema\SchemaContext;

interface DdlCompiler
{
    /**
     * @return list<string>
     */
    public function compileInstall(
        SchemaManifest $manifest,
        SchemaContext $context,
    ): array;

    /**
     * @return list<string>
     */
    public function compileRollback(
        SchemaManifest $manifest,
        SchemaContext $context,
    ): array;
}

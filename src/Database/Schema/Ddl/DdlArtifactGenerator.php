<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Ddl;

use HangTuah\GateTrail\Database\Schema\Manifest\SchemaManifest;
use HangTuah\GateTrail\Database\Schema\SchemaContext;

final readonly class DdlArtifactGenerator
{
    public const SCHEMA_RELEASE = '0001';

    public function __construct(
        private DdlCompilerFactory $compilers,
    ) {}

    public function install(
        string $driver,
        SchemaManifest $manifest,
        SchemaContext $context,
    ): string {
        return $this->render(
            $driver,
            'install',
            $this->compilers
                ->forDriver($driver)
                ->compileInstall($manifest, $context),
        );
    }

    public function rollback(
        string $driver,
        SchemaManifest $manifest,
        SchemaContext $context,
    ): string {
        return $this->render(
            $driver,
            'rollback',
            $this->compilers
                ->forDriver($driver)
                ->compileRollback($manifest, $context),
        );
    }

    /**
     * @param  list<string>  $statements
     */
    private function render(
        string $driver,
        string $direction,
        array $statements,
    ): string {
        $header = sprintf(
            "-- GateTrail schema release %s\n"
            ."-- Database driver: %s\n"
            ."-- Direction: %s\n"
            .'-- Generated from the canonical schema manifest; do not edit manually.',
            self::SCHEMA_RELEASE,
            $driver,
            $direction,
        );

        return $header."\n\n".implode("\n\n", $statements)."\n";
    }
}

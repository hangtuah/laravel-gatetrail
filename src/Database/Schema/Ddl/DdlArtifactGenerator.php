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
        ?string $strategy = null,
    ): string {
        return $this->render(
            $driver,
            'install',
            $this->compilers
                ->forDriver($driver)
                ->compileInstall($manifest, $context),
            $strategy,
        );
    }

    public function rollback(
        string $driver,
        SchemaManifest $manifest,
        SchemaContext $context,
        ?string $strategy = null,
    ): string {
        return $this->render(
            $driver,
            'rollback',
            $this->compilers
                ->forDriver($driver)
                ->compileRollback($manifest, $context),
            $strategy,
        );
    }

    /**
     * @param  list<string>  $statements
     */
    private function render(
        string $driver,
        string $direction,
        array $statements,
        ?string $strategy,
    ): string {
        $header = [
            sprintf(
                '-- GateTrail schema release %s',
                self::SCHEMA_RELEASE,
            ),
            sprintf('-- Database driver: %s', $driver),
        ];

        if ($strategy !== null) {
            $header[] = sprintf('-- Generated-key strategy: %s', $strategy);
        }

        $header[] = sprintf('-- Direction: %s', $direction);
        $header[] = '-- Generated from the canonical schema manifest; do not edit manually.';

        return implode("\n", $header)
            ."\n\n"
            .implode("\n\n", $statements)
            ."\n";
    }
}

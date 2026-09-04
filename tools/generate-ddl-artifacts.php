<?php

declare(strict_types=1);

use HangTuah\GateTrail\Database\Schema\Ddl\DdlArtifactGenerator;
use HangTuah\GateTrail\Database\Schema\Ddl\DdlCompilerFactory;
use HangTuah\GateTrail\Database\Schema\IdentifierValidator;
use HangTuah\GateTrail\Database\Schema\Manifest\SchemaManifest;
use HangTuah\GateTrail\Database\Schema\SchemaContext;

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);
$config = require $root.'/config/gatetrail.php';
$manifest = SchemaManifest::gateTrail();
$generator = new DdlArtifactGenerator(new DdlCompilerFactory);
$artifactSets = [
    [
        'driver' => 'mysql',
        'directory' => sprintf(
            '%s/database/ddl/mysql/%s',
            $root,
            DdlArtifactGenerator::SCHEMA_RELEASE,
        ),
        'config' => $config,
        'strategy' => null,
    ],
    [
        'driver' => 'oracle',
        'directory' => sprintf(
            '%s/database/ddl/oracle/%s/identity',
            $root,
            DdlArtifactGenerator::SCHEMA_RELEASE,
        ),
        'config' => oracleConfig($config, 'identity'),
        'strategy' => 'identity',
    ],
    [
        'driver' => 'oracle',
        'directory' => sprintf(
            '%s/database/ddl/oracle/%s/sequence-trigger',
            $root,
            DdlArtifactGenerator::SCHEMA_RELEASE,
        ),
        'config' => oracleConfig($config, 'sequence_trigger'),
        'strategy' => 'sequence_trigger',
    ],
    [
        'driver' => 'pgsql',
        'directory' => sprintf(
            '%s/database/ddl/postgresql/%s',
            $root,
            DdlArtifactGenerator::SCHEMA_RELEASE,
        ),
        'config' => $config,
        'strategy' => null,
    ],
];

foreach ($artifactSets as $artifactSet) {
    $context = new SchemaContext(
        $artifactSet['config'],
        new IdentifierValidator,
        $manifest,
    );
    $directory = $artifactSet['directory'];

    if (! is_dir($directory) && ! mkdir($directory, 0755, true)) {
        throw new RuntimeException(
            sprintf(
                'Unable to create DDL artifact directory [%s].',
                $directory,
            ),
        );
    }

    $artifacts = [
        'install.sql' => $generator->install(
            $artifactSet['driver'],
            $manifest,
            $context,
            $artifactSet['strategy'],
        ),
        'rollback.sql' => $generator->rollback(
            $artifactSet['driver'],
            $manifest,
            $context,
            $artifactSet['strategy'],
        ),
    ];

    foreach ($artifacts as $filename => $contents) {
        $path = $directory.'/'.$filename;

        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException(
                sprintf('Unable to write DDL artifact [%s].', $path),
            );
        }

        fwrite(STDOUT, sprintf("Generated %s\n", $path));
    }
}

/**
 * @param  array<string, mixed>  $config
 * @return array<string, mixed>
 */
function oracleConfig(array $config, string $strategy): array
{
    $oracle = $config['oracle'] ?? null;

    if (! is_array($oracle)) {
        throw new RuntimeException(
            'GateTrail Oracle configuration must be an array.',
        );
    }

    $oracle['generated_keys'] = $strategy;
    $config['oracle'] = $oracle;

    return $config;
}

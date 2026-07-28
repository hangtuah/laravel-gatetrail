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
$context = new SchemaContext(
    $config,
    new IdentifierValidator,
    $manifest,
);
$generator = new DdlArtifactGenerator(new DdlCompilerFactory);
$directory = sprintf(
    '%s/database/ddl/mysql/%s',
    $root,
    DdlArtifactGenerator::SCHEMA_RELEASE,
);

if (! is_dir($directory) && ! mkdir($directory, 0755, true)) {
    throw new RuntimeException(
        sprintf('Unable to create DDL artifact directory [%s].', $directory),
    );
}

$artifacts = [
    'install.sql' => $generator->install('mysql', $manifest, $context),
    'rollback.sql' => $generator->rollback('mysql', $manifest, $context),
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

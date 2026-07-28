<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Migrations;

use HangTuah\GateTrail\Database\Schema\Ddl\DdlCompilerFactory;
use HangTuah\GateTrail\Database\Schema\Manifest\SchemaManifest;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use Illuminate\Database\Connection;

final readonly class OracleMigrationExecutor
{
    public function __construct(
        private DdlCompilerFactory $compilers,
        private SchemaManifest $manifest,
        private SchemaContext $context,
    ) {}

    public function installIfSupported(
        Connection $connection,
        string $tableKey,
    ): bool {
        if (! $this->supports($connection)) {
            return false;
        }

        $this->execute(
            $connection,
            $this->compilerStatements($tableKey, install: true),
        );

        return true;
    }

    public function rollbackIfSupported(
        Connection $connection,
        string $tableKey,
    ): bool {
        if (! $this->supports($connection)) {
            return false;
        }

        $this->execute(
            $connection,
            $this->compilerStatements($tableKey, install: false),
        );

        return true;
    }

    private function supports(Connection $connection): bool
    {
        return in_array(
            $connection->getDriverName(),
            ['oracle', 'oci8'],
            true,
        );
    }

    /**
     * @return list<string>
     */
    private function compilerStatements(
        string $tableKey,
        bool $install,
    ): array {
        $tableManifest = new SchemaManifest([
            $this->manifest->table($tableKey),
        ]);
        $compiler = $this->compilers->forDriver('oracle');

        return $install
            ? $compiler->compileInstall($tableManifest, $this->context)
            : $compiler->compileRollback($tableManifest, $this->context);
    }

    /**
     * @param  list<string>  $statements
     */
    private function execute(
        Connection $connection,
        array $statements,
    ): void {
        foreach ($statements as $statement) {
            $connection->statement(
                $this->executableStatement($statement),
            );
        }
    }

    private function executableStatement(string $statement): string
    {
        $statement = trim($statement);

        if (str_ends_with($statement, '/')) {
            return rtrim(substr($statement, 0, -1));
        }

        if (str_ends_with($statement, ';')) {
            return substr($statement, 0, -1);
        }

        return $statement;
    }
}

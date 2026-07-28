<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema;

use HangTuah\GateTrail\Database\Schema\Manifest\SchemaManifest;
use InvalidArgumentException;

final class SchemaContext
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly array $config,
        private readonly IdentifierValidator $identifiers,
        private readonly SchemaManifest $manifest,
    ) {}

    public function connectionName(): ?string
    {
        $connection = $this->databaseValue('connection');

        if ($connection === null || $connection === '') {
            return null;
        }

        if (! is_string($connection)) {
            throw new InvalidArgumentException(
                'GateTrail database.connection must be a string or null.',
            );
        }

        return $connection;
    }

    public function provisioning(): string
    {
        $provisioning = $this->databaseValue('provisioning');

        if (! is_string($provisioning)) {
            throw new InvalidArgumentException(
                'GateTrail database.provisioning must be a string.',
            );
        }

        if (! in_array($provisioning, ['package', 'dba'], true)) {
            throw new InvalidArgumentException(
                'GateTrail database.provisioning must be [package] or [dba].',
            );
        }

        return $provisioning;
    }

    public function table(string $key): string
    {
        $table = $this->tableName($key);
        $schema = $this->schemaName();

        return $schema === null ? $table : $schema.'.'.$table;
    }

    public function tableName(string $key): string
    {
        $tables = $this->config['tables'] ?? null;

        if (! is_array($tables) || ! array_key_exists($key, $tables)) {
            throw new InvalidArgumentException(
                sprintf('Unknown GateTrail table key [%s].', $key),
            );
        }

        $configured = $tables[$key];

        if (! is_string($configured) || $configured === '') {
            throw new InvalidArgumentException(
                sprintf('GateTrail table [%s] must be a non-empty string.', $key),
            );
        }

        $table = $this->prefix().$configured;
        $this->identifiers->validate($table, sprintf('table [%s]', $key));

        return $table;
    }

    public function validateAll(): void
    {
        foreach ($this->manifest->creationOrder() as $key) {
            $this->tableName($key);
        }

        $this->schemaName();
    }

    private function schemaName(): ?string
    {
        $schema = $this->databaseValue('schema');

        if ($schema === null || $schema === '') {
            return null;
        }

        if (! is_string($schema)) {
            throw new InvalidArgumentException(
                'GateTrail database.schema must be a string or null.',
            );
        }

        $this->identifiers->validate($schema, 'database schema');

        return $schema;
    }

    private function prefix(): string
    {
        $prefix = $this->databaseValue('prefix');

        if ($prefix === null) {
            return '';
        }

        if (! is_string($prefix)) {
            throw new InvalidArgumentException(
                'GateTrail database.prefix must be a string or null.',
            );
        }

        if ($prefix !== '' && preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $prefix) !== 1) {
            throw new InvalidArgumentException(
                'GateTrail database.prefix contains a non-portable character.',
            );
        }

        return $prefix;
    }

    private function databaseValue(string $key): mixed
    {
        $database = $this->config['database'] ?? null;

        if (! is_array($database)) {
            throw new InvalidArgumentException(
                'GateTrail database configuration must be an array.',
            );
        }

        return $database[$key] ?? null;
    }
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Inspection\PostgreSql;

/**
 * One normalized `pg_constraint` entry.
 *
 * `$type` keeps PostgreSQL's own single-character `contype` code — `p`, `u`,
 * `f` or `c` — rather than a translated word, so the snapshot stays faithful
 * to the catalog it came from.
 *
 * `$deleteRule` and `$updateRule` are the expanded referential actions
 * (`RESTRICT`, `NO ACTION`, `CASCADE`, `SET NULL`, `SET DEFAULT`) for foreign
 * keys, and null for every other constraint type. An unrecognized action code
 * is also null, which the inspector treats as incomplete metadata.
 *
 * `$columns` follows the declared column order for primary-key, unique and
 * foreign-key constraints. PostgreSQL stores a check constraint's `conkey` in
 * its own order, so that order is not compared against the manifest.
 *
 * @internal
 */
final readonly class PostgreSqlConstraintMetadata
{
    /**
     * @param  list<string>  $columns
     * @param  list<string>  $referencedColumns
     */
    public function __construct(
        public string $table,
        public string $name,
        public string $type,
        public array $columns,
        public bool $validated,
        public ?string $referencedTable = null,
        public array $referencedColumns = [],
        public ?string $deleteRule = null,
        public ?string $updateRule = null,
        public ?string $checkExpression = null,
    ) {}
}

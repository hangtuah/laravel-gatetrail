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
 * `$referencedSchema` completes a foreign key's identity. Two schemas may each
 * hold a table of the same name, so a reference is only canonical when its
 * namespace, table and ordered columns all match. It is null for every
 * non-foreign constraint.
 *
 * `$deferrable` and `$deferred` carry `condeferrable` and `condeferred`. They
 * matter because PostgreSQL lets a `NO ACTION` check be postponed to commit
 * time, which `RESTRICT` cannot be — so the two actions are only interchangeable
 * while the constraint is `NOT DEFERRABLE`. Both default to false, which is the
 * canonical state and what PostgreSQL reports for every constraint the release
 * `0001` artifacts and the package migrations create.
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
        public bool $deferrable = false,
        public bool $deferred = false,
        public ?string $referencedSchema = null,
        public ?string $referencedTable = null,
        public array $referencedColumns = [],
        public ?string $deleteRule = null,
        public ?string $updateRule = null,
        public ?string $checkExpression = null,
    ) {}
}

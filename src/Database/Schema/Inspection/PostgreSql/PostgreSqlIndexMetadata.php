<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Inspection\PostgreSql;

/**
 * One normalized `pg_index` entry with its ordered key columns.
 *
 * `$constraint` names the primary-key or unique constraint the index backs, or
 * null for an explicitly created index. PostgreSQL creates one index per
 * primary-key and unique constraint, so this field is what separates the
 * manifest's explicit indexes from constraint-backed ones.
 *
 * `$complete` is false when PostgreSQL reports an expression index, a partial
 * index or a key position with no resolvable column. Such an index cannot be
 * compared structurally against the manifest, so the inspector fails closed
 * instead of guessing.
 *
 * @internal
 */
final readonly class PostgreSqlIndexMetadata
{
    /**
     * @param  list<string>  $columns
     */
    public function __construct(
        public string $table,
        public string $name,
        public array $columns,
        public bool $unique,
        public bool $primary,
        public bool $valid,
        public bool $live,
        public bool $complete,
        public ?string $constraint = null,
    ) {}
}

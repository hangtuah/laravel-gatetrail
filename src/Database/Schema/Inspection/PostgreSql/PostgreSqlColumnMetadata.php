<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Inspection\PostgreSql;

/**
 * One normalized `information_schema.columns` row.
 *
 * `$collation` is null when the column uses the database's default collation
 * and carries the collation name only when one was declared explicitly, which
 * is how GateTrail distinguishes a deterministic `C` column from a column that
 * merely inherited a deterministic database default.
 *
 * @internal
 */
final readonly class PostgreSqlColumnMetadata
{
    public function __construct(
        public string $table,
        public string $name,
        public string $dataType,
        public bool $nullable,
        public ?int $characterLength,
        public ?int $datetimePrecision,
        public ?string $collation,
        public ?string $default,
    ) {}
}

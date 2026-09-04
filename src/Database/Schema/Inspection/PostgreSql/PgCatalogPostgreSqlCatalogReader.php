<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Inspection\PostgreSql;

use HangTuah\GateTrail\Database\Schema\Manifest\SchemaManifest;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * The only place PostgreSQL-specific catalog SQL is written.
 *
 * # Read-only by construction
 *
 * Every statement this class issues is a `SELECT` against `pg_catalog` and
 * `information_schema`. It never emits `CREATE`, `ALTER`, `DROP`, `COMMENT`,
 * `GRANT`, `SET`, repair SQL or an advisory lock, and it opens no transaction.
 * A schema it cannot read is reported, never provisioned.
 *
 * # Nothing consumer-supplied reaches the SQL text
 *
 * The schema name and all seven configured table names arrive as bindings, and
 * every one of them has already passed {@see SchemaContext::validateAll()} and
 * its `IdentifierValidator` boundary. The only value this class composes into a
 * query is the `?` placeholder run for the table list, whose length comes from
 * the canonical manifest rather than from configuration.
 *
 * # Catalog sources
 *
 * - tables — `pg_class` restricted to ordinary relations (`relkind = 'r'`);
 * - columns — `information_schema.columns`, whose `collation_name` is null for
 *   the database default and set only for an explicitly declared collation;
 * - indexes — `pg_index` with `indkey` expanded in key order, joined to
 *   `pg_constraint` so a constraint-backed index is distinguishable from an
 *   explicitly created one;
 * - constraints — `pg_constraint` with `conkey`/`confkey` expanded in declared
 *   order, `pg_namespace` joined through `confrelid` so a foreign key's
 *   referenced schema is known and not merely assumed from its table name,
 *   `condeferrable`/`condeferred` for its deferrability, and
 *   `pg_get_constraintdef()` for the check expression;
 * - identities — projected from the same column rows, using their identity
 *   flags and, for the `bigserial` representation, their `nextval(...)`
 *   default, so no extra round trip is made and the two cannot disagree.
 *
 * @internal
 */
final readonly class PgCatalogPostgreSqlCatalogReader implements PostgreSqlCatalogReader
{
    /**
     * PostgreSQL's `confdeltype`/`confupdtype` action codes.
     */
    private const REFERENTIAL_ACTIONS = [
        'a' => 'NO ACTION',
        'r' => 'RESTRICT',
        'c' => 'CASCADE',
        'n' => 'SET NULL',
        'd' => 'SET DEFAULT',
    ];

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function read(
        SchemaManifest $manifest,
        SchemaContext $context,
    ): PostgreSqlCatalogSnapshot {
        $context->validateAll();

        $schema = $this->schema($context);
        $tables = $this->configuredTables($manifest, $context);
        $present = $this->readTables($schema, $tables);
        $columnRows = $this->readColumnRows($schema, $tables);
        $indexes = $this->readIndexes($schema, $tables);
        $constraints = $this->readConstraints($schema, $tables);

        return new PostgreSqlCatalogSnapshot(
            schema: $schema,
            tables: $present,
            columns: $this->mapColumns($columnRows),
            indexes: $indexes,
            constraints: $constraints,
            identities: $this->mapIdentities($columnRows),
        );
    }

    /**
     * @return list<string>
     */
    private function configuredTables(
        SchemaManifest $manifest,
        SchemaContext $context,
    ): array {
        return array_map(
            static fn (string $key): string => $context->tableName($key),
            $manifest->creationOrder(),
        );
    }

    private function schema(SchemaContext $context): string
    {
        $configured = $context->schemaName();

        if ($configured !== null) {
            return $configured;
        }

        $rows = $this->select(
            <<<'SQL'
                SELECT current_schema() AS schema_name
                SQL,
        );
        $schema = isset($rows[0])
            ? self::nullableString(self::value($rows[0], 'schema_name'))
            : null;

        if ($schema === null || $schema === '') {
            throw new RuntimeException(
                'GateTrail could not resolve the current PostgreSQL schema.',
            );
        }

        return $schema;
    }

    /**
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function readTables(string $schema, array $tables): array
    {
        $rows = $this->select(
            sprintf(
                <<<'SQL'
                    SELECT c.relname AS table_name
                    FROM pg_catalog.pg_class c
                    INNER JOIN pg_catalog.pg_namespace n
                        ON n.oid = c.relnamespace
                    WHERE n.nspname = ?
                      AND c.relkind = 'r'
                      AND c.relname IN (%s)
                    ORDER BY c.relname
                    SQL,
                $this->placeholders($tables),
            ),
            [$schema, ...$tables],
        );

        return array_map(
            static fn (object $row): string => (string) self::value(
                $row,
                'table_name',
            ),
            $rows,
        );
    }

    /**
     * The single column read. Both {@see self::mapColumns()} and
     * {@see self::mapIdentities()} project these rows, so the column shape and
     * the generated-key shape can never disagree.
     *
     * @param  list<string>  $tables
     * @return list<object>
     */
    private function readColumnRows(string $schema, array $tables): array
    {
        return $this->select(
            sprintf(
                <<<'SQL'
                    SELECT
                        table_name,
                        column_name,
                        data_type,
                        is_nullable,
                        character_maximum_length,
                        datetime_precision,
                        collation_name,
                        column_default,
                        is_identity,
                        identity_generation
                    FROM information_schema.columns
                    WHERE table_schema = ?
                      AND table_name IN (%s)
                    ORDER BY table_name, ordinal_position
                    SQL,
                $this->placeholders($tables),
            ),
            [$schema, ...$tables],
        );
    }

    /**
     * @param  list<object>  $rows
     * @return list<PostgreSqlColumnMetadata>
     */
    private function mapColumns(array $rows): array
    {
        return array_map(
            static fn (object $row): PostgreSqlColumnMetadata => new PostgreSqlColumnMetadata(
                table: (string) self::value($row, 'table_name'),
                name: (string) self::value($row, 'column_name'),
                dataType: strtolower((string) self::value($row, 'data_type')),
                nullable: self::value($row, 'is_nullable') === 'YES',
                characterLength: self::nullableInt(
                    self::value($row, 'character_maximum_length'),
                ),
                datetimePrecision: self::nullableInt(
                    self::value($row, 'datetime_precision'),
                ),
                collation: self::nullableString(
                    self::value($row, 'collation_name'),
                ),
                default: self::nullableString(
                    self::value($row, 'column_default'),
                ),
            ),
            $rows,
        );
    }

    /**
     * @param  list<string>  $tables
     * @return list<PostgreSqlIndexMetadata>
     */
    private function readIndexes(string $schema, array $tables): array
    {
        $rows = $this->select(
            sprintf(
                <<<'SQL'
                    SELECT
                        t.relname AS table_name,
                        i.relname AS index_name,
                        ix.indisunique AS is_unique,
                        ix.indisprimary AS is_primary,
                        ix.indisvalid AS is_valid,
                        ix.indislive AS is_live,
                        (ix.indpred IS NOT NULL) AS is_partial,
                        (ix.indexprs IS NOT NULL) AS has_expressions,
                        con.conname AS constraint_name,
                        a.attname AS column_name
                    FROM pg_catalog.pg_index ix
                    INNER JOIN pg_catalog.pg_class i
                        ON i.oid = ix.indexrelid
                    INNER JOIN pg_catalog.pg_class t
                        ON t.oid = ix.indrelid
                    INNER JOIN pg_catalog.pg_namespace n
                        ON n.oid = t.relnamespace
                    LEFT JOIN pg_catalog.pg_constraint con
                        ON con.conindid = ix.indexrelid
                       AND con.contype IN ('p', 'u')
                    CROSS JOIN LATERAL
                        unnest(ix.indkey) WITH ORDINALITY AS k(attnum, ord)
                    LEFT JOIN pg_catalog.pg_attribute a
                        ON a.attrelid = t.oid
                       AND a.attnum = k.attnum
                    WHERE n.nspname = ?
                      AND t.relkind = 'r'
                      AND t.relname IN (%s)
                    ORDER BY t.relname, i.relname, k.ord
                    SQL,
                $this->placeholders($tables),
            ),
            [$schema, ...$tables],
        );

        /**
         * @var array<string, array{
         *     table: string,
         *     name: string,
         *     columns: list<string>,
         *     unique: bool,
         *     primary: bool,
         *     valid: bool,
         *     live: bool,
         *     complete: bool,
         *     constraint: ?string
         * }> $grouped
         */
        $grouped = [];

        foreach ($rows as $row) {
            $table = (string) self::value($row, 'table_name');
            $name = (string) self::value($row, 'index_name');
            $key = $table."\0".$name;

            $grouped[$key] ??= [
                'table' => $table,
                'name' => $name,
                'columns' => [],
                'unique' => self::boolean(self::value($row, 'is_unique')),
                'primary' => self::boolean(self::value($row, 'is_primary')),
                'valid' => self::boolean(self::value($row, 'is_valid')),
                'live' => self::boolean(self::value($row, 'is_live')),
                'complete' => ! self::boolean(self::value($row, 'is_partial'))
                    && ! self::boolean(self::value($row, 'has_expressions')),
                'constraint' => self::nullableString(
                    self::value($row, 'constraint_name'),
                ),
            ];

            $column = self::nullableString(self::value($row, 'column_name'));

            if ($column === null) {
                $grouped[$key]['complete'] = false;

                continue;
            }

            $grouped[$key]['columns'][] = $column;
        }

        return array_values(array_map(
            static fn (array $index): PostgreSqlIndexMetadata => new PostgreSqlIndexMetadata(
                table: $index['table'],
                name: $index['name'],
                columns: $index['columns'],
                unique: $index['unique'],
                primary: $index['primary'],
                valid: $index['valid'],
                live: $index['live'],
                complete: $index['complete'],
                constraint: $index['constraint'],
            ),
            $grouped,
        ));
    }

    /**
     * @param  list<string>  $tables
     * @return list<PostgreSqlConstraintMetadata>
     */
    private function readConstraints(string $schema, array $tables): array
    {
        $rows = $this->select(
            sprintf(
                <<<'SQL'
                    SELECT
                        t.relname AS table_name,
                        con.conname AS constraint_name,
                        con.contype AS constraint_type,
                        con.convalidated AS is_validated,
                        con.condeferrable AS is_deferrable,
                        con.condeferred AS is_deferred,
                        con.confdeltype AS delete_action,
                        con.confupdtype AS update_action,
                        rn.nspname AS referenced_schema,
                        rt.relname AS referenced_table,
                        pg_catalog.pg_get_constraintdef(con.oid) AS definition,
                        a.attname AS column_name,
                        ra.attname AS referenced_column
                    FROM pg_catalog.pg_constraint con
                    INNER JOIN pg_catalog.pg_class t
                        ON t.oid = con.conrelid
                    INNER JOIN pg_catalog.pg_namespace n
                        ON n.oid = t.relnamespace
                    LEFT JOIN pg_catalog.pg_class rt
                        ON rt.oid = con.confrelid
                    LEFT JOIN pg_catalog.pg_namespace rn
                        ON rn.oid = rt.relnamespace
                    LEFT JOIN LATERAL
                        unnest(con.conkey) WITH ORDINALITY AS k(attnum, ord)
                        ON TRUE
                    LEFT JOIN pg_catalog.pg_attribute a
                        ON a.attrelid = con.conrelid
                       AND a.attnum = k.attnum
                    LEFT JOIN LATERAL
                        unnest(con.confkey) WITH ORDINALITY AS rk(attnum, ord)
                        ON rk.ord = k.ord
                    LEFT JOIN pg_catalog.pg_attribute ra
                        ON ra.attrelid = con.confrelid
                       AND ra.attnum = rk.attnum
                    WHERE n.nspname = ?
                      AND con.contype IN ('p', 'u', 'f', 'c')
                      AND t.relname IN (%s)
                    ORDER BY t.relname, con.conname, k.ord
                    SQL,
                $this->placeholders($tables),
            ),
            [$schema, ...$tables],
        );

        /**
         * @var array<string, array{
         *     table: string,
         *     name: string,
         *     type: string,
         *     columns: list<string>,
         *     validated: bool,
         *     deferrable: bool,
         *     deferred: bool,
         *     referenced_schema: ?string,
         *     referenced_table: ?string,
         *     referenced_columns: list<string>,
         *     delete_rule: ?string,
         *     update_rule: ?string,
         *     check_expression: ?string
         * }> $grouped
         */
        $grouped = [];

        foreach ($rows as $row) {
            $table = (string) self::value($row, 'table_name');
            $name = (string) self::value($row, 'constraint_name');
            $type = strtolower((string) self::value($row, 'constraint_type'));
            $key = $table."\0".$name;

            $grouped[$key] ??= [
                'table' => $table,
                'name' => $name,
                'type' => $type,
                'columns' => [],
                'validated' => self::boolean(
                    self::value($row, 'is_validated'),
                ),
                'deferrable' => self::boolean(
                    self::value($row, 'is_deferrable'),
                ),
                'deferred' => self::boolean(
                    self::value($row, 'is_deferred'),
                ),
                'referenced_schema' => self::nullableString(
                    self::value($row, 'referenced_schema'),
                ),
                'referenced_table' => self::nullableString(
                    self::value($row, 'referenced_table'),
                ),
                'referenced_columns' => [],
                'delete_rule' => $type === 'f'
                    ? self::referentialAction(
                        self::value($row, 'delete_action'),
                    )
                    : null,
                'update_rule' => $type === 'f'
                    ? self::referentialAction(
                        self::value($row, 'update_action'),
                    )
                    : null,
                'check_expression' => $type === 'c'
                    ? self::checkExpression(self::value($row, 'definition'))
                    : null,
            ];

            $column = self::nullableString(self::value($row, 'column_name'));

            if ($column !== null) {
                $grouped[$key]['columns'][] = $column;
            }

            $referencedColumn = self::nullableString(
                self::value($row, 'referenced_column'),
            );

            if ($referencedColumn !== null) {
                $grouped[$key]['referenced_columns'][] = $referencedColumn;
            }
        }

        return array_values(array_map(
            static fn (array $constraint): PostgreSqlConstraintMetadata => new PostgreSqlConstraintMetadata(
                table: $constraint['table'],
                name: $constraint['name'],
                type: $constraint['type'],
                columns: $constraint['columns'],
                validated: $constraint['validated'],
                deferrable: $constraint['deferrable'],
                deferred: $constraint['deferred'],
                referencedSchema: $constraint['referenced_schema'],
                referencedTable: $constraint['referenced_table'],
                referencedColumns: $constraint['referenced_columns'],
                deleteRule: $constraint['delete_rule'],
                updateRule: $constraint['update_rule'],
                checkExpression: $constraint['check_expression'],
            ),
            $grouped,
        ));
    }

    /**
     * PostgreSQL has two representations of the same generated-key contract, and
     * both are read from the column row that already carries them: the identity
     * flags for `GENERATED ... AS IDENTITY`, and a `nextval(...)` default for
     * the `bigserial` form Laravel's grammar emits.
     *
     * @param  list<object>  $rows
     * @return list<PostgreSqlIdentityMetadata>
     */
    private function mapIdentities(array $rows): array
    {
        $identities = [];

        foreach ($rows as $row) {
            $table = (string) self::value($row, 'table_name');
            $column = (string) self::value($row, 'column_name');

            if (self::value($row, 'is_identity') === 'YES') {
                $identities[] = new PostgreSqlIdentityMetadata(
                    table: $table,
                    column: $column,
                    generation: strtoupper(
                        (string) self::value($row, 'identity_generation'),
                    ) === 'ALWAYS'
                        ? PostgreSqlIdentityGeneration::Always
                        : PostgreSqlIdentityGeneration::ByDefault,
                );

                continue;
            }

            $sequence = self::sequenceDefault(
                self::nullableString(self::value($row, 'column_default')),
            );

            if ($sequence === null) {
                continue;
            }

            $identities[] = new PostgreSqlIdentityMetadata(
                table: $table,
                column: $column,
                generation: PostgreSqlIdentityGeneration::SequenceDefault,
                sequence: $sequence,
            );
        }

        return $identities;
    }

    /**
     * Builds the `?, ?, ...` run for a bound `IN` list. Its length comes from
     * the canonical manifest, and every value is bound rather than written
     * into the query.
     *
     * @param  list<string>  $values
     */
    private function placeholders(array $values): string
    {
        if ($values === []) {
            throw new RuntimeException(
                'GateTrail resolved no PostgreSQL tables to inspect.',
            );
        }

        return implode(', ', array_fill(0, count($values), '?'));
    }

    /**
     * @param  list<string>  $bindings
     * @return list<object>
     */
    private function select(string $query, array $bindings = []): array
    {
        /** @var list<object> $rows */
        $rows = $this->connection->select($query, $bindings, true);

        return $rows;
    }

    /**
     * Extracts the expression from `pg_get_constraintdef()` output, which is
     * always `CHECK (<expression>)` for a check constraint. Anything else is
     * reported as missing rather than guessed at.
     */
    private static function checkExpression(int|string|null $definition): ?string
    {
        $definition = self::nullableString($definition);

        if ($definition === null) {
            return null;
        }

        $trimmed = trim($definition);

        if (preg_match('/^CHECK\s*\((.*)\)$/is', $trimmed, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Returns the sequence a `bigserial` column defaults from, or null when the
     * column has no `nextval(...)` default.
     */
    private static function sequenceDefault(?string $default): ?string
    {
        if ($default === null) {
            return null;
        }

        if (preg_match(
            "/^nextval\(\s*'([^']+)'/i",
            trim($default),
            $matches,
        ) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private static function referentialAction(int|string|null $code): ?string
    {
        $code = self::nullableString($code);

        if ($code === null) {
            return null;
        }

        return self::REFERENTIAL_ACTIONS[strtolower($code)] ?? null;
    }

    private static function value(object $row, string $key): int|string|null
    {
        foreach ((array) $row as $column => $candidate) {
            if (strcasecmp((string) $column, $key) !== 0) {
                continue;
            }

            if (is_bool($candidate)) {
                return $candidate ? 1 : 0;
            }

            if (is_int($candidate) || is_string($candidate)) {
                return $candidate;
            }

            return null;
        }

        return null;
    }

    /**
     * PostgreSQL booleans arrive as a native bool, `1`/`0`, or `t`/`f`
     * depending on the driver's emulation settings.
     */
    private static function boolean(int|string|null $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        return in_array(strtolower($value), ['t', 'true', '1'], true);
    }

    private static function nullableString(int|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) $value;

        return trim($value, " \0") === '' ? null : $value;
    }

    private static function nullableInt(int|string|null $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}

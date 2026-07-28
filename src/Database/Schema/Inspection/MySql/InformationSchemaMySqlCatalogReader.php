<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Inspection\MySql;

use HangTuah\GateTrail\Database\Schema\SchemaContext;
use Illuminate\Database\ConnectionInterface;

final readonly class InformationSchemaMySqlCatalogReader implements MySqlCatalogReader
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function read(SchemaContext $context): MySqlCatalogSnapshot
    {
        $context->validateAll();
        $database = $context->schemaName()
            ?? $this->connection->getDatabaseName();

        return new MySqlCatalogSnapshot(
            tables: $this->readTables($database),
            columns: $this->readColumns($database),
            indexes: $this->readIndexes($database),
            constraints: $this->readConstraints($database),
        );
    }

    /**
     * @return list<string>
     */
    private function readTables(string $database): array
    {
        $rows = $this->select(
            <<<'SQL'
                SELECT table_name
                FROM information_schema.tables
                WHERE table_schema = ?
                  AND table_type = 'BASE TABLE'
                ORDER BY table_name
                SQL,
            $database,
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
     * @return list<MySqlColumnMetadata>
     */
    private function readColumns(string $database): array
    {
        $rows = $this->select(
            <<<'SQL'
                SELECT
                    table_name,
                    column_name,
                    data_type,
                    column_type,
                    is_nullable,
                    character_maximum_length,
                    extra,
                    collation_name
                FROM information_schema.columns
                WHERE table_schema = ?
                ORDER BY table_name, ordinal_position
                SQL,
            $database,
        );

        return array_map(
            static fn (object $row): MySqlColumnMetadata => new MySqlColumnMetadata(
                table: (string) self::value($row, 'table_name'),
                name: (string) self::value($row, 'column_name'),
                dataType: strtolower((string) self::value($row, 'data_type')),
                columnType: strtolower((string) self::value($row, 'column_type')),
                nullable: self::value($row, 'is_nullable') === 'YES',
                length: self::nullableInt(
                    self::value($row, 'character_maximum_length'),
                ),
                extra: strtolower((string) self::value($row, 'extra')),
                collation: self::nullableString(
                    self::value($row, 'collation_name'),
                ),
            ),
            $rows,
        );
    }

    /**
     * @return list<MySqlIndexMetadata>
     */
    private function readIndexes(string $database): array
    {
        $rows = $this->select(
            <<<'SQL'
                SELECT
                    table_name,
                    index_name,
                    non_unique,
                    column_name
                FROM information_schema.statistics
                WHERE table_schema = ?
                ORDER BY table_name, index_name, seq_in_index
                SQL,
            $database,
        );

        /**
         * @var array<string, array{
         *     table: string,
         *     name: string,
         *     unique: bool,
         *     columns: list<string>
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
                'unique' => (int) self::value($row, 'non_unique') === 0,
                'columns' => [],
            ];
            $grouped[$key]['columns'][] = (string) self::value(
                $row,
                'column_name',
            );
        }

        return array_values(array_map(
            static fn (array $index): MySqlIndexMetadata => new MySqlIndexMetadata(
                table: $index['table'],
                name: $index['name'],
                columns: $index['columns'],
                unique: $index['unique'],
            ),
            $grouped,
        ));
    }

    /**
     * @return list<MySqlConstraintMetadata>
     */
    private function readConstraints(string $database): array
    {
        $rows = $this->select(
            <<<'SQL'
                SELECT
                    tc.table_name,
                    tc.constraint_name,
                    tc.constraint_type,
                    kcu.column_name,
                    kcu.referenced_table_name,
                    kcu.referenced_column_name,
                    rc.delete_rule,
                    rc.update_rule,
                    cc.check_clause
                FROM information_schema.table_constraints AS tc
                LEFT JOIN information_schema.key_column_usage AS kcu
                  ON kcu.constraint_schema = tc.constraint_schema
                 AND kcu.table_name = tc.table_name
                 AND kcu.constraint_name = tc.constraint_name
                LEFT JOIN information_schema.referential_constraints AS rc
                  ON rc.constraint_schema = tc.constraint_schema
                 AND rc.table_name = tc.table_name
                 AND rc.constraint_name = tc.constraint_name
                LEFT JOIN information_schema.check_constraints AS cc
                  ON cc.constraint_schema = tc.constraint_schema
                 AND cc.constraint_name = tc.constraint_name
                WHERE tc.constraint_schema = ?
                ORDER BY
                    tc.table_name,
                    tc.constraint_name,
                    kcu.ordinal_position
                SQL,
            $database,
        );

        /**
         * @var array<string, array{
         *     table: string,
         *     name: string,
         *     type: string,
         *     columns: list<string>,
         *     referenced_table: ?string,
         *     referenced_columns: list<string>,
         *     delete_rule: ?string,
         *     update_rule: ?string,
         *     check_clause: ?string
         * }> $grouped
         */
        $grouped = [];

        foreach ($rows as $row) {
            $table = (string) self::value($row, 'table_name');
            $name = (string) self::value($row, 'constraint_name');
            $key = $table."\0".$name;

            $grouped[$key] ??= [
                'table' => $table,
                'name' => $name,
                'type' => strtoupper(
                    (string) self::value($row, 'constraint_type'),
                ),
                'columns' => [],
                'referenced_table' => self::nullableString(
                    self::value($row, 'referenced_table_name'),
                ),
                'referenced_columns' => [],
                'delete_rule' => self::nullableString(
                    self::value($row, 'delete_rule'),
                ),
                'update_rule' => self::nullableString(
                    self::value($row, 'update_rule'),
                ),
                'check_clause' => self::nullableString(
                    self::value($row, 'check_clause'),
                ),
            ];

            $column = self::nullableString(self::value($row, 'column_name'));

            if ($column !== null) {
                $grouped[$key]['columns'][] = $column;
            }

            $referencedColumn = self::nullableString(
                self::value($row, 'referenced_column_name'),
            );

            if ($referencedColumn !== null) {
                $grouped[$key]['referenced_columns'][] = $referencedColumn;
            }
        }

        return array_values(array_map(
            static fn (array $constraint): MySqlConstraintMetadata => new MySqlConstraintMetadata(
                table: $constraint['table'],
                name: $constraint['name'],
                type: $constraint['type'],
                columns: $constraint['columns'],
                referencedTable: $constraint['referenced_table'],
                referencedColumns: $constraint['referenced_columns'],
                deleteRule: $constraint['delete_rule'],
                updateRule: $constraint['update_rule'],
                checkClause: $constraint['check_clause'],
            ),
            $grouped,
        ));
    }

    /**
     * @return list<object>
     */
    private function select(string $query, string $database): array
    {
        /** @var list<object> $rows */
        $rows = $this->connection->select($query, [$database], true);

        return $rows;
    }

    private static function value(
        object $row,
        string $key,
    ): int|string|null {
        $value = ((array) $row)[$key] ?? null;

        if (is_int($value) || is_string($value)) {
            return $value;
        }

        return null;
    }

    private static function nullableString(
        int|string|null $value,
    ): ?string {
        return $value === null ? null : (string) $value;
    }

    private static function nullableInt(
        int|string|null $value,
    ): ?int {
        return $value === null ? null : (int) $value;
    }
}

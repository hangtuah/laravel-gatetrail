<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Inspection\Oracle;

use HangTuah\GateTrail\Database\Schema\SchemaContext;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * @internal
 */
final readonly class AllViewsOracleCatalogReader implements OracleCatalogReader
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function read(SchemaContext $context): OracleCatalogSnapshot
    {
        $context->validateAll();
        $owner = $this->owner($context);

        return new OracleCatalogSnapshot(
            owner: $owner,
            tables: $this->readTables($owner),
            columns: $this->readColumns($owner),
            indexes: $this->readIndexes($owner),
            constraints: $this->readConstraints($owner),
            identities: $this->readIdentities($owner),
            sequences: $this->readSequences($owner),
            triggers: $this->readTriggers($owner),
        );
    }

    private function owner(SchemaContext $context): string
    {
        $configured = $context->schemaName();

        if ($configured !== null) {
            return strtoupper($configured);
        }

        $rows = $this->select(
            <<<'SQL'
                SELECT SYS_CONTEXT('USERENV', 'CURRENT_SCHEMA') AS schema_name
                FROM dual
                SQL,
        );
        $owner = isset($rows[0])
            ? self::nullableString(self::value($rows[0], 'schema_name'))
            : null;

        if ($owner === null || $owner === '') {
            throw new RuntimeException(
                'GateTrail could not resolve the current Oracle schema.',
            );
        }

        return strtoupper($owner);
    }

    /**
     * @return list<string>
     */
    private function readTables(string $owner): array
    {
        $rows = $this->select(
            <<<'SQL'
                SELECT table_name
                FROM all_tables
                WHERE owner = ?
                ORDER BY table_name
                SQL,
            [$owner],
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
     * @return list<OracleColumnMetadata>
     */
    private function readColumns(string $owner): array
    {
        $rows = $this->select(
            <<<'SQL'
                SELECT
                    table_name,
                    column_name,
                    data_type,
                    nullable,
                    char_length,
                    char_used,
                    data_precision,
                    data_scale,
                    identity_column,
                    default_on_null
                FROM all_tab_columns
                WHERE owner = ?
                ORDER BY table_name, column_id
                SQL,
            [$owner],
        );

        return array_map(
            static fn (object $row): OracleColumnMetadata => new OracleColumnMetadata(
                table: (string) self::value($row, 'table_name'),
                name: (string) self::value($row, 'column_name'),
                dataType: strtoupper((string) self::value($row, 'data_type')),
                nullable: strtoupper(
                    (string) self::value($row, 'nullable'),
                ) === 'Y',
                charLength: self::nullableInt(
                    self::value($row, 'char_length'),
                ),
                charUsed: self::uppercaseNullable(
                    self::value($row, 'char_used'),
                ),
                precision: self::nullableInt(
                    self::value($row, 'data_precision'),
                ),
                scale: self::nullableInt(self::value($row, 'data_scale')),
                identity: strtoupper(
                    (string) self::value($row, 'identity_column'),
                ) === 'YES',
                defaultOnNull: strtoupper(
                    (string) self::value($row, 'default_on_null'),
                ) === 'YES',
            ),
            $rows,
        );
    }

    /**
     * @return list<OracleIndexMetadata>
     */
    private function readIndexes(string $owner): array
    {
        $rows = $this->select(
            <<<'SQL'
                SELECT
                    i.table_name,
                    i.index_name,
                    i.uniqueness,
                    i.status,
                    c.column_name
                FROM all_indexes i
                INNER JOIN all_ind_columns c
                    ON c.index_owner = i.owner
                   AND c.index_name = i.index_name
                   AND c.table_owner = i.table_owner
                   AND c.table_name = i.table_name
                WHERE i.table_owner = ?
                ORDER BY i.table_name, i.index_name, c.column_position
                SQL,
            [$owner],
        );

        /**
         * @var array<string, array{
         *     table: string,
         *     name: string,
         *     columns: list<string>,
         *     unique: bool,
         *     status: string
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
                'unique' => strtoupper(
                    (string) self::value($row, 'uniqueness'),
                ) === 'UNIQUE',
                'status' => strtoupper(
                    (string) self::value($row, 'status'),
                ),
            ];
            $grouped[$key]['columns'][] = (string) self::value(
                $row,
                'column_name',
            );
        }

        return array_values(array_map(
            static fn (array $index): OracleIndexMetadata => new OracleIndexMetadata(
                table: $index['table'],
                name: $index['name'],
                columns: $index['columns'],
                unique: $index['unique'],
                status: $index['status'],
            ),
            $grouped,
        ));
    }

    /**
     * @return list<OracleConstraintMetadata>
     */
    private function readConstraints(string $owner): array
    {
        $rows = $this->select(
            <<<'SQL'
                SELECT
                    c.table_name,
                    c.constraint_name,
                    c.constraint_type,
                    cc.column_name,
                    rc.table_name AS referenced_table_name,
                    rcc.column_name AS referenced_column_name,
                    c.delete_rule,
                    c.status,
                    c.validated,
                    c.search_condition_vc
                FROM all_constraints c
                LEFT JOIN all_cons_columns cc
                    ON cc.owner = c.owner
                   AND cc.constraint_name = c.constraint_name
                   AND cc.table_name = c.table_name
                LEFT JOIN all_constraints rc
                    ON rc.owner = c.r_owner
                   AND rc.constraint_name = c.r_constraint_name
                LEFT JOIN all_cons_columns rcc
                    ON rcc.owner = rc.owner
                   AND rcc.constraint_name = rc.constraint_name
                   AND rcc.table_name = rc.table_name
                   AND rcc.position = cc.position
                WHERE c.owner = ?
                  AND c.constraint_type IN ('P', 'U', 'R', 'C')
                ORDER BY c.table_name, c.constraint_name, cc.position
                SQL,
            [$owner],
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
         *     status: string,
         *     validated: string,
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
                'delete_rule' => self::uppercaseNullable(
                    self::value($row, 'delete_rule'),
                ),
                'status' => strtoupper(
                    (string) self::value($row, 'status'),
                ),
                'validated' => strtoupper(
                    (string) self::value($row, 'validated'),
                ),
                'check_clause' => self::nullableString(
                    self::value($row, 'search_condition_vc'),
                ),
            ];

            $column = self::nullableString(
                self::value($row, 'column_name'),
            );

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
            static fn (array $constraint): OracleConstraintMetadata => new OracleConstraintMetadata(
                table: $constraint['table'],
                name: $constraint['name'],
                type: $constraint['type'],
                columns: $constraint['columns'],
                referencedTable: $constraint['referenced_table'],
                referencedColumns: $constraint['referenced_columns'],
                deleteRule: $constraint['delete_rule'],
                status: $constraint['status'],
                validated: $constraint['validated'],
                checkClause: $constraint['check_clause'],
            ),
            $grouped,
        ));
    }

    /**
     * @return list<OracleIdentityMetadata>
     */
    private function readIdentities(string $owner): array
    {
        $rows = $this->select(
            <<<'SQL'
                SELECT table_name, column_name, generation_type
                FROM all_tab_identity_cols
                WHERE owner = ?
                ORDER BY table_name, column_name
                SQL,
            [$owner],
        );

        return array_map(
            static fn (object $row): OracleIdentityMetadata => new OracleIdentityMetadata(
                table: (string) self::value($row, 'table_name'),
                column: (string) self::value($row, 'column_name'),
                generationType: strtoupper(
                    (string) self::value($row, 'generation_type'),
                ),
            ),
            $rows,
        );
    }

    /**
     * @return list<OracleSequenceMetadata>
     */
    private function readSequences(string $owner): array
    {
        $rows = $this->select(
            <<<'SQL'
                SELECT sequence_name, increment_by, cycle_flag
                FROM all_sequences
                WHERE sequence_owner = ?
                ORDER BY sequence_name
                SQL,
            [$owner],
        );

        return array_map(
            static fn (object $row): OracleSequenceMetadata => new OracleSequenceMetadata(
                name: (string) self::value($row, 'sequence_name'),
                incrementBy: (int) self::value($row, 'increment_by'),
                cycles: strtoupper(
                    (string) self::value($row, 'cycle_flag'),
                ) === 'Y',
            ),
            $rows,
        );
    }

    /**
     * @return list<OracleTriggerMetadata>
     */
    private function readTriggers(string $owner): array
    {
        $rows = $this->select(
            <<<'SQL'
                SELECT
                    trigger_name,
                    table_name,
                    trigger_type,
                    triggering_event,
                    status,
                    trigger_body
                FROM all_triggers
                WHERE owner = ?
                  AND table_owner = ?
                ORDER BY trigger_name
                SQL,
            [$owner, $owner],
        );

        return array_map(
            static fn (object $row): OracleTriggerMetadata => new OracleTriggerMetadata(
                name: (string) self::value($row, 'trigger_name'),
                table: (string) self::value($row, 'table_name'),
                type: strtoupper((string) self::value($row, 'trigger_type')),
                event: strtoupper(
                    (string) self::value($row, 'triggering_event'),
                ),
                status: strtoupper((string) self::value($row, 'status')),
                body: (string) self::value($row, 'trigger_body'),
            ),
            $rows,
        );
    }

    /**
     * @param  list<int|string|null>  $bindings
     * @return list<object>
     */
    private function select(string $query, array $bindings = []): array
    {
        /** @var list<object> $rows */
        $rows = $this->connection->select($query, $bindings, true);

        return $rows;
    }

    private static function value(
        object $row,
        string $key,
    ): int|string|null {
        foreach ((array) $row as $column => $candidate) {
            if (
                strcasecmp((string) $column, $key) === 0
                && (is_int($candidate) || is_string($candidate))
            ) {
                return $candidate;
            }
        }

        return null;
    }

    private static function nullableString(int|string|null $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private static function uppercaseNullable(int|string|null $value): ?string
    {
        $value = self::nullableString($value);

        return $value === null ? null : strtoupper($value);
    }

    private static function nullableInt(int|string|null $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}

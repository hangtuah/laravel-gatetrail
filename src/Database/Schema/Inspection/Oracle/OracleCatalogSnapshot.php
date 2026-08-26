<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Inspection\Oracle;

/**
 * @internal
 */
final readonly class OracleCatalogSnapshot
{
    /**
     * @param  list<string>  $tables
     * @param  list<OracleColumnMetadata>  $columns
     * @param  list<OracleIndexMetadata>  $indexes
     * @param  list<OracleConstraintMetadata>  $constraints
     * @param  list<OracleIdentityMetadata>  $identities
     * @param  list<OracleSequenceMetadata>  $sequences
     * @param  list<OracleTriggerMetadata>  $triggers
     */
    public function __construct(
        public string $owner,
        public array $tables,
        public array $columns,
        public array $indexes,
        public array $constraints,
        public array $identities,
        public array $sequences,
        public array $triggers,
    ) {}

    public function hasTable(string $table): bool
    {
        return in_array($table, $this->tables, true);
    }

    public function column(string $table, string $name): ?OracleColumnMetadata
    {
        foreach ($this->columns as $column) {
            if ($column->table === $table && $column->name === $name) {
                return $column;
            }
        }

        return null;
    }

    public function index(string $table, string $name): ?OracleIndexMetadata
    {
        foreach ($this->indexes as $index) {
            if ($index->table === $table && $index->name === $name) {
                return $index;
            }
        }

        return null;
    }

    public function constraint(
        string $table,
        string $name,
    ): ?OracleConstraintMetadata {
        foreach ($this->constraints as $constraint) {
            if ($constraint->table === $table && $constraint->name === $name) {
                return $constraint;
            }
        }

        return null;
    }

    public function identity(
        string $table,
        string $column,
    ): ?OracleIdentityMetadata {
        foreach ($this->identities as $identity) {
            if ($identity->table === $table && $identity->column === $column) {
                return $identity;
            }
        }

        return null;
    }

    public function sequence(string $name): ?OracleSequenceMetadata
    {
        foreach ($this->sequences as $sequence) {
            if ($sequence->name === $name) {
                return $sequence;
            }
        }

        return null;
    }

    public function trigger(string $name): ?OracleTriggerMetadata
    {
        foreach ($this->triggers as $trigger) {
            if ($trigger->name === $name) {
                return $trigger;
            }
        }

        return null;
    }
}

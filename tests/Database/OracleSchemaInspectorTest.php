<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Database;

use HangTuah\GateTrail\Database\Schema\IdentifierValidator;
use HangTuah\GateTrail\Database\Schema\Inspection\Oracle\OracleCatalogReader;
use HangTuah\GateTrail\Database\Schema\Inspection\Oracle\OracleCatalogSnapshot;
use HangTuah\GateTrail\Database\Schema\Inspection\Oracle\OracleColumnMetadata;
use HangTuah\GateTrail\Database\Schema\Inspection\Oracle\OracleConstraintMetadata;
use HangTuah\GateTrail\Database\Schema\Inspection\Oracle\OracleIdentityMetadata;
use HangTuah\GateTrail\Database\Schema\Inspection\Oracle\OracleIndexMetadata;
use HangTuah\GateTrail\Database\Schema\Inspection\Oracle\OracleSchemaInspector;
use HangTuah\GateTrail\Database\Schema\Inspection\Oracle\OracleSequenceMetadata;
use HangTuah\GateTrail\Database\Schema\Inspection\Oracle\OracleTriggerMetadata;
use HangTuah\GateTrail\Database\Schema\Inspection\SchemaInspection;
use HangTuah\GateTrail\Database\Schema\Manifest\ColumnDefinition;
use HangTuah\GateTrail\Database\Schema\Manifest\ColumnType;
use HangTuah\GateTrail\Database\Schema\Manifest\ConstraintDefinition;
use HangTuah\GateTrail\Database\Schema\Manifest\ConstraintType;
use HangTuah\GateTrail\Database\Schema\Manifest\SchemaManifest;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Tests\TestCase;

final class OracleSchemaInspectorTest extends TestCase
{
    public function test_matching_identity_and_sequence_trigger_catalogs_pass(): void
    {
        foreach (['identity', 'sequence_trigger'] as $strategy) {
            $inspection = $this->inspect(
                $this->matchingSnapshot($strategy),
                $strategy,
            );

            $this->assertTrue($inspection->passes(), $strategy);
            $this->assertSame([], $inspection->issues);
        }
    }

    public function test_table_column_index_and_constraint_drift_are_reported(): void
    {
        $snapshot = $this->matchingSnapshot('sequence_trigger');
        $columns = array_map(
            static fn (OracleColumnMetadata $column): OracleColumnMetadata => (
                $column->table === 'ACL_ROLES' && $column->name === 'NAME'
            ) ? new OracleColumnMetadata(
                table: $column->table,
                name: $column->name,
                dataType: 'CLOB',
                nullable: true,
                charLength: null,
                charUsed: null,
                precision: null,
                scale: null,
                identity: false,
                defaultOnNull: false,
            ) : $column,
            $snapshot->columns,
        );
        $columns[] = new OracleColumnMetadata(
            table: 'ACL_ROLES',
            name: 'LEGACY_FLAG',
            dataType: 'NUMBER',
            nullable: true,
            charLength: null,
            charUsed: null,
            precision: 19,
            scale: 0,
            identity: false,
            defaultOnNull: false,
        );
        $indexes = array_values(array_filter(
            $snapshot->indexes,
            static fn (OracleIndexMetadata $index): bool => (
                $index->name !== 'ACL_RA_SUBJECT_IX'
            ),
        ));
        $constraints = array_values(array_filter(
            $snapshot->constraints,
            static fn (OracleConstraintMetadata $constraint): bool => (
                $constraint->name !== 'ACL_RA_PERIOD_CK'
            ),
        ));
        $snapshot = new OracleCatalogSnapshot(
            $snapshot->owner,
            array_values(array_filter(
                $snapshot->tables,
                static fn (string $table): bool => $table !== 'ACL_PERMISSIONS',
            )),
            $columns,
            $indexes,
            $constraints,
            $snapshot->identities,
            $snapshot->sequences,
            $snapshot->triggers,
        );

        $codes = $this->codes($this->inspect($snapshot, 'sequence_trigger'));

        $this->assertContains('missing_table', $codes);
        $this->assertContains('column_type_mismatch', $codes);
        $this->assertContains('column_nullability_mismatch', $codes);
        $this->assertContains('column_length_mismatch', $codes);
        $this->assertContains('unexpected_column', $codes);
        $this->assertContains('missing_index', $codes);
        $this->assertContains('missing_constraint', $codes);
    }

    public function test_quoted_lowercase_object_name_does_not_match_portable_identifier(): void
    {
        $snapshot = $this->matchingSnapshot('sequence_trigger');
        $snapshot = new OracleCatalogSnapshot(
            $snapshot->owner,
            array_map(
                static fn (string $table): string => $table === 'ACL_ROLES'
                    ? 'acl_roles'
                    : $table,
                $snapshot->tables,
            ),
            $snapshot->columns,
            $snapshot->indexes,
            $snapshot->constraints,
            $snapshot->identities,
            $snapshot->sequences,
            $snapshot->triggers,
        );

        $inspection = $this->inspect($snapshot, 'sequence_trigger');

        $this->assertSame('missing_table', $inspection->issues[0]->code);
        $this->assertSame('ACL_ROLES', $inspection->issues[0]->object);
    }

    public function test_constraint_state_reference_rule_and_check_drift_are_reported(): void
    {
        $snapshot = $this->matchingSnapshot('sequence_trigger');
        $constraints = array_map(
            static function (
                OracleConstraintMetadata $constraint,
            ): OracleConstraintMetadata {
                if ($constraint->name === 'ACL_RA_ROLE_FK') {
                    return new OracleConstraintMetadata(
                        table: $constraint->table,
                        name: $constraint->name,
                        type: $constraint->type,
                        columns: $constraint->columns,
                        referencedTable: 'LEGACY_ROLES',
                        referencedColumns: ['LEGACY_ID'],
                        deleteRule: 'CASCADE',
                        status: 'DISABLED',
                        validated: 'NOT VALIDATED',
                        checkClause: null,
                    );
                }

                if ($constraint->name === 'ACL_RA_PERIOD_CK') {
                    return new OracleConstraintMetadata(
                        table: $constraint->table,
                        name: $constraint->name,
                        type: $constraint->type,
                        columns: $constraint->columns,
                        referencedTable: null,
                        referencedColumns: [],
                        deleteRule: null,
                        status: $constraint->status,
                        validated: $constraint->validated,
                        checkClause: 'expires_at IS NULL',
                    );
                }

                return $constraint;
            },
            $snapshot->constraints,
        );
        $snapshot = new OracleCatalogSnapshot(
            $snapshot->owner,
            $snapshot->tables,
            $snapshot->columns,
            $snapshot->indexes,
            $constraints,
            $snapshot->identities,
            $snapshot->sequences,
            $snapshot->triggers,
        );

        $codes = $this->codes($this->inspect($snapshot, 'sequence_trigger'));

        $this->assertContains('constraint_state_mismatch', $codes);
        $this->assertContains('foreign_reference_mismatch', $codes);
        $this->assertContains('foreign_rule_mismatch', $codes);
        $this->assertContains('check_expression_mismatch', $codes);
    }

    public function test_identity_strategy_rejects_wrong_generation_and_mixed_objects(): void
    {
        $snapshot = $this->matchingSnapshot('identity');
        $identities = array_map(
            static fn (OracleIdentityMetadata $identity): OracleIdentityMetadata => (
                $identity->table === 'ACL_ROLES'
            ) ? new OracleIdentityMetadata(
                $identity->table,
                $identity->column,
                'ALWAYS',
            ) : $identity,
            $snapshot->identities,
        );
        $snapshot = new OracleCatalogSnapshot(
            $snapshot->owner,
            $snapshot->tables,
            $snapshot->columns,
            $snapshot->indexes,
            $snapshot->constraints,
            $identities,
            [new OracleSequenceMetadata('ACL_ROLES_SEQ', 1, false)],
            [],
        );

        $codes = $this->codes($this->inspect($snapshot, 'identity'));

        $this->assertContains('identity_strategy_mismatch', $codes);
        $this->assertContains('mixed_generated_key_strategy', $codes);
    }

    public function test_sequence_trigger_strategy_reports_missing_and_invalid_objects(): void
    {
        $snapshot = $this->matchingSnapshot('sequence_trigger');
        $sequences = array_map(
            static fn (OracleSequenceMetadata $sequence): OracleSequenceMetadata => (
                $sequence->name === 'ACL_ROLES_SEQ'
            ) ? new OracleSequenceMetadata($sequence->name, 2, true)
                : $sequence,
            array_values(array_filter(
                $snapshot->sequences,
                static fn (OracleSequenceMetadata $sequence): bool => (
                    $sequence->name !== 'ACL_PERMS_SEQ'
                ),
            )),
        );
        $triggers = array_map(
            static fn (OracleTriggerMetadata $trigger): OracleTriggerMetadata => (
                $trigger->name === 'ACL_ROLES_TRG'
            ) ? new OracleTriggerMetadata(
                $trigger->name,
                $trigger->table,
                'AFTER EACH ROW',
                'UPDATE',
                'DISABLED',
                'BEGIN NULL; END;',
            ) : $trigger,
            array_values(array_filter(
                $snapshot->triggers,
                static fn (OracleTriggerMetadata $trigger): bool => (
                    $trigger->name !== 'ACL_PERMS_TRG'
                ),
            )),
        );
        $snapshot = new OracleCatalogSnapshot(
            $snapshot->owner,
            $snapshot->tables,
            $snapshot->columns,
            $snapshot->indexes,
            $snapshot->constraints,
            $snapshot->identities,
            $sequences,
            $triggers,
        );

        $codes = $this->codes($this->inspect($snapshot, 'sequence_trigger'));

        $this->assertContains('sequence_definition_mismatch', $codes);
        $this->assertContains('missing_sequence', $codes);
        $this->assertContains('trigger_definition_mismatch', $codes);
        $this->assertContains('missing_trigger', $codes);
    }

    public function test_sequence_trigger_accepts_its_configured_schema_and_rejects_another_owner(): void
    {
        $snapshot = $this->matchingSnapshot('sequence_trigger');
        $qualified = $this->withRoleTriggerOwner($snapshot, 'GATETRAIL');

        $this->assertTrue(
            $this->inspect(
                $qualified,
                'sequence_trigger',
                'GATETRAIL',
            )->passes(),
        );

        $wrongOwner = $this->withRoleTriggerOwner(
            $snapshot,
            'OTHER_SCHEMA',
        );
        $codes = $this->codes(
            $this->inspect(
                $wrongOwner,
                'sequence_trigger',
                'GATETRAIL',
            ),
        );

        $this->assertContains('trigger_definition_mismatch', $codes);
    }

    private function inspect(
        OracleCatalogSnapshot $snapshot,
        string $strategy,
        ?string $schema = null,
    ): SchemaInspection {
        $reader = new class($snapshot) implements OracleCatalogReader
        {
            public function __construct(
                private readonly OracleCatalogSnapshot $snapshot,
            ) {}

            public function read(SchemaContext $context): OracleCatalogSnapshot
            {
                return $this->snapshot;
            }
        };

        return (new OracleSchemaInspector($reader))->inspect(
            $this->manifest(),
            $this->context($strategy, $schema),
        );
    }

    private function withRoleTriggerOwner(
        OracleCatalogSnapshot $snapshot,
        string $owner,
    ): OracleCatalogSnapshot {
        $triggers = array_map(
            static fn (OracleTriggerMetadata $trigger): OracleTriggerMetadata => (
                $trigger->name === 'ACL_ROLES_TRG'
            ) ? new OracleTriggerMetadata(
                name: $trigger->name,
                table: $trigger->table,
                type: $trigger->type,
                event: $trigger->event,
                status: $trigger->status,
                body: str_replace(
                    'ACL_ROLES_SEQ.NEXTVAL',
                    strtoupper($owner).'.ACL_ROLES_SEQ.NEXTVAL',
                    $trigger->body,
                ),
            ) : $trigger,
            $snapshot->triggers,
        );

        return new OracleCatalogSnapshot(
            $snapshot->owner,
            $snapshot->tables,
            $snapshot->columns,
            $snapshot->indexes,
            $snapshot->constraints,
            $snapshot->identities,
            $snapshot->sequences,
            $triggers,
        );
    }

    private function matchingSnapshot(string $strategy): OracleCatalogSnapshot
    {
        $tables = [];
        $columns = [];
        $indexes = [];
        $constraints = [];
        $identities = [];
        $sequences = [];
        $triggers = [];
        $context = $this->context($strategy);
        $options = $context->oracleOptions();

        foreach ($this->manifest()->tables() as $table) {
            $physicalTable = strtoupper($context->tableName($table->key));
            $tables[] = $physicalTable;

            foreach ($table->columns as $column) {
                $identity = $column->generated && $strategy === 'identity';
                $columns[] = $this->columnMetadata(
                    $physicalTable,
                    $column,
                    $identity,
                );

                if ($identity) {
                    $identities[] = new OracleIdentityMetadata(
                        $physicalTable,
                        strtoupper($column->name),
                        'BY DEFAULT',
                    );
                }

                if ($column->generated && $strategy === 'sequence_trigger') {
                    $sequence = strtoupper($options->sequence($table->key));
                    $sequences[] = new OracleSequenceMetadata(
                        $sequence,
                        1,
                        false,
                    );
                    $triggers[] = new OracleTriggerMetadata(
                        name: strtoupper($options->trigger($table->key)),
                        table: $physicalTable,
                        type: 'BEFORE EACH ROW',
                        event: 'INSERT',
                        status: 'ENABLED',
                        body: sprintf(
                            'BEGIN IF :NEW.%s IS NULL THEN :NEW.%s := %s.NEXTVAL; END IF; END;',
                            strtoupper($column->name),
                            strtoupper($column->name),
                            $sequence,
                        ),
                    );
                }
            }

            foreach ($table->indexes as $index) {
                $indexes[] = new OracleIndexMetadata(
                    table: $physicalTable,
                    name: strtoupper($index->name),
                    columns: array_map('strtoupper', $index->columns),
                    unique: false,
                    status: 'VALID',
                );
            }

            foreach ($table->constraints as $constraint) {
                $constraints[] = $this->constraintMetadata(
                    $physicalTable,
                    $constraint,
                    $context,
                );
            }
        }

        return new OracleCatalogSnapshot(
            'GATETRAIL',
            $tables,
            $columns,
            $indexes,
            $constraints,
            $identities,
            $sequences,
            $triggers,
        );
    }

    private function columnMetadata(
        string $table,
        ColumnDefinition $column,
        bool $identity,
    ): OracleColumnMetadata {
        [$dataType, $precision, $scale] = match ($column->type) {
            ColumnType::BigInteger => ['NUMBER', 19, 0],
            ColumnType::DateTime => ['TIMESTAMP(6)', null, null],
            ColumnType::FixedString => ['CHAR', null, null],
            ColumnType::String => ['VARCHAR2', null, null],
            ColumnType::Text => ['CLOB', null, null],
        };
        $character = in_array(
            $column->type,
            [ColumnType::FixedString, ColumnType::String],
            true,
        );

        return new OracleColumnMetadata(
            table: $table,
            name: strtoupper($column->name),
            dataType: $dataType,
            nullable: $column->nullable,
            charLength: $character ? $column->length : null,
            charUsed: $character ? 'C' : null,
            precision: $precision,
            scale: $scale,
            identity: $identity,
            defaultOnNull: $identity,
        );
    }

    private function constraintMetadata(
        string $table,
        ConstraintDefinition $constraint,
        SchemaContext $context,
    ): OracleConstraintMetadata {
        return new OracleConstraintMetadata(
            table: $table,
            name: strtoupper($constraint->name),
            type: match ($constraint->type) {
                ConstraintType::Primary => 'P',
                ConstraintType::Unique => 'U',
                ConstraintType::Foreign => 'R',
                ConstraintType::Check => 'C',
            },
            columns: array_map('strtoupper', $constraint->columns),
            referencedTable: $constraint->referencedTable === null
                ? null
                : strtoupper(
                    $context->tableName($constraint->referencedTable),
                ),
            referencedColumns: array_map(
                'strtoupper',
                $constraint->referencedColumns,
            ),
            deleteRule: $constraint->type === ConstraintType::Foreign
                ? 'NO ACTION'
                : null,
            status: 'ENABLED',
            validated: 'VALIDATED',
            checkClause: $constraint->expression,
        );
    }

    /**
     * @return list<string>
     */
    private function codes(SchemaInspection $inspection): array
    {
        return array_map(
            static fn ($issue): string => $issue->code,
            $inspection->issues,
        );
    }

    private function context(
        string $strategy,
        ?string $schema = null,
    ): SchemaContext {
        $config = require dirname(__DIR__, 2).'/config/gatetrail.php';
        $config['oracle']['generated_keys'] = $strategy;
        $config['database']['schema'] = $schema;

        return new SchemaContext(
            $config,
            new IdentifierValidator,
            $this->manifest(),
        );
    }

    private function manifest(): SchemaManifest
    {
        return $this->app->make(SchemaManifest::class);
    }
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Integration\PostgreSql;

use HangTuah\GateTrail\Database\Schema\Inspection\SchemaInspection;
use HangTuah\GateTrail\Tests\Concerns\AssertsReadOnlySql;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Live regressions for the two fail-open foreign-key gaps found reviewing the
 * first PostgreSQL inspection increment.
 *
 * Both were accepted as canonical before this hardening:
 *
 * 1. a foreign key pointing at an identically named table in **another
 *    schema**, because only the table name was compared;
 * 2. a **DEFERRABLE** `NO ACTION` foreign key, because `RESTRICT` and
 *    `NO ACTION` were treated as unconditionally equivalent.
 *
 * Every case installs the canonical release `0001` schema into a configured
 * non-default schema, changes exactly one foreign key, and asserts both the
 * reported code and that neither schema was modified by the inspection.
 */
final class ForeignKeyDriftTest extends PostgreSqlIntegrationTestCase
{
    use AssertsReadOnlySql;

    private const CONSTRAINT = 'acl_role_assignments.acl_ra_role_fk';

    /**
     * A foreign key may not silently leave the schema under inspection, even
     * when the table it finds elsewhere carries the canonical name and columns.
     */
    public function test_a_foreign_key_referencing_another_schema_is_reported(): void
    {
        $this->executeArtifact('install');
        $this->plantDecoyRolesTable();
        $this->repointRoleForeignKeyAt('decoy');

        $securityBefore = $this->catalogFingerprint('security');
        $decoyBefore = $this->catalogFingerprint('decoy');

        $queries = $this->record(function (): void {
            $inspection = $this->inspectSchema();

            $this->assertContains(
                'foreign_reference_mismatch',
                $this->inspectionCodes($inspection),
            );
            $this->assertFalse($inspection->passes());
            $this->assertStringContainsString(
                'decoy.acl_roles',
                $this->messageFor($inspection, 'foreign_reference_mismatch'),
            );
        });

        $this->assertReadOnlyStatements($queries);
        $this->assertSame(
            $securityBefore,
            $this->catalogFingerprint('security'),
        );
        $this->assertSame($decoyBefore, $this->catalogFingerprint('decoy'));
        $this->assertSame('decoy', $this->referencedSchema());
    }

    /**
     * The same foreign key, repointed back at the canonical table inside the
     * inspected schema, passes — so the assertion above is about the namespace
     * and not about the repointing itself.
     */
    public function test_the_same_foreign_key_inside_the_configured_schema_passes(): void
    {
        $this->executeArtifact('install');
        $this->plantDecoyRolesTable();
        $this->repointRoleForeignKeyAt('security');

        $this->assertSame('security', $this->referencedSchema());
        $this->assertInspectionPasses($this->inspectSchema());
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function deferrableStates(): array
    {
        return [
            'initially immediate' => ['DEFERRABLE INITIALLY IMMEDIATE', false],
            'initially deferred' => ['DEFERRABLE INITIALLY DEFERRED', true],
        ];
    }

    /**
     * The referenced table, columns and non-cascading action are all preserved,
     * so only deferrability can account for the reported drift.
     */
    #[DataProvider('deferrableStates')]
    public function test_a_deferrable_foreign_key_is_reported(
        string $clause,
        bool $initiallyDeferred,
    ): void {
        $this->executeArtifact('install');
        $this->replaceRoleForeignKey('NO ACTION', $clause);

        $before = $this->catalogFingerprint();

        $queries = $this->record(function () use ($initiallyDeferred): void {
            $inspection = $this->inspectSchema();

            $this->assertContains(
                'constraint_state_mismatch',
                $this->inspectionCodes($inspection),
            );
            $this->assertNotContains(
                'foreign_reference_mismatch',
                $this->inspectionCodes($inspection),
            );
            $this->assertNotContains(
                'foreign_rule_mismatch',
                $this->inspectionCodes($inspection),
            );
            $this->assertFalse($inspection->passes());
            $this->assertStringContainsString(
                $initiallyDeferred
                    ? 'INITIALLY DEFERRED'
                    : 'INITIALLY IMMEDIATE',
                $this->messageFor($inspection, 'constraint_state_mismatch'),
            );
        });

        $this->assertReadOnlyStatements($queries);
        $this->assertSame($before, $this->catalogFingerprint());
        $this->assertSame(
            [true, $initiallyDeferred],
            $this->deferrability(),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function restrictiveActions(): array
    {
        return [
            'RESTRICT' => ['RESTRICT'],
            'NO ACTION' => ['NO ACTION'],
        ];
    }

    /**
     * A non-deferrable restrictive foreign key stays canonical under both
     * actions. Laravel's PostgreSQL grammar can emit either, so reporting
     * `NO ACTION` as drift would break package-managed provisioning.
     */
    #[DataProvider('restrictiveActions')]
    public function test_a_not_deferrable_restrictive_foreign_key_passes(
        string $action,
    ): void {
        $this->executeArtifact('install');
        $this->replaceRoleForeignKey($action, 'NOT DEFERRABLE');

        $this->assertSame([false, false], $this->deferrability());
        $this->assertInspectionPasses($this->inspectSchema());
    }

    protected function provisioningMode(): string
    {
        return 'dba';
    }

    protected function configuredSchema(): ?string
    {
        return 'security';
    }

    /**
     * @return list<string>
     */
    protected function auxiliarySchemas(): array
    {
        return ['decoy'];
    }

    /**
     * An identically named, identically shaped table in a schema GateTrail is
     * not inspecting. Only its namespace distinguishes it from the canonical
     * one.
     */
    private function plantDecoyRolesTable(): void
    {
        $this->connection()->statement(
            'CREATE TABLE "decoy"."acl_roles" ('
            .'"id" BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL, '
            .'CONSTRAINT "acl_roles_pk" PRIMARY KEY ("id"))',
        );
    }

    private function repointRoleForeignKeyAt(string $schema): void
    {
        $this->connection()->statement(
            'ALTER TABLE "security"."acl_role_assignments" '
            .'DROP CONSTRAINT "acl_ra_role_fk"',
        );
        $this->connection()->statement(sprintf(
            'ALTER TABLE "security"."acl_role_assignments" '
            .'ADD CONSTRAINT "acl_ra_role_fk" FOREIGN KEY ("role_id") '
            .'REFERENCES "%s"."acl_roles" ("id") '
            .'ON DELETE RESTRICT ON UPDATE RESTRICT',
            $schema,
        ));
    }

    private function replaceRoleForeignKey(
        string $action,
        string $deferrability,
    ): void {
        $this->connection()->statement(
            'ALTER TABLE "security"."acl_role_assignments" '
            .'DROP CONSTRAINT "acl_ra_role_fk"',
        );
        $this->connection()->statement(sprintf(
            'ALTER TABLE "security"."acl_role_assignments" '
            .'ADD CONSTRAINT "acl_ra_role_fk" FOREIGN KEY ("role_id") '
            .'REFERENCES "security"."acl_roles" ("id") '
            .'ON DELETE %s ON UPDATE %s %s',
            $action,
            $action,
            $deferrability,
        ));
    }

    private function referencedSchema(): ?string
    {
        $row = $this->connection()->selectOne(
            <<<'SQL'
                SELECT rn.nspname AS referenced_schema
                FROM pg_catalog.pg_constraint con
                INNER JOIN pg_catalog.pg_class rt ON rt.oid = con.confrelid
                INNER JOIN pg_catalog.pg_namespace rn
                    ON rn.oid = rt.relnamespace
                WHERE con.conname = ?
                SQL,
            ['acl_ra_role_fk'],
        );

        $value = $this->rowValue($row, 'referenced_schema');

        return $value === null ? null : (string) $value;
    }

    /**
     * @return array{bool, bool}
     */
    private function deferrability(): array
    {
        $row = $this->connection()->selectOne(
            <<<'SQL'
                SELECT con.condeferrable, con.condeferred
                FROM pg_catalog.pg_constraint con
                INNER JOIN pg_catalog.pg_class c ON c.oid = con.conrelid
                INNER JOIN pg_catalog.pg_namespace n
                    ON n.oid = c.relnamespace
                WHERE n.nspname = ? AND con.conname = ?
                SQL,
            [$this->activeSchema(), 'acl_ra_role_fk'],
        );
        $values = (array) $row;

        return [
            (bool) ($values['condeferrable'] ?? false),
            (bool) ($values['condeferred'] ?? false),
        ];
    }

    private function messageFor(
        SchemaInspection $inspection,
        string $code,
    ): string {
        foreach ($inspection->issues as $issue) {
            if ($issue->code === $code) {
                return $issue->message;
            }
        }

        return '';
    }
}

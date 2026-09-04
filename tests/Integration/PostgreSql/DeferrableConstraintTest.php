<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Integration\PostgreSql;

use HangTuah\GateTrail\Tests\Concerns\AssertsReadOnlySql;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Live evidence that the `NOT DEFERRABLE` requirement covers every canonical
 * constraint type, not only foreign keys.
 *
 * A deferrable constraint is enforced when PostgreSQL chooses to check it,
 * which for `INITIALLY DEFERRED` is transaction commit. A deferrable unique
 * constraint therefore permits two equivalent rows to coexist for the length of
 * a transaction — precisely the window GateTrail's assignment coordination
 * exists to close — so immediate enforcement is part of the schema contract
 * rather than a stylistic preference.
 *
 * Each case installs the canonical release `0001` schema, recreates exactly one
 * constraint under its own name and ordered columns with only its deferrability
 * changed, and asserts that deferrability alone accounts for the finding.
 *
 * Foreign-key deferrability has its own live coverage in
 * {@see ForeignKeyDriftTest}; this class covers primary-key and unique
 * constraints and the canonical acceptance baseline.
 */
final class DeferrableConstraintTest extends PostgreSqlIntegrationTestCase
{
    use AssertsReadOnlySql;

    /**
     * `acl_access_audits` is the table used for the primary-key cases because
     * no foreign key references it, so its primary key can be recreated without
     * weakening or dropping any other constraint. That is asserted rather than
     * assumed by {@see self::test_the_audit_primary_key_is_referenced_by_nothing()}.
     */
    private const AUDIT_TABLE = 'acl_access_audits';

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

    #[DataProvider('deferrableStates')]
    public function test_a_deferrable_unique_constraint_is_reported(
        string $clause,
        bool $initiallyDeferred,
    ): void {
        $this->executeArtifact('install');
        $this->connection()->statement(
            'ALTER TABLE "acl_roles" DROP CONSTRAINT "acl_role_name_guard_uq"',
        );
        $this->connection()->statement(sprintf(
            'ALTER TABLE "acl_roles" ADD CONSTRAINT "acl_role_name_guard_uq" '
            .'UNIQUE ("name", "guard_name") %s',
            $clause,
        ));

        $this->assertDeferrabilityIsTheOnlyChange(
            'acl_roles.acl_role_name_guard_uq',
            'acl_role_name_guard_uq',
            $initiallyDeferred,
        );
    }

    #[DataProvider('deferrableStates')]
    public function test_a_deferrable_primary_key_is_reported(
        string $clause,
        bool $initiallyDeferred,
    ): void {
        $this->executeArtifact('install');
        $this->connection()->statement(sprintf(
            'ALTER TABLE "%s" DROP CONSTRAINT "acl_audit_pk"',
            self::AUDIT_TABLE,
        ));
        $this->connection()->statement(sprintf(
            'ALTER TABLE "%s" ADD CONSTRAINT "acl_audit_pk" '
            .'PRIMARY KEY ("id") %s',
            self::AUDIT_TABLE,
            $clause,
        ));

        $this->assertDeferrabilityIsTheOnlyChange(
            self::AUDIT_TABLE.'.acl_audit_pk',
            'acl_audit_pk',
            $initiallyDeferred,
        );
    }

    /**
     * The release `0001` artifacts install every primary-key, unique and
     * foreign-key constraint as `NOT DEFERRABLE`, so the canonical schema is
     * accepted and none of the cases above is a false positive.
     */
    public function test_the_canonical_release_0001_constraints_are_not_deferrable(): void
    {
        $this->executeArtifact('install');

        $deferrable = $this->connection()->selectOne(
            <<<'SQL'
                SELECT
                    COUNT(*) AS total,
                    COUNT(*) FILTER (
                        WHERE con.condeferrable OR con.condeferred
                    ) AS deferrable
                FROM pg_catalog.pg_constraint con
                INNER JOIN pg_catalog.pg_class c ON c.oid = con.conrelid
                INNER JOIN pg_catalog.pg_namespace n
                    ON n.oid = c.relnamespace
                WHERE n.nspname = ?
                  AND con.contype IN ('p', 'u', 'f')
                SQL,
            [$this->activeSchema()],
        );

        $this->assertSame(13, (int) $this->rowValue($deferrable, 'total'));
        $this->assertSame(
            0,
            (int) $this->rowValue($deferrable, 'deferrable'),
        );
        $this->assertInspectionPasses($this->inspectSchema());
    }

    /**
     * Pins the assumption the primary-key cases rest on: recreating
     * `acl_audit_pk` cannot require weakening another constraint, because
     * nothing references it.
     */
    public function test_the_audit_primary_key_is_referenced_by_nothing(): void
    {
        $this->executeArtifact('install');

        $referencing = $this->connection()->selectOne(
            <<<'SQL'
                SELECT COUNT(*) AS aggregate
                FROM pg_catalog.pg_constraint con
                INNER JOIN pg_catalog.pg_class rt ON rt.oid = con.confrelid
                INNER JOIN pg_catalog.pg_namespace rn
                    ON rn.oid = rt.relnamespace
                WHERE con.contype = 'f'
                  AND rn.nspname = ?
                  AND rt.relname = ?
                SQL,
            [$this->activeSchema(), self::AUDIT_TABLE],
        );

        $this->assertSame(0, (int) $this->rowValue($referencing, 'aggregate'));
    }

    protected function provisioningMode(): string
    {
        return 'dba';
    }

    /**
     * Asserts that the seeded constraint is reported for its deferrability and
     * for nothing else, that inspection only read, and that it left the drift
     * in place.
     */
    private function assertDeferrabilityIsTheOnlyChange(
        string $object,
        string $constraint,
        bool $initiallyDeferred,
    ): void {
        $before = $this->catalogFingerprint();

        $queries = $this->record(function () use (
            $object,
            $initiallyDeferred,
        ): void {
            $inspection = $this->inspectSchema();
            $codes = $this->inspectionCodes($inspection);

            $this->assertContains('constraint_state_mismatch', $codes);
            $this->assertFalse($inspection->passes());

            // The constraint kept its name, type and ordered columns, and its
            // backing index came back under the same name, so none of these
            // may fire.
            foreach ([
                'missing_constraint',
                'constraint_type_mismatch',
                'constraint_columns_mismatch',
                'missing_index',
                'unexpected_index',
                'column_nullability_mismatch',
                'generated_key_mismatch',
            ] as $unrelated) {
                $this->assertNotContains($unrelated, $codes);
            }

            $issues = array_values(array_filter(
                $inspection->issues,
                static fn ($issue): bool => $issue->object === $object,
            ));

            $this->assertCount(1, $issues);
            $this->assertSame('constraint_state_mismatch', $issues[0]->code);
            $this->assertStringContainsString(
                'NOT DEFERRABLE',
                $issues[0]->message,
            );
            $this->assertStringContainsString(
                $initiallyDeferred
                    ? 'INITIALLY DEFERRED'
                    : 'INITIALLY IMMEDIATE',
                $issues[0]->message,
            );
        });

        $this->assertReadOnlyStatements($queries);
        $this->assertSame($before, $this->catalogFingerprint());
        $this->assertSame(
            [true, $initiallyDeferred],
            $this->deferrability($constraint),
        );
    }

    /**
     * @return array{bool, bool}
     */
    private function deferrability(string $constraint): array
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
            [$this->activeSchema(), $constraint],
        );
        $values = (array) $row;

        return [
            (bool) ($values['condeferrable'] ?? false),
            (bool) ($values['condeferred'] ?? false),
        ];
    }
}

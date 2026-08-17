<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Access;

use DateTimeImmutable;
use HangTuah\GateTrail\Access\EffectiveAccessQueryBuilder;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\Models\RoleAssignment;
use HangTuah\GateTrail\Models\RolePermission;
use HangTuah\GateTrail\Subjects\SubjectIdentity;
use HangTuah\GateTrail\Support\ModelRegistry;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Database\SqlServerConnection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The compiled shape of ADR query 3.
 *
 * Query 3 is the only statement in the package that puts three tables in one
 * `FROM` scope and a fourth in a correlated subquery, so its shape carries
 * more load than the other two combined. Two properties in particular cannot
 * be observed from row results and are therefore asserted here:
 *
 * - **each scope holds exactly one lifecycle-bearing table.**
 *   `scopeActiveAt()` emits unqualified `revoked_at`, `starts_at`,
 *   `assigned_at` and `expires_at`, and both `role_permissions` and
 *   `role_assignments` carry all four. Joining the assignment table into the
 *   outer query would make every lifecycle predicate ambiguous — and silently
 *   wrong on engines that resolve rather than error;
 * - **the assignment is revalidated by a correlated `EXISTS`**, with both
 *   sides of the correlation qualified, rather than by an outer join.
 *
 * `RoleDerivedPermissionResolutionTest` proves the two lifecycle predicates
 * actually bind to their own roots. SQL alone cannot prove that, and this file
 * does not claim it.
 */
final class RoleDerivedPermissionQueryTest extends TestCase
{
    /**
     * @return array<string, array{class-string}>
     */
    public static function grammars(): array
    {
        return [
            'sqlite' => [SQLiteConnection::class],
            'mysql' => [MySqlConnection::class],
            'postgres' => [PostgresConnection::class],
            'sqlserver' => [SqlServerConnection::class],
        ];
    }

    /**
     * The half-open fragment `scopeActiveAt()` emits, as one contiguous string.
     */
    private const LIFECYCLE = '"revoked_at" is null '
        .'and ("starts_at" <= ? or ("starts_at" is null and "assigned_at" <= ?)) '
        .'and ("expires_at" is null or "expires_at" > ?)';

    // ----------------------------------------------------- Builder-only behaviour

    public function test_it_returns_an_unexecuted_builder(): void
    {
        $statements = 0;

        $this->app['db']->listen(function () use (&$statements): void {
            $statements++;
        });

        $query = $this->build();

        $this->assertInstanceOf(Builder::class, $query);
        $this->assertSame(0, $statements);
        $this->assertNull($query->toBase()->lock);
        $this->assertSame(0, $this->app['db']->transactionLevel());
    }

    /**
     * Laravel compiles an empty `whereIn` to `0 = 1`, so an empty candidate
     * list would not fail — it would quietly cost a statement and return
     * nothing, which is indistinguishable from a correct empty answer. The ADR
     * requires query 3 to be skipped in that case, so reaching the builder is
     * a defect.
     */
    public function test_it_rejects_an_empty_candidate_list(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one candidate role');

        $this->build(candidates: []);
    }

    public function test_the_rejection_discloses_no_subject_or_guard(): void
    {
        try {
            $this->build(candidates: []);

            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException $exception) {
            foreach (['App\\Models\\User', 'web', '7', 'select'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $exception->getMessage(),
                );
            }
        }
    }

    // ------------------------------------------------------------- Outer scope

    public function test_the_outer_root_is_the_role_permission_grant(): void
    {
        $this->assertStringContainsString(
            'from "acl_role_permissions"',
            $this->build()->toSql(),
        );
    }

    public function test_the_outer_scope_carries_the_whole_lifecycle_predicate(): void
    {
        $this->assertStringContainsString(
            'where '.self::LIFECYCLE,
            $this->build()->toSql(),
        );
    }

    public function test_it_narrows_by_qualified_candidate_role_identifiers(): void
    {
        $query = $this->build(candidates: [3, 4, 5]);

        $this->assertStringContainsString(
            '"acl_role_permissions"."role_id" in (?, ?, ?)',
            $query->toSql(),
        );

        foreach ([3, 4, 5] as $candidate) {
            $this->assertContains($candidate, $query->getBindings());
        }
    }

    public function test_candidate_identifier_types_are_preserved(): void
    {
        $bindings = $this->build(candidates: [7, '0193c4a1'])->getBindings();

        $this->assertContains(7, $bindings, 'An integer candidate stays an integer.');
        $this->assertContains('0193c4a1', $bindings);
    }

    /**
     * The role definition is revalidated inside query 3 rather than trusted
     * from query 1, because the two are separate statements.
     */
    public function test_it_revalidates_the_joined_role_definition(): void
    {
        $sql = $this->build()->toSql();

        $this->assertStringContainsString(
            'inner join "acl_roles" on "acl_roles"."id" = "acl_role_permissions"."role_id"',
            $sql,
        );
        $this->assertStringContainsString('"acl_roles"."deleted_at" is null', $sql);
        $this->assertStringContainsString('"acl_roles"."guard_name" = ?', $sql);
    }

    public function test_it_filters_the_joined_permission_definition(): void
    {
        $sql = $this->build()->toSql();

        $this->assertStringContainsString(
            'inner join "acl_permissions" on "acl_permissions"."id" = "acl_role_permissions"."permission_id"',
            $sql,
        );
        $this->assertStringContainsString(
            '"acl_permissions"."deleted_at" is null',
            $sql,
        );
        $this->assertStringContainsString(
            '"acl_permissions"."guard_name" = ?',
            $sql,
        );
    }

    // -------------------------------------------------------- Correlated EXISTS

    public function test_it_revalidates_the_assignment_through_a_correlated_exists(): void
    {
        $sql = $this->build()->toSql();

        $this->assertStringContainsString(
            'exists (select * from "acl_role_assignments" where '.self::LIFECYCLE,
            $sql,
        );
        $this->assertStringContainsString(
            '"acl_role_assignments"."subject_type" = ? '
            .'and "acl_role_assignments"."subject_id" = ?',
            $sql,
        );
    }

    /**
     * The one predicate that spans both scopes, so neither side may be left to
     * scope resolution.
     */
    public function test_the_correlation_qualifies_both_sides(): void
    {
        $this->assertStringContainsString(
            '"acl_role_assignments"."role_id" = "acl_role_permissions"."role_id"',
            $this->build()->toSql(),
        );
    }

    /**
     * Two lifecycle groups, one per scope. A single group would mean one of
     * the two roots lost its `activeAt()`.
     */
    public function test_exactly_two_lifecycle_groups_are_emitted(): void
    {
        $this->assertSame(
            2,
            substr_count($this->build()->toSql(), self::LIFECYCLE),
        );
    }

    /**
     * The failure this shape exists to prevent: `role_assignments` in the
     * outer `FROM` alongside `role_permissions` would give two tables carrying
     * all four lifecycle columns, making every unqualified name ambiguous.
     */
    public function test_the_assignment_table_is_never_joined_into_the_outer_query(): void
    {
        $sql = $this->build()->toSql();
        $outer = substr($sql, 0, (int) strpos($sql, 'exists ('));

        $this->assertStringNotContainsString('acl_role_assignments', $outer);
        $this->assertSame(
            1,
            substr_count($sql, 'from "acl_role_permissions"'),
        );
        $this->assertSame(
            1,
            substr_count($sql, 'from "acl_role_assignments"'),
        );
    }

    // ------------------------------------------------------- Selected columns

    public function test_it_selects_the_permission_aliases_and_the_role_guard(): void
    {
        $this->assertStringStartsWith(
            'select "acl_permissions"."id" as "gatetrail_id", '
            .'"acl_permissions"."name" as "gatetrail_name", '
            .'"acl_permissions"."guard_name" as "gatetrail_guard", '
            .'"acl_roles"."guard_name" as "gatetrail_role_guard" from ',
            $this->build()->toSql(),
        );
    }

    public function test_the_role_guard_alias_is_fixed_and_portable(): void
    {
        $alias = EffectiveAccessQueryBuilder::ROLE_GUARD_ALIAS;

        $this->assertSame('gatetrail_role_guard', $alias);
        $this->assertLessThanOrEqual(30, strlen($alias));
        $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $alias);
    }

    // ---------------------------------------------------------- Forbidden shapes

    public function test_it_emits_no_unportable_or_unsafe_construct(): void
    {
        $sql = $this->build()->toSql();

        foreach ([
            'distinct', 'order by', 'union', 'over (', 'lateral',
            'with ', 'for update', 'lock in share mode', 'limit', 'offset',
        ] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $sql);
        }
    }

    /**
     * Every quoted identifier standing alone, enumerated. The four lifecycle
     * columns appear once per scope and are the documented exception; the four
     * table names are bare where a table name belongs; the four aliases are
     * new names rather than references.
     */
    public function test_nothing_but_the_lifecycle_scopes_is_left_unqualified(): void
    {
        preg_match_all('/(?<!\.)"([a-z0-9_]+)"(?!\.)/', $this->build()->toSql(), $matches);

        $bare = array_values(array_unique($matches[1]));
        sort($bare);

        $this->assertSame([
            'acl_permissions',
            'acl_role_assignments',
            'acl_role_permissions',
            'acl_roles',
            'assigned_at',
            'expires_at',
            'gatetrail_guard',
            'gatetrail_id',
            'gatetrail_name',
            'gatetrail_role_guard',
            'revoked_at',
            'starts_at',
        ], $bare);
    }

    // -------------------------------------------------- Prefix, schema, models

    public function test_it_honours_a_configured_prefix_and_schema(): void
    {
        config()->set('gatetrail.database.prefix', 'x_');
        config()->set('gatetrail.database.schema', 'gt');
        $this->forgetResolvedComponents();

        $sql = $this->build()->toSql();

        foreach ([
            'from "gt"."x_acl_role_permissions"',
            'inner join "gt"."x_acl_roles" on "gt"."x_acl_roles"."id" = "gt"."x_acl_role_permissions"."role_id"',
            'inner join "gt"."x_acl_permissions" on "gt"."x_acl_permissions"."id" = "gt"."x_acl_role_permissions"."permission_id"',
            '"gt"."x_acl_roles"."deleted_at" is null',
            '"gt"."x_acl_permissions"."deleted_at" is null',
            'from "gt"."x_acl_role_assignments"',
            '"gt"."x_acl_role_assignments"."role_id" = "gt"."x_acl_role_permissions"."role_id"',
        ] as $expected) {
            $this->assertStringContainsString($expected, $sql);
        }

        foreach ([
            '"acl_roles"',
            '"acl_permissions"',
            '"acl_role_permissions"',
            '"acl_role_assignments"',
        ] as $unprefixed) {
            $this->assertStringNotContainsString($unprefixed, $sql);
        }
    }

    public function test_it_honours_replacement_grant_and_assignment_models(): void
    {
        config()->set('gatetrail.models.role_permission', ReplacementRolePermission::class);
        config()->set('gatetrail.models.role_assignment', ReplacementRoleAssignment::class);
        $this->forgetResolvedComponents();

        $sql = $this->build()->toSql();

        // Same tables, resolved through the configured classes rather than
        // hardcoded ones, and both lifecycle scopes intact.
        $this->assertStringContainsString('from "acl_role_permissions"', $sql);
        $this->assertStringContainsString('from "acl_role_assignments"', $sql);
        $this->assertSame(2, substr_count($sql, self::LIFECYCLE));
    }

    public function test_it_honours_overridden_definition_deleted_at_columns(): void
    {
        config()->set('gatetrail.models.role', DeactivatedAtRoleDefinition::class);
        config()->set('gatetrail.models.permission', DeactivatedAtPermissionDefinition::class);
        $this->forgetResolvedComponents();

        $sql = $this->build()->toSql();

        $this->assertStringContainsString('"acl_roles"."deactivated_at" is null', $sql);
        $this->assertStringContainsString(
            '"acl_permissions"."deactivated_at" is null',
            $sql,
        );
        $this->assertStringNotContainsString('"acl_roles"."deleted_at"', $sql);
        $this->assertStringNotContainsString('"acl_permissions"."deleted_at"', $sql);
    }

    public function test_it_honours_configured_definition_primary_keys(): void
    {
        config()->set('gatetrail.models.role', ReKeyedRoleDefinition::class);
        config()->set('gatetrail.models.permission', ReKeyedPermissionDefinition::class);
        $this->forgetResolvedComponents();

        $sql = $this->build()->toSql();

        $this->assertStringContainsString(
            'on "acl_roles"."role_uuid" = "acl_role_permissions"."role_id"',
            $sql,
        );
        $this->assertStringContainsString(
            'on "acl_permissions"."permission_uuid" = "acl_role_permissions"."permission_id"',
            $sql,
        );
        $this->assertStringContainsString(
            '"acl_permissions"."permission_uuid" as "gatetrail_id"',
            $sql,
        );
        $this->assertStringNotContainsString('"acl_roles"."id"', $sql);
        $this->assertStringNotContainsString('"acl_permissions"."id"', $sql);
    }

    // -------------------------------------------------------------- Portability

    /**
     * Compiled under every query grammar `illuminate/database` ships. Oracle is
     * compiled separately through the isolated runtime — see
     * `tests/Integration/Oracle/OracleQueryCompilationTest.php` — because its
     * grammar is not a root dependency. Compilation is not runtime evidence
     * for any of them.
     */
    #[DataProvider('grammars')]
    public function test_it_compiles_under_each_bundled_grammar(
        string $connectionClass,
    ): void {
        $connection = new $connectionClass(static fn () => null, 'db', '', []);

        $sql = $connection->getQueryGrammar()->compileSelect($this->build()->toBase());

        foreach ([
            'acl_role_permissions',
            'acl_roles',
            'acl_permissions',
            'acl_role_assignments',
            'gatetrail_id',
            'gatetrail_role_guard',
        ] as $expected) {
            $this->assertStringContainsString($expected, $sql);
        }

        $this->assertStringContainsStringIgnoringCase('exists (', $sql);
        $this->assertStringContainsStringIgnoringCase('inner join', $sql);
        $this->assertSame(2, substr_count(strtolower($sql), 'is null and ('));

        foreach ([
            'distinct', 'order by', 'union', 'over (', 'lateral', 'with ',
        ] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $sql);
        }
    }

    // ------------------------------------------------------------------ Helpers

    /**
     * @param  list<int|string>  $candidates
     */
    private function build(array $candidates = [3, 4]): Builder
    {
        return $this->app->make(EffectiveAccessQueryBuilder::class)
            ->roleDerivedPermissionsQuery(
                new SubjectIdentity('App\\Models\\User', 7),
                'web',
                new DateTimeImmutable('2026-08-17 12:00:00'),
                $candidates,
            );
    }

    /**
     * `SchemaContext` and `ModelRegistry` capture configuration when they are
     * constructed, so a configuration change mid-test only applies once they
     * are rebuilt.
     */
    private function forgetResolvedComponents(): void
    {
        foreach ([
            SchemaContext::class,
            ModelRegistry::class,
            EffectiveAccessQueryBuilder::class,
        ] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
    }
}

final class ReplacementRolePermission extends RolePermission {}

final class ReplacementRoleAssignment extends RoleAssignment {}

final class DeactivatedAtRoleDefinition extends Role
{
    public const DELETED_AT = 'deactivated_at';
}

final class DeactivatedAtPermissionDefinition extends Permission
{
    public const DELETED_AT = 'deactivated_at';
}

final class ReKeyedRoleDefinition extends Role
{
    public $incrementing = false;

    protected $primaryKey = 'role_uuid';

    protected $keyType = 'string';
}

final class ReKeyedPermissionDefinition extends Permission
{
    public $incrementing = false;

    protected $primaryKey = 'permission_uuid';

    protected $keyType = 'string';
}

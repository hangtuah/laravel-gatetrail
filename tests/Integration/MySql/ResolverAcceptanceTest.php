<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Integration\MySql;

use HangTuah\GateTrail\Contracts\PermissionResolver;
use HangTuah\GateTrail\Contracts\RoleResolver;
use HangTuah\GateTrail\Database\Schema\Manifest\SchemaManifest;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use HangTuah\GateTrail\Tests\Integration\Concerns\AssertsResolverAcceptance;
use HangTuah\GateTrail\Tests\Integration\Support\ResolverAcceptanceFixture;
use Psr\Clock\ClockInterface;

/**
 * The live MySQL half of ADR 0012's acceptance matrix.
 *
 * It runs the **same** {@see ResolverAcceptanceFixture} and the **same**
 * {@see AssertsResolverAcceptance} assertions as the SQLite parity test, so
 * this is not "MySQL works" — it is "MySQL produces the identical ordered,
 * deduplicated effective collections that SQLite produced from the identical
 * dataset". Section 12.2 calls parity the load-bearing item, and one shared
 * expectation is the only way to make that claim mean anything.
 *
 * Two things are genuinely MySQL's own and are asserted here rather than in the
 * shared trait:
 *
 * **A non-empty table prefix.** `gatetrail.database.prefix = gt_`, so every
 * resolver statement has to reach `gt_acl_*`. The unprefixed names are asserted
 * absent from the database, which turns "the prefix is honoured" from a string
 * comparison into a fact — a resolver that hard-coded `acl_roles` would not
 * return the wrong answer here, it would fail with "table doesn't exist".
 *
 * **Two independent defences against a widening collation**, both measured
 * against the live engine rather than assumed:
 *
 * 1. the database default is `utf8mb4_unicode_ci`, but the migrations pin
 *    `name` and `guard_name` to `utf8mb4_bin`, so `guard_name = 'web'` does
 *    **not** match `WEB` at the SQL layer. Forcing the comparison back to
 *    `utf8mb4_unicode_ci` shows the row would otherwise have matched, so that
 *    defence is doing real work;
 * 2. `utf8mb4_bin` is nonetheless **PAD SPACE** in MySQL 8, so `guard_name =
 *    'web'` really does match a guard stored as `'web '`. That row reaches the
 *    row mapper, and the byte-exact post-verification in PHP is the **only**
 *    thing that keeps it out of the answer.
 *
 * The second case is why the post-verification is not belt-and-braces. It is
 * load-bearing on MySQL today, and this test is what says so.
 *
 * Runs automatically in all four `ci.yml` lanes — Laravel 12 and 13 against
 * MySQL 8.0.16 and 8.0.45 — through `composer test:mysql`.
 *
 * @see docs/decisions/0012-effective-access-resolution.md section 12
 */
final class ResolverAcceptanceTest extends MySqlIntegrationTestCase
{
    use AssertsResolverAcceptance;

    private const PREFIX = 'gt_';

    private ResolverAcceptanceFixture $fixture;

    public function test_the_shared_acceptance_matrix_holds_on_live_mysql(): void
    {
        $this->assertSharedResolverAcceptance();
    }

    /**
     * Prefix evidence, in the only form that cannot be faked: the prefixed
     * tables exist, the unprefixed ones do not, and the resolver still answers.
     */
    public function test_every_resolver_table_carries_the_configured_prefix(): void
    {
        $context = $this->app->make(SchemaContext::class);

        foreach ($this->app->make(SchemaManifest::class)->creationOrder() as $key) {
            $prefixed = $context->tableName($key);

            $this->assertStringStartsWith(self::PREFIX, $prefixed);
            $this->assertTrue(
                $this->schema()->hasTable($prefixed),
                sprintf('The prefixed table [%s] must exist.', $prefixed),
            );
            $this->assertFalse(
                $this->schema()->hasTable(substr($prefixed, strlen(self::PREFIX))),
                sprintf(
                    'The unprefixed table [%s] must not exist, so a hard-coded '
                        .'name would fail rather than silently succeed.',
                    substr($prefixed, strlen(self::PREFIX)),
                ),
            );
        }

        // And the resolver, reading only prefixed names, still agrees with the
        // shared expectation.
        $this->assertEffectiveCollectionsMatchExactly();
    }

    /**
     * Layer one: the package's own column collation stops case widening.
     */
    public function test_the_definition_columns_are_pinned_to_a_binary_collation(): void
    {
        $roles = $this->app->make(SchemaContext::class)->table('roles');

        $this->assertSame(
            'utf8mb4_unicode_ci',
            $this->tableCollation($roles),
            'The ambient database default is the widening one, which is what '
                .'makes the column-level pin meaningful.',
        );

        foreach (['name', 'guard_name'] as $column) {
            $this->assertSame(
                'utf8mb4_bin',
                $this->columnCollation($roles, $column),
                sprintf(
                    'The migrations pin [%s] to a case-sensitive collation.',
                    $column,
                ),
            );
        }

        /** @var list<string> $matched */
        $matched = $this->connection()
            ->table($roles)
            ->where('guard_name', ResolverAcceptanceFixture::GUARD)
            ->pluck('guard_name')
            ->all();

        $this->assertNotContains(
            'WEB',
            $matched,
            'The binary column collation excluded the case variant in SQL.',
        );

        // And it would not have, under the database's default collation.
        /** @var list<string> $widened */
        $widened = $this->connection()
            ->table($roles)
            ->whereRaw(
                'guard_name COLLATE utf8mb4_unicode_ci = ?',
                [ResolverAcceptanceFixture::GUARD],
            )
            ->pluck('guard_name')
            ->all();

        $this->assertContains(
            'WEB',
            $widened,
            'Forced back to utf8mb4_unicode_ci the row matches, so the pinned '
                .'collation is doing real work rather than being decorative.',
        );
    }

    /**
     * Layer two: `utf8mb4_bin` is still PAD SPACE, so a trailing-space guard
     * **does** survive the SQL predicate and is excluded only in PHP.
     *
     * This is the case that makes the byte-exact post-verification
     * load-bearing on MySQL rather than redundant.
     */
    public function test_a_pad_space_guard_reaches_php_and_is_excluded_there(): void
    {
        $roles = $this->app->make(SchemaContext::class)->table('roles');

        /** @var list<string> $matched */
        $matched = $this->connection()
            ->table($roles)
            ->where('guard_name', ResolverAcceptanceFixture::GUARD)
            ->pluck('guard_name')
            ->all();

        $this->assertContains(
            'web ',
            $matched,
            'MySQL 8 pads trailing spaces even under utf8mb4_bin, so this row '
                .'passes the SQL guard predicate.',
        );

        // The resolver read that row and rejected it in PHP: the pad-guard
        // role is absent from the effective roles, and its permission is
        // denied.
        $this->assertEffectiveCollectionsMatchExactly();

        $this->assertFalse(
            $this->roleResolver()->has($this->acceptanceSubject(), 'pad-guard'),
        );
        $this->assertFalse(
            $this->permissionResolver()->allows(
                $this->acceptanceSubject(),
                'documents.pad-guard',
            ),
        );
    }

    /**
     * Neither widening reaches the answer, stated once over the whole
     * exclusion table.
     */
    public function test_no_collation_widening_reaches_the_answer(): void
    {
        $this->assertEffectiveCollectionsMatchExactly();
        $this->assertEveryExclusionHolds();
    }

    /**
     * The join executes at all, which is the failure an unqualified column
     * would produce on a real engine rather than in a compiled string.
     */
    public function test_the_qualified_joins_execute_without_ambiguity(): void
    {
        $this->forgetAcceptanceMemo();

        $permissions = $this->permissionResolver()
            ->effectivePermissions($this->acceptanceSubject());

        $this->assertCount(
            count(ResolverAcceptanceFixture::expectedPermissions()),
            $permissions,
            'Three joined tables resolved without an ambiguous-column error.',
        );
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('gatetrail.database.prefix', self::PREFIX);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Any unprefixed leftovers would make the "unprefixed absent"
        // assertion accidental rather than arranged.
        $this->dropUnprefixedTables();

        $this->artisan('migrate', [
            '--database' => self::CONNECTION,
        ])->assertExitCode(0);

        $this->app->instance(
            ClockInterface::class,
            new FrozenClock(ResolverAcceptanceFixture::evaluatedAt()),
        );

        $this->fixture = new ResolverAcceptanceFixture(
            $this->connection(),
            $this->app->make(SchemaContext::class),
        );

        $this->fixture->installSubjectTable();
        $this->fixture->seed();
    }

    protected function tearDown(): void
    {
        if (isset($this->app, $this->fixture)) {
            $this->fixture->dropSubjectTable();
        }

        parent::tearDown();
    }

    protected function permissionResolver(): PermissionResolver
    {
        return $this->app->make(PermissionResolver::class);
    }

    protected function roleResolver(): RoleResolver
    {
        return $this->app->make(RoleResolver::class);
    }

    private function tableCollation(string $table): string
    {
        $row = $this->connection()->selectOne(
            'SELECT TABLE_COLLATION AS value
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
        );

        return (string) ((array) $row)['value'];
    }

    private function columnCollation(string $table, string $column): string
    {
        $row = $this->connection()->selectOne(
            'SELECT COLLATION_NAME AS value
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?',
            [$table, $column],
        );

        return (string) ((array) $row)['value'];
    }

    private function dropUnprefixedTables(): void
    {
        $manifest = $this->app->make(SchemaManifest::class);
        $context = $this->app->make(SchemaContext::class);

        foreach ($manifest->rollbackOrder() as $key) {
            $prefixed = $context->tableName($key);

            $this->schema()->dropIfExists(
                substr($prefixed, strlen(self::PREFIX)),
            );
        }
    }
}

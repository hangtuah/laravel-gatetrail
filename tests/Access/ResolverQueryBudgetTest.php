<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Access;

use DateTimeImmutable;
use DateTimeZone;
use HangTuah\GateTrail\Access\EffectiveAccessResolver;
use HangTuah\GateTrail\Access\EffectiveAccessSnapshot;
use HangTuah\GateTrail\Contracts\PermissionResolver;
use HangTuah\GateTrail\Contracts\RoleResolver;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Diagnostics\GateTrailStatementProbe;
use HangTuah\GateTrail\Diagnostics\StatementMeasurement;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\PermissionReference;
use HangTuah\GateTrail\RoleReference;
use HangTuah\GateTrail\Tests\Fixtures\CountingClock;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;

/**
 * ADR 0012 section 6.3's query budget, measured rather than asserted.
 *
 * Every count here comes from `GateTrailStatementProbe` — a listener on the
 * GateTrail connection — so each number is what the connection executed, not
 * what the resolver believed it executed. Section 8 rejects the self-reported
 * alternative precisely because a counter the resolver increments would sit
 * unchanged at 3 while an accidental eager-load, relation touch or `count()`
 * added a fourth statement.
 *
 * The budget, in full:
 *
 * | Path | Statements |
 * |---|---|
 * | Cold, no effective role | 2 |
 * | Cold, 1..900 effective roles | 3 |
 * | Cold, 901 effective roles | 4 — `2 + ceil(roles / 900)` |
 * | Warm memo hit | 0 |
 * | Impossible requested input | 0, and the resolver is never entered |
 * | Inside a caller transaction | the cold budget, every time |
 *
 * Each case additionally asserts the **category** breakdown, not just the
 * total: a resolver read is `SELECT`-only, so `select` must equal `total` and
 * every write and schema category must be zero. The classifier fails towards
 * `other`, so an unexpected statement shape breaks these assertions rather
 * than hiding inside the select count.
 *
 * @see docs/decisions/0012-effective-access-resolution.md sections 6.3 and 8
 */
final class ResolverQueryBudgetTest extends TestCase
{
    private CountingClock $clock;

    private TestSubject $subject;

    private Role $role;

    private Permission $permission;

    /**
     * Row counts strictly below the chunk bound. The statement count must not
     * move across any of them.
     *
     * @return array<string, array{int}>
     */
    public static function subChunkRowCounts(): array
    {
        return [
            'one role' => [1],
            'two roles' => [2],
            'fifty roles' => [50],
            'the chunk bound exactly' => [900],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--database' => 'testing'])->assertExitCode(0);

        // Bound before anything resolves the resolver, because the clock is one
        // of its constructor dependencies.
        $this->clock = new CountingClock(
            new DateTimeImmutable('2026-08-19 09:30:00', new DateTimeZone('UTC')),
        );
        $this->app->instance(ClockInterface::class, $this->clock);

        $this->role = Role::query()->create(['name' => 'reviewer', 'guard_name' => 'web']);
        $this->permission = Permission::query()->create([
            'name' => 'documents.approve',
            'guard_name' => 'web',
        ]);
        $this->subject = TestSubject::query()->create(['name' => 'Subject']);
    }

    // ------------------------------------------------------------ 1. Cold, no roles

    public function test_a_cold_resolution_without_roles_costs_two_selects(): void
    {
        $measurement = $this->probe()->measure(fn () => $this->resolve());

        $this->assertBudget($measurement, 2);
    }

    // --------------------------------------------------------- 2. Cold, with roles

    public function test_a_cold_resolution_with_one_role_costs_three_selects(): void
    {
        $this->assignRole($this->role->getKey());

        $measurement = $this->probe()->measure(fn () => $this->resolve());

        $this->assertBudget($measurement, 3);
    }

    // ------------------------------------------------------- 3. Above the chunk bound

    /**
     * 901 candidates is `2 + ceil(901 / 900)` = 4. One role more than the bound
     * is the only place the budget can change, and it changes by exactly one.
     */
    public function test_nine_hundred_and_one_roles_cost_four_selects(): void
    {
        $roleIds = $this->seedRoles(901);

        $this->assertCount(901, $roleIds);

        $measurement = $this->probe()->measure(fn () => $this->resolve());

        $this->assertBudget($measurement, 4);
        $this->assertSame(901, $this->resolve()->roles()->count());
    }

    // -------------------------------------------------------------- 4. Warm memo hit

    public function test_a_warm_memo_hit_costs_nothing_in_every_category(): void
    {
        $this->assignRole($this->role->getKey());

        $cold = $this->probe()->measure(fn () => $this->resolve());
        $warm = $this->probe()->measure(fn () => $this->resolve());

        $this->assertBudget($cold, 3);
        $this->assertSame(0, $warm->total);
        $this->assertEquals(
            StatementMeasurement::none(),
            $warm,
            'Every category must be zero, not merely the total.',
        );
    }

    // ------------------------------------------------------- 5. Impossible input

    /**
     * An impossible input is denied by `RequestedDefinitionNormalizer` before
     * `EffectiveAccessResolver` is reached at all. Zero statements proves no
     * query ran; the untouched clock proves the resolver was never entered,
     * because `resolve()` reads the clock on every path that reaches it.
     */
    public function test_an_impossible_input_costs_nothing_and_never_enters_the_resolver(): void
    {
        $this->assignRole($this->role->getKey());
        $this->grantToRole();

        $reads = $this->clock->reads;

        $measurement = $this->probe()->measure(function (): void {
            $this->assertFalse(
                $this->permissions()->allows(
                    $this->subject,
                    PermissionReference::id('0x1f'),
                ),
            );
            $this->assertFalse(
                $this->permissions()->allows($this->subject, '   '),
            );
            $this->assertFalse(
                $this->roles()->has($this->subject, RoleReference::id('1.0')),
            );
            $this->assertFalse($this->roles()->has($this->subject, ''));
        });

        $this->assertSame(0, $measurement->total);
        $this->assertEquals(StatementMeasurement::none(), $measurement);
        $this->assertSame(
            $reads,
            $this->clock->reads,
            'The clock is read once per resolve, so an untouched clock means '
                .'EffectiveAccessResolver was never entered.',
        );
    }

    // ----------------------------------------------- 6. Repetition within one second

    public function test_the_first_call_pays_the_budget_and_the_rest_pay_nothing(): void
    {
        $this->assignRole($this->role->getKey());
        $this->grantToRole();

        $first = $this->probe()->measure(function (): void {
            $this->assertTrue($this->permissions()->allows($this->subject, 'documents.approve'));
        });

        $rest = $this->probe()->measure(function (): void {
            for ($index = 0; $index < 9; $index++) {
                $this->assertTrue($this->permissions()->allows($this->subject, 'documents.approve'));
            }

            $this->assertTrue($this->roles()->has($this->subject, 'reviewer'));
            $this->permissions()->effectivePermissions($this->subject);
            $this->roles()->effectiveRoles($this->subject);
        });

        $this->assertBudget($first, 3);
        $this->assertSame(
            0,
            $rest->total,
            'Twelve further checks in the same evaluated second cost nothing.',
        );
    }

    // ---------------------------------------- 7. Direct plus role-derived, with parity

    public function test_direct_and_role_derived_permissions_share_the_same_budget(): void
    {
        $this->assignRole($this->role->getKey());
        $this->grantToRole();

        $direct = Permission::query()->create([
            'name' => 'documents.publish',
            'guard_name' => 'web',
        ]);
        $this->grantDirect($direct->getKey());

        $measurement = $this->probe()->measure(fn () => $this->resolve());

        $this->assertBudget($measurement, 3);

        $names = $this->permissions()
            ->effectivePermissions($this->subject)
            ->map(static fn ($permission): string => $permission->name)
            ->all();

        sort($names);

        $this->assertSame(['documents.approve', 'documents.publish'], $names);

        foreach ($names as $name) {
            $this->assertTrue(
                $this->permissions()->allows($this->subject, $name),
                'Parity is unchanged by measuring it.',
            );
        }
    }

    // ------------------------------------------------------- 8. Query-3 revalidation

    /**
     * Query 3 revalidates the whole path — the grant's lifecycle, both
     * definitions and, through a correlated `EXISTS`, the subject's assignment.
     * All of that is one statement: the revalidation buys correctness without
     * buying a fourth query.
     */
    public function test_the_correlated_exists_adds_no_fourth_statement(): void
    {
        $this->assignRole($this->role->getKey());
        $this->grantToRole();

        $measurement = $this->probe()->measure(fn () => $this->resolve());

        $this->assertBudget($measurement, 3);
        $this->assertTrue(
            $this->resolve()->hasPermissionNamed('documents.approve'),
            'The revalidated path still grants.',
        );
    }

    /**
     * The exclusion the revalidation exists to produce costs nothing extra
     * either: dropping a permission is a predicate inside query 3, not a
     * second query. The role is still held and still active, so query 1 finds
     * it and query 3 still runs — and still returns nothing.
     */
    public function test_a_revalidation_exclusion_also_costs_three(): void
    {
        $this->assignRole($this->role->getKey());
        $this->grantToRole();

        DB::connection('testing')->table('acl_permissions')
            ->where('id', $this->permission->getKey())
            ->update(['deleted_at' => '2026-08-19 09:00:00']);

        $measurement = $this->probe()->measure(fn () => $this->resolve());

        $this->assertBudget($measurement, 3);
        $this->assertSame(
            1,
            $this->resolve()->roles()->count(),
            'The role is still effective, so query 3 really did run.',
        );
        $this->assertFalse(
            $this->resolve()->hasPermissionNamed('documents.approve'),
            'And query 3 revalidated the deactivated permission away.',
        );
    }

    // -------------------------------------------------------------- 9. Row count

    /**
     * The N+1 assertion. Below the chunk bound the number of rows must not
     * change the number of statements at all.
     */
    #[DataProvider('subChunkRowCounts')]
    public function test_row_count_below_the_chunk_bound_does_not_change_the_budget(
        int $roles,
    ): void {
        $this->seedRoles($roles);

        $measurement = $this->probe()->measure(fn () => $this->resolve());

        $this->assertBudget($measurement, 3);
        $this->assertSame($roles, $this->resolve()->roles()->count());
    }

    // ------------------------------------------------- 10. Caller-owned transaction

    /**
     * Inside a caller's transaction the memo is bypassed in both directions, so
     * every call pays the cold budget — and every statement it pays is still a
     * `SELECT`, because a transaction does not make the resolver a writer.
     */
    public function test_each_call_inside_a_transaction_pays_the_cold_budget(): void
    {
        $this->assignRole($this->role->getKey());
        $this->grantToRole();

        $probe = $this->probe();
        $connection = DB::connection('testing');

        $connection->beginTransaction();

        try {
            $this->assertSame(1, $connection->transactionLevel());

            $first = $probe->measure(fn () => $this->resolve());
            $second = $probe->measure(fn () => $this->resolve());
            $third = $probe->measure(fn () => $this->resolve());

            $this->assertBudget($first, 3);
            $this->assertBudget($second, 3, 'A transaction has no warm path.');
            $this->assertBudget($third, 3);
        } finally {
            $connection->rollBack();
        }

        $this->assertSame(0, $connection->transactionLevel());
    }

    // ------------------------------------------------------------------ Helpers

    /**
     * The one shape every budget assertion takes: N statements, all of them
     * selects, and nothing in any other category.
     */
    private function assertBudget(
        StatementMeasurement $measurement,
        int $expected,
        string $message = '',
    ): void {
        $this->assertSame($expected, $measurement->total, $message);
        $this->assertSame(
            $expected,
            $measurement->select,
            'Resolver reads are SELECT-only.',
        );
        $this->assertSame(0, $measurement->insert);
        $this->assertSame(0, $measurement->update);
        $this->assertSame(0, $measurement->delete);
        $this->assertSame(0, $measurement->schema);
        $this->assertSame(
            0,
            $measurement->other,
            'An unclassified statement means the shape changed.',
        );
    }

    private function probe(): GateTrailStatementProbe
    {
        return new GateTrailStatementProbe(
            $this->app->make(DatabaseManager::class),
            $this->app->make(SchemaContext::class),
        );
    }

    private function resolve(): EffectiveAccessSnapshot
    {
        return $this->app->make(EffectiveAccessResolver::class)
            ->resolve($this->subject);
    }

    private function permissions(): PermissionResolver
    {
        return $this->app->make(PermissionResolver::class);
    }

    private function roles(): RoleResolver
    {
        return $this->app->make(RoleResolver::class);
    }

    private function assignRole(int|string $roleId): void
    {
        DB::connection('testing')->table('acl_role_assignments')->insert([
            'subject_type' => $this->subject->getMorphClass(),
            'subject_id' => $this->subject->getKey(),
            'role_id' => $roleId,
            'assigned_at' => '2026-08-19 08:00:00',
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'test-suite',
            'created_at' => '2026-08-19 08:00:00',
            'updated_at' => '2026-08-19 08:00:00',
        ]);
    }

    private function grantToRole(): void
    {
        DB::connection('testing')->table('acl_role_permissions')->insert([
            'role_id' => $this->role->getKey(),
            'permission_id' => $this->permission->getKey(),
            'assigned_at' => '2026-08-19 08:00:00',
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'test-suite',
            'created_at' => '2026-08-19 08:00:00',
            'updated_at' => '2026-08-19 08:00:00',
        ]);
    }

    private function grantDirect(int|string $permissionId): void
    {
        DB::connection('testing')->table('acl_permission_assignments')->insert([
            'subject_type' => $this->subject->getMorphClass(),
            'subject_id' => $this->subject->getKey(),
            'permission_id' => $permissionId,
            'assigned_at' => '2026-08-19 08:00:00',
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'test-suite',
            'created_at' => '2026-08-19 08:00:00',
            'updated_at' => '2026-08-19 08:00:00',
        ]);
    }

    /**
     * Bulk-seeds N held roles, batched because the chunk-boundary case needs
     * 901 roles and 901 assignments.
     *
     * @return list<int>
     */
    private function seedRoles(int $count): array
    {
        $connection = DB::connection('testing');
        $roles = [];

        for ($index = 0; $index < $count; $index++) {
            $roles[] = [
                'name' => sprintf('bulk-role-%04d', $index),
                'guard_name' => 'web',
                'created_at' => '2026-08-19 08:00:00',
                'updated_at' => '2026-08-19 08:00:00',
            ];
        }

        foreach (array_chunk($roles, 250) as $batch) {
            $connection->table('acl_roles')->insert($batch);
        }

        /** @var list<int> $ids */
        $ids = $connection->table('acl_roles')
            ->where('name', 'like', 'bulk-role-%')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $assignments = [];

        foreach ($ids as $roleId) {
            $assignments[] = [
                'subject_type' => $this->subject->getMorphClass(),
                'subject_id' => $this->subject->getKey(),
                'role_id' => $roleId,
                'assigned_at' => '2026-08-19 08:00:00',
                'assigned_by_type' => 'gatetrail.system',
                'assigned_by_id' => 'test-suite',
                'created_at' => '2026-08-19 08:00:00',
                'updated_at' => '2026-08-19 08:00:00',
            ];
        }

        foreach (array_chunk($assignments, 250) as $batch) {
            $connection->table('acl_role_assignments')->insert($batch);
        }

        return $ids;
    }
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Access;

use DateTimeImmutable;
use HangTuah\GateTrail\Access\EffectiveAccessQueryBuilder;
use HangTuah\GateTrail\Access\EffectiveAccessResolver;
use HangTuah\GateTrail\Access\EffectiveAccessRowMapper;
use HangTuah\GateTrail\Access\EffectiveAccessSnapshot;
use HangTuah\GateTrail\Access\EffectivePermission;
use HangTuah\GateTrail\Access\EffectiveRole;
use HangTuah\GateTrail\Contracts\PermissionResolver;
use HangTuah\GateTrail\Contracts\RoleResolver;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\Models\RoleAssignment;
use HangTuah\GateTrail\Models\RolePermission;
use HangTuah\GateTrail\Subjects\SubjectIdentity;
use HangTuah\GateTrail\Tests\Fixtures\CountingClock;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;

/**
 * Role-derived permissions, as behaviour.
 *
 * Three things here cannot be established from compiled SQL, and each is the
 * reason this file exists rather than being folded into the shape test.
 *
 * **The two lifecycle predicates bind to their own roots.** Both scopes emit
 * the same four unqualified column names, so counting them in the SQL proves
 * nothing about which table each one reaches. The mirror matrix is the
 * decisive evidence: revoking the *grant* and revoking the *assignment* are
 * different rows, and each must independently deny.
 *
 * **The candidate list is not evidence.** Query 1 and query 3 are separate
 * statements, so a role can be revoked or deactivated between them. The
 * revalidation tests reproduce exactly that interleaving — candidates captured
 * first, state mutated, query 3 executed with the now-stale list — which is
 * the only way to show the `IN` list is a bound rather than a grant.
 *
 * **Chunking is an exact boundary.** 900 and 901 candidate roles must differ
 * by one statement and not by one row of output, so the boundary is crossed
 * with real rows on a real engine.
 */
final class RoleDerivedPermissionResolutionTest extends TestCase
{
    private DateTimeImmutable $time;

    private TestSubject $subject;

    private Role $role;

    private Permission $permission;

    /**
     * One lifecycle matrix, applied to the outer `role_permissions` root and
     * to the inner `role_assignments` root in turn. Same cases, different
     * table — which is what makes the pair decisive about binding.
     *
     * @return array<string, array{array<string, mixed>, bool}>
     */
    public static function lifecycleStates(): array
    {
        return [
            'active' => [[], true],
            'starts exactly at the instant' => [['starts_at' => '2026-08-17 12:00:00'], true],
            'scheduled' => [['starts_at' => '2026-08-17 12:00:01'], false],
            'expires after the instant' => [['expires_at' => '2026-08-17 12:00:01'], true],
            'expires exactly at the instant' => [['expires_at' => '2026-08-17 12:00:00'], false],
            'expired' => [
                [
                    'assigned_at' => '2026-08-17 09:00:00',
                    'expires_at' => '2026-08-17 11:00:00',
                ],
                false,
            ],
            'revoked' => [
                [
                    'revoked_at' => '2026-08-17 11:30:00',
                    'revoked_by_type' => 'gatetrail.system',
                    'revoked_by_id' => 'test-suite',
                    'revocation_reason' => 'Mirror matrix',
                ],
                false,
            ],
        ];
    }

    /**
     * The chunk boundary, in statements. Query 1 and query 2 always run, so
     * the expected total is `2 + ceil(roles / 900)`.
     *
     * @return array<string, array{int, int}>
     */
    public static function chunkBoundaries(): array
    {
        return [
            'no roles skips query 3 entirely' => [0, 2],
            'one role' => [1, 3],
            'one below the bound' => [899, 3],
            'exactly the bound' => [900, 3],
            'one above the bound' => [901, 4],
            'exactly two chunks' => [1800, 4],
            'one above two chunks' => [1801, 5],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--database' => 'testing'])->assertExitCode(0);

        $this->time = new DateTimeImmutable('2026-08-17 12:00:00');
        $this->app->instance(ClockInterface::class, new FrozenClock($this->time));

        $this->role = $this->createRole('reviewer');
        $this->permission = $this->createPermission('documents.approve');
        $this->subject = TestSubject::query()->create(['name' => 'Subject']);
    }

    // ------------------------------------------------------- Baseline behaviour

    public function test_a_permission_granted_through_a_role_is_effective(): void
    {
        $this->assignRole($this->role);
        $this->grantToRole($this->role, $this->permission);

        $snapshot = $this->resolve();

        $this->assertTrue($snapshot->hasPermissionId($this->permission->getKey()));
        $this->assertTrue($snapshot->hasPermissionNamed('documents.approve'));
        $this->assertTrue($snapshot->hasRoleId($this->role->getKey()));
    }

    /**
     * A role name is not an authorization bypass by itself, and it is also not
     * erased by granting nothing.
     */
    public function test_a_role_granting_nothing_stays_in_the_effective_roles(): void
    {
        $this->assignRole($this->role);

        $snapshot = $this->resolve();

        $this->assertTrue($snapshot->hasRoleId($this->role->getKey()));
        $this->assertTrue($snapshot->permissions()->isEmpty());
    }

    public function test_a_grant_to_a_role_the_subject_does_not_hold_is_excluded(): void
    {
        $other = $this->createRole('unheld');
        $this->grantToRole($other, $this->permission);

        $snapshot = $this->resolve();

        $this->assertTrue($snapshot->roles()->isEmpty());
        $this->assertTrue($snapshot->permissions()->isEmpty());
    }

    public function test_a_role_held_by_another_subject_grants_nothing_here(): void
    {
        $other = TestSubject::query()->create(['name' => 'Other']);

        $this->assignRole($this->role, subject: $other);
        $this->grantToRole($this->role, $this->permission);

        $this->assertTrue($this->resolve()->permissions()->isEmpty());
        $this->assertTrue(
            $this->resolve($other)->hasPermissionId($this->permission->getKey()),
            'The other subject genuinely holds it.',
        );
    }

    // --------------------------------------------------- Mirror lifecycle matrix

    /**
     * The outer root. The subject's role assignment stays active throughout,
     * so only the grant's own lifecycle can decide the answer.
     */
    #[DataProvider('lifecycleStates')]
    public function test_the_role_permission_grant_lifecycle_decides(
        array $attributes,
        bool $included,
    ): void {
        $this->assignRole($this->role);
        $this->grantToRole($this->role, $this->permission, $attributes);

        $snapshot = $this->resolve();

        $this->assertSame(
            $included,
            $snapshot->hasPermissionId($this->permission->getKey()),
        );
        $this->assertTrue(
            $snapshot->hasRoleId($this->role->getKey()),
            'The role assignment is untouched by the grant lifecycle.',
        );
    }

    /**
     * The inner `EXISTS` root, with the identical cases. The grant stays active
     * throughout, so only the assignment's own lifecycle can decide — which is
     * what proves the inner predicate reached `role_assignments` and not the
     * outer table.
     */
    #[DataProvider('lifecycleStates')]
    public function test_the_role_assignment_lifecycle_decides_independently(
        array $attributes,
        bool $included,
    ): void {
        $this->assignRole($this->role, $attributes);
        $this->grantToRole($this->role, $this->permission);

        $this->assertSame(
            $included,
            $this->resolve()->hasPermissionId($this->permission->getKey()),
        );
    }

    /**
     * The two mirror cases stated directly. If both predicates bound to one
     * table, one of these would pass when it must not.
     */
    public function test_a_revoked_grant_with_an_active_assignment_is_excluded(): void
    {
        $this->assignRole($this->role);
        $this->grantToRole($this->role, $this->permission, $this->revoked());

        $snapshot = $this->resolve();

        $this->assertTrue($snapshot->hasRoleId($this->role->getKey()));
        $this->assertFalse($snapshot->hasPermissionId($this->permission->getKey()));
    }

    public function test_an_active_grant_with_a_revoked_assignment_is_excluded(): void
    {
        $this->assignRole($this->role, $this->revoked());
        $this->grantToRole($this->role, $this->permission);

        $snapshot = $this->resolve();

        $this->assertFalse($snapshot->hasRoleId($this->role->getKey()));
        $this->assertFalse($snapshot->hasPermissionId($this->permission->getKey()));
        $this->assertTrue(
            RolePermission::query()->activeAt($this->time)->exists(),
            'The grant itself is genuinely active.',
        );
    }

    // -------------------------------------------- Full-path revalidation

    /**
     * The interleaving section 6.1.1 exists for: query 1 observed the role,
     * then the assignment was revoked, then query 3 ran with the stale
     * candidate still in its `IN` list.
     */
    public function test_a_revocation_after_candidate_discovery_grants_nothing(): void
    {
        $this->assignRole($this->role);
        $this->grantToRole($this->role, $this->permission);

        $candidates = $this->candidatesFromQueryOne();

        $this->assertSame([$this->role->getKey()], $candidates);

        RoleAssignment::query()->update([
            'revoked_at' => '2026-08-17 11:30:00',
            'revoked_by_type' => 'gatetrail.system',
            'revoked_by_id' => 'test-suite',
            'revocation_reason' => 'Concurrent revocation',
        ]);

        $this->assertSame([], $this->executeQueryThree($candidates));
    }

    public function test_a_deactivation_after_candidate_discovery_grants_nothing(): void
    {
        $this->assignRole($this->role);
        $this->grantToRole($this->role, $this->permission);

        $candidates = $this->candidatesFromQueryOne();

        $this->assertSame([$this->role->getKey()], $candidates);

        $this->role->delete();

        $this->assertSame([], $this->executeQueryThree($candidates));
    }

    /**
     * The complementary direction. A stale candidate whose whole path is still
     * valid must still grant, otherwise the two tests above would pass for the
     * wrong reason.
     */
    public function test_an_unchanged_valid_path_still_grants(): void
    {
        $this->assignRole($this->role);
        $this->grantToRole($this->role, $this->permission);

        $candidates = $this->candidatesFromQueryOne();

        $this->assertSame(
            [$this->permission->getKey()],
            $this->executeQueryThree($candidates),
        );
    }

    /**
     * A role granted after query 1 is absent from the candidate list, so its
     * permissions are absent from this build. That is an **under**-grant, and
     * the correct direction to fail.
     */
    public function test_a_role_granted_after_query_one_is_absent_from_this_build(): void
    {
        $candidates = $this->candidatesFromQueryOne();

        $this->assertSame([], $candidates);

        $this->assignRole($this->role);
        $this->grantToRole($this->role, $this->permission);

        $this->assertTrue(
            $this->resolve()->hasPermissionId($this->permission->getKey()),
            'A later evaluation observes it.',
        );
    }

    /**
     * The candidate list narrows the scan but cannot widen the answer: a role
     * the subject does not hold contributes nothing even when it is supplied
     * as a candidate and its grant is active.
     */
    public function test_a_candidate_the_subject_never_held_grants_nothing(): void
    {
        $unheld = $this->createRole('unheld');
        $this->grantToRole($unheld, $this->permission);

        $this->assertSame([], $this->executeQueryThree([$unheld->getKey()]));
    }

    // ----------------------------------------- Definition lifecycle and guard

    public function test_a_deactivated_role_grants_nothing(): void
    {
        $this->assignRole($this->role);
        $this->grantToRole($this->role, $this->permission);
        $this->role->delete();

        $snapshot = $this->resolve();

        $this->assertTrue($snapshot->permissions()->isEmpty());
        $this->assertTrue($snapshot->roles()->isEmpty());
    }

    public function test_a_deactivated_permission_is_excluded(): void
    {
        $this->assignRole($this->role);
        $this->grantToRole($this->role, $this->permission);
        $this->permission->delete();

        $snapshot = $this->resolve();

        $this->assertTrue($snapshot->permissions()->isEmpty());
        $this->assertTrue($snapshot->hasRoleId($this->role->getKey()));
    }

    public function test_restoring_the_definitions_makes_the_path_effective_again(): void
    {
        $this->assignRole($this->role);
        $this->grantToRole($this->role, $this->permission);

        $this->role->delete();
        $this->permission->delete();

        $this->assertTrue($this->resolve()->permissions()->isEmpty());

        $this->role->restore();
        $this->permission->restore();

        $this->assertTrue(
            $this->resolve()->hasPermissionId($this->permission->getKey()),
        );
    }

    public function test_a_cross_guard_permission_is_excluded(): void
    {
        $apiPermission = $this->createPermission('api.approve', 'api');

        $this->assignRole($this->role);
        $this->grantToRole($this->role, $apiPermission);

        $this->assertTrue($this->resolve()->permissions()->isEmpty());
    }

    /**
     * A cross-guard role is excluded twice over: it is not an effective role,
     * so it is never a candidate, and query 3 would revalidate its guard
     * anyway.
     */
    public function test_a_cross_guard_role_is_excluded(): void
    {
        $apiRole = $this->createRole('api-reviewer', 'api');

        $this->assignRole($apiRole);
        $this->grantToRole($apiRole, $this->permission);

        $snapshot = $this->resolve();

        $this->assertTrue($snapshot->roles()->isEmpty());
        $this->assertTrue($snapshot->permissions()->isEmpty());
        $this->assertSame([], $this->executeQueryThree([$apiRole->getKey()]));
    }

    // ----------------------------------------------- Merge, dedup and ordering

    public function test_a_permission_reachable_through_two_roles_appears_once(): void
    {
        $second = $this->createRole('auditor');

        $this->assignRole($this->role);
        $this->assignRole($second);
        $this->grantToRole($this->role, $this->permission);
        $this->grantToRole($second, $this->permission);

        $snapshot = $this->resolve();

        $this->assertSame(2, $snapshot->roles()->count());
        $this->assertSame(1, $snapshot->permissions()->count());
        $this->assertTrue($snapshot->hasPermissionId($this->permission->getKey()));
    }

    public function test_a_permission_reachable_directly_and_through_a_role_appears_once(): void
    {
        $this->assignRole($this->role);
        $this->grantToRole($this->role, $this->permission);
        $this->grantDirectly($this->permission);

        $this->assertSame(1, $this->resolve()->permissions()->count());
    }

    /**
     * Revoking one source must not deny while another still grants — the
     * specification's multiple-sources rule, in both directions.
     */
    public function test_losing_one_source_does_not_deny_while_another_grants(): void
    {
        $this->assignRole($this->role);
        $this->grantToRole($this->role, $this->permission);
        $this->grantDirectly($this->permission);

        RolePermission::query()->update([
            'revoked_at' => '2026-08-17 11:30:00',
            'revoked_by_type' => 'gatetrail.system',
            'revoked_by_id' => 'test-suite',
            'revocation_reason' => 'Source removed',
        ]);

        $this->assertTrue(
            $this->resolve()->hasPermissionId($this->permission->getKey()),
            'The direct grant still holds it.',
        );
    }

    /**
     * The same permission arriving from two different chunks is still one
     * entry, which is the deduplication case chunking introduces.
     */
    public function test_duplicates_across_chunks_collapse_to_one(): void
    {
        $roleIds = $this->seedRoles(901);

        // Every one of the 901 roles grants the same permission, so the
        // permission comes back from both chunks.
        $this->seedGrants($roleIds, $this->permission->getKey());

        $snapshot = $this->resolve();

        $this->assertSame(901, $snapshot->roles()->count());
        $this->assertSame(1, $snapshot->permissions()->count());
        $this->assertTrue($snapshot->hasPermissionId($this->permission->getKey()));
    }

    public function test_merged_permissions_are_ordered_by_raw_name_bytes(): void
    {
        $this->assignRole($this->role);

        foreach (['zulu', 'Apple', 'apple'] as $name) {
            $this->grantToRole($this->role, $this->createPermission($name));
        }

        $this->grantDirectly($this->createPermission('_direct'));

        $this->assertSame(
            ['Apple', '_direct', 'apple', 'zulu'],
            $this->resolve()->permissions()
                ->map(static fn (EffectivePermission $p): string => $p->name)
                ->all(),
        );
    }

    // ---------------------------------------------------------- Query budget

    /**
     * The exact chunk boundary, with real rows on a real engine. 900 and 901
     * roles must differ by one **statement**, not by one row of output.
     */
    #[DataProvider('chunkBoundaries')]
    public function test_the_statement_count_follows_the_chunk_boundary(
        int $roleCount,
        int $expectedStatements,
    ): void {
        if ($roleCount > 0) {
            $this->seedRoles($roleCount);
        }

        $statements = $this->captureStatements(fn () => $this->resolve());

        $this->assertCount($expectedStatements, $statements);

        foreach ($statements as $sql) {
            $this->assertStringStartsWith('select ', $sql);
        }

        $this->assertSame(
            $expectedStatements - 2,
            $this->countQueryThreeStatements($statements),
        );
    }

    /**
     * Chunking must never degrade to one statement per role. At 1801 roles a
     * per-role implementation would issue 1801 statements, so the assertion is
     * that the count tracks chunks, not candidates.
     */
    public function test_chunking_never_becomes_one_query_per_role(): void
    {
        $this->seedRoles(1801);

        $statements = $this->captureStatements(fn () => $this->resolve());

        $this->assertCount(5, $statements);
        $this->assertLessThan(1801, count($statements));
    }

    /**
     * Every chunk belongs to one evaluation, so the clock is read once no
     * matter how many statements the chunking produces.
     */
    public function test_one_clock_instant_covers_every_chunk(): void
    {
        $this->seedRoles(901);

        $clock = new CountingClock($this->time);
        $this->app->instance(ClockInterface::class, $clock);
        $this->app->forgetScopedInstances();

        $statements = $this->captureStatements(fn () => $this->resolve());

        $this->assertCount(4, $statements);
        $this->assertSame(1, $clock->reads);
    }

    public function test_it_writes_nothing_locks_nothing_and_owns_no_transaction(): void
    {
        $this->assignRole($this->role);
        $this->grantToRole($this->role, $this->permission);

        $statements = $this->captureStatements(fn () => $this->resolve());

        $this->assertCount(3, $statements);

        foreach ($statements as $sql) {
            foreach ([
                'insert', 'update', 'delete', 'truncate',
                'create', 'alter', 'drop', 'begin', 'commit', 'rollback',
            ] as $keyword) {
                $this->assertDoesNotMatchRegularExpression('/\b'.$keyword.'\b/i', $sql);
            }

            foreach ([
                'for update',
                'lock in share mode',
                'acl_assignment_locks',
            ] as $forbidden) {
                $this->assertStringNotContainsStringIgnoringCase($forbidden, $sql);
            }
        }

        $this->assertSame(0, DB::connection('testing')->transactionLevel());
    }

    public function test_it_reads_inside_a_caller_owned_transaction(): void
    {
        DB::connection('testing')->transaction(function (): void {
            $this->assignRole($this->role);
            $this->grantToRole($this->role, $this->permission);

            $this->assertTrue(
                $this->resolve()->hasPermissionId($this->permission->getKey()),
            );
            $this->assertSame(1, DB::connection('testing')->transactionLevel());
        });

        $this->assertSame(0, DB::connection('testing')->transactionLevel());
    }

    public function test_a_failure_in_query_three_propagates_unchanged(): void
    {
        $this->assignRole($this->role);
        $this->grantToRole($this->role, $this->permission);

        Schema::disableForeignKeyConstraints();
        Schema::drop('acl_role_permissions');
        Schema::enableForeignKeyConstraints();

        try {
            $this->resolve();
            $this->fail('Expected the driver exception to propagate.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString(
                'acl_role_permissions',
                $exception->getMessage(),
            );
            $this->assertNotNull($exception->getPrevious());
        }
    }

    // ------------------------------------------------------- Increment boundary

    public function test_the_public_resolver_contracts_remain_unbound(): void
    {
        $this->assertFalse($this->app->bound(PermissionResolver::class));
        $this->assertFalse($this->app->bound(RoleResolver::class));

        foreach ([
            'HangTuah\\GateTrail\\Access\\DefaultPermissionResolver',
            'HangTuah\\GateTrail\\Access\\DefaultRoleResolver',
        ] as $class) {
            $this->assertFalse(class_exists($class));
        }
    }

    /**
     * Memoization is Increment 8, so a repeated call must still cost its
     * statements — including its chunks.
     */
    public function test_every_call_rebuilds_including_its_chunks(): void
    {
        $this->seedRoles(901);

        $statements = $this->captureStatements(function (): void {
            $this->resolve();
            $this->resolve();
        });

        $this->assertCount(8, $statements);
    }

    // ------------------------------------------------------------------ Helpers

    private function resolve(?TestSubject $subject = null): EffectiveAccessSnapshot
    {
        return $this->app->make(EffectiveAccessResolver::class)
            ->resolve($subject ?? $this->subject);
    }

    /**
     * Query 1, executed and mapped exactly as the resolver does, so the
     * candidate list under test is the real one.
     *
     * @return list<int|string>
     */
    private function candidatesFromQueryOne(): array
    {
        $queries = $this->app->make(EffectiveAccessQueryBuilder::class);
        $mapper = $this->app->make(EffectiveAccessRowMapper::class);

        $roles = $mapper->roles(
            $queries->effectiveRolesQuery($this->identity(), 'web', $this->time)->get(),
            'web',
        );

        return array_map(
            static fn (EffectiveRole $role): int|string => $role->id,
            $roles,
        );
    }

    /**
     * Query 3 for one candidate list, mapped through the real mapper.
     *
     * @param  list<int|string>  $candidates
     * @return list<int|string>
     */
    private function executeQueryThree(array $candidates): array
    {
        $queries = $this->app->make(EffectiveAccessQueryBuilder::class);
        $mapper = $this->app->make(EffectiveAccessRowMapper::class);

        $permissions = $mapper->roleDerivedPermissions(
            $queries->roleDerivedPermissionsQuery(
                $this->identity(),
                'web',
                $this->time,
                $candidates,
            )->get(),
            'web',
        );

        return array_values(array_unique(array_map(
            static fn (EffectivePermission $p): int|string => $p->id,
            $permissions,
        )));
    }

    private function identity(): SubjectIdentity
    {
        return new SubjectIdentity(
            $this->subject->getMorphClass(),
            (int) $this->subject->getKey(),
        );
    }

    /**
     * @return list<string>
     */
    private function captureStatements(callable $work): array
    {
        $statements = [];

        $this->app['db']->listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $work();

        return $statements;
    }

    /**
     * @param  list<string>  $statements
     */
    private function countQueryThreeStatements(array $statements): int
    {
        $count = 0;

        foreach ($statements as $sql) {
            if (str_contains($sql, 'from "acl_role_permissions"')) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    private function revoked(): array
    {
        return [
            'revoked_at' => '2026-08-17 11:30:00',
            'revoked_by_type' => 'gatetrail.system',
            'revoked_by_id' => 'test-suite',
            'revocation_reason' => 'Mirror case',
        ];
    }

    private function createRole(string $name, string $guard = 'web'): Role
    {
        return Role::query()->create(['name' => $name, 'guard_name' => $guard]);
    }

    private function createPermission(string $name, string $guard = 'web'): Permission
    {
        return Permission::query()->create(['name' => $name, 'guard_name' => $guard]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assignRole(
        Role $role,
        array $attributes = [],
        ?TestSubject $subject = null,
    ): RoleAssignment {
        $subject ??= $this->subject;

        return RoleAssignment::query()->create([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'role_id' => $role->getKey(),
            'assigned_at' => '2026-08-17 11:00:00',
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'test-suite',
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function grantToRole(
        Role $role,
        Permission $permission,
        array $attributes = [],
    ): RolePermission {
        return RolePermission::query()->create([
            'role_id' => $role->getKey(),
            'permission_id' => $permission->getKey(),
            'assigned_at' => '2026-08-17 11:00:00',
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'test-suite',
            ...$attributes,
        ]);
    }

    private function grantDirectly(Permission $permission): void
    {
        DB::connection('testing')->table('acl_permission_assignments')->insert([
            'subject_type' => $this->subject->getMorphClass(),
            'subject_id' => $this->subject->getKey(),
            'permission_id' => $permission->getKey(),
            'assigned_at' => '2026-08-17 11:00:00',
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'test-suite',
            'created_at' => '2026-08-17 11:00:00',
            'updated_at' => '2026-08-17 11:00:00',
        ]);
    }

    /**
     * Bulk-seeds N held roles.
     *
     * Written as batched raw inserts rather than N Eloquent saves, because the
     * chunk-boundary tests need 1801 roles and 1801 assignments and a per-row
     * save would dominate the suite's runtime without testing anything.
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
                'created_at' => '2026-08-17 11:00:00',
                'updated_at' => '2026-08-17 11:00:00',
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
                'assigned_at' => '2026-08-17 11:00:00',
                'assigned_by_type' => 'gatetrail.system',
                'assigned_by_id' => 'test-suite',
                'created_at' => '2026-08-17 11:00:00',
                'updated_at' => '2026-08-17 11:00:00',
            ];
        }

        foreach (array_chunk($assignments, 250) as $batch) {
            $connection->table('acl_role_assignments')->insert($batch);
        }

        return $ids;
    }

    /**
     * @param  list<int>  $roleIds
     */
    private function seedGrants(array $roleIds, int $permissionId): void
    {
        $grants = [];

        foreach ($roleIds as $roleId) {
            $grants[] = [
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'assigned_at' => '2026-08-17 11:00:00',
                'assigned_by_type' => 'gatetrail.system',
                'assigned_by_id' => 'test-suite',
                'created_at' => '2026-08-17 11:00:00',
                'updated_at' => '2026-08-17 11:00:00',
            ];
        }

        foreach (array_chunk($grants, 250) as $batch) {
            DB::connection('testing')->table('acl_role_permissions')->insert($batch);
        }
    }
}

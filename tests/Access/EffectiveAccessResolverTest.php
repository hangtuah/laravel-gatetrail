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
use HangTuah\GateTrail\Exceptions\InvalidGuard;
use HangTuah\GateTrail\Exceptions\InvalidSubject;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\PermissionAssignment;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\Models\RoleAssignment;
use HangTuah\GateTrail\Models\RolePermission;
use HangTuah\GateTrail\Subjects\SubjectIdentity;
use HangTuah\GateTrail\Support\ModelRegistry;
use HangTuah\GateTrail\Tests\Fixtures\CountingClock;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * The evaluated authorization state, end to end.
 *
 * Everything here goes through real persisted rows and the real snapshot, so
 * lifecycle, guard and definition filtering are observed as behaviour rather
 * than inferred from compiled SQL — the query shapes already have their own
 * tests. What this file adds is the orchestration: one identity, one guard,
 * one instant, two statements, and nothing written.
 *
 * **Role-derived permissions are deliberately absent.** Increment 5 runs
 * queries 1 and 2 only, and a permission reachable solely through a role must
 * not appear until Increment 6 adds query 3 with its full-path revalidation.
 * That gap is asserted, not assumed.
 */
final class EffectiveAccessResolverTest extends TestCase
{
    private DateTimeImmutable $time;

    private TestSubject $subject;

    private Role $role;

    private Permission $permission;

    /**
     * The half-open matrix, applied identically to role assignments and to
     * direct permission assignments because one scope produces both.
     *
     * @return array<string, array{array<string, mixed>, bool}>
     */
    public static function lifecycleStates(): array
    {
        return [
            'starts exactly at the instant' => [['starts_at' => '2026-08-17 12:00:00'], true],
            'starts before the instant' => [['starts_at' => '2026-08-17 11:59:59'], true],
            'starts after the instant' => [['starts_at' => '2026-08-17 12:00:01'], false],
            'no start, assigned exactly at the instant' => [
                ['starts_at' => null, 'assigned_at' => '2026-08-17 12:00:00'],
                true,
            ],
            'no start, assigned before the instant' => [
                ['starts_at' => null, 'assigned_at' => '2026-08-17 11:00:00'],
                true,
            ],
            'no start, assigned after the instant' => [
                ['starts_at' => null, 'assigned_at' => '2026-08-17 12:00:01'],
                false,
            ],
            'never expires' => [['expires_at' => null], true],
            'expires after the instant' => [['expires_at' => '2026-08-17 12:00:01'], true],
            'expires exactly at the instant' => [['expires_at' => '2026-08-17 12:00:00'], false],
            'expires before the instant' => [['expires_at' => '2026-08-17 11:59:59'], false],
            'not revoked' => [['revoked_at' => null], true],
            'revoked' => [
                [
                    'revoked_at' => '2026-08-17 11:00:00',
                    'revoked_by_type' => 'gatetrail.system',
                    'revoked_by_id' => 'test-suite',
                    'revocation_reason' => 'Lifecycle matrix',
                ],
                false,
            ],
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

    // ------------------------------------------------------------ 1. Structure

    public function test_it_is_final_readonly_and_internal(): void
    {
        $reflection = new ReflectionClass(EffectiveAccessResolver::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());

        $doc = $reflection->getDocComment();

        $this->assertIsString($doc);
        $this->assertStringContainsString('@internal', $doc);
    }

    public function test_its_public_surface_is_one_resolve_method(): void
    {
        $names = [];

        foreach (
            (new ReflectionClass(EffectiveAccessResolver::class))
                ->getMethods(ReflectionMethod::IS_PUBLIC) as $method
        ) {
            $names[] = $method->getName();
        }

        sort($names);

        $this->assertSame(['__construct', 'resolve'], $names);

        $resolve = new ReflectionMethod(EffectiveAccessResolver::class, 'resolve');

        $this->assertSame(
            EffectiveAccessSnapshot::class,
            (string) $resolve->getReturnType(),
        );
        $this->assertSame(
            Model::class,
            (string) $resolve->getParameters()[0]->getType(),
        );
    }

    /**
     * Scoped, not singleton. The request memo of Increment 8 lives in this
     * object, and a singleton captured by a long-lived worker would carry one
     * request's authorization state into the next. The query builder is a
     * genuine singleton and is used here as the contrast that gives the
     * assertion meaning.
     */
    public function test_it_is_registered_as_a_scoped_service(): void
    {
        $resolver = $this->app->make(EffectiveAccessResolver::class);
        $builder = $this->app->make(EffectiveAccessQueryBuilder::class);

        $this->assertSame($resolver, $this->app->make(EffectiveAccessResolver::class));

        $this->app->forgetScopedInstances();

        $this->assertNotSame(
            $resolver,
            $this->app->make(EffectiveAccessResolver::class),
        );
        $this->assertSame(
            $builder,
            $this->app->make(EffectiveAccessQueryBuilder::class),
            'A singleton must survive the scope flush that clears the resolver.',
        );
    }

    public function test_the_public_resolver_contracts_remain_unbound(): void
    {
        $this->assertFalse($this->app->bound(PermissionResolver::class));
        $this->assertFalse($this->app->bound(RoleResolver::class));
        $this->assertFalse($this->app->isAlias(PermissionResolver::class));
        $this->assertFalse($this->app->isAlias(RoleResolver::class));
    }

    public function test_no_later_increment_adapter_exists(): void
    {
        foreach ([
            'HangTuah\\GateTrail\\Access\\DefaultPermissionResolver',
            'HangTuah\\GateTrail\\Access\\DefaultRoleResolver',
        ] as $class) {
            $this->assertFalse(class_exists($class));
            $this->assertFalse($this->app->bound($class));
        }
    }

    // ------------------------------------- 2. One identity, guard and instant

    public function test_it_stores_the_supplied_subject_identity(): void
    {
        $snapshot = $this->resolve();

        $this->assertInstanceOf(SubjectIdentity::class, $snapshot->subject());
        $this->assertSame(
            $this->subject->getMorphClass(),
            $snapshot->subject()->type,
        );
        $this->assertSame($this->subject->getKey(), $snapshot->subject()->id);
    }

    public function test_it_stores_the_resolved_guard_and_instant(): void
    {
        $snapshot = $this->resolve();

        $this->assertSame('web', $snapshot->guardName());
        $this->assertSame($this->time, $snapshot->evaluatedAt());
    }

    /**
     * One call resolves one instant. Two clock reads could straddle a
     * lifecycle boundary and let the two queries disagree about *when* they
     * are asking.
     */
    public function test_it_reads_the_clock_exactly_once_per_resolve(): void
    {
        $clock = new CountingClock($this->time);
        $this->app->instance(ClockInterface::class, $clock);
        $this->app->forgetScopedInstances();

        $this->resolve();

        $this->assertSame(1, $clock->reads);

        $this->resolve();

        $this->assertSame(2, $clock->reads, 'Each resolve reads the clock once.');
    }

    /**
     * The subject that was handed in is the subject that is evaluated. A
     * resolver that reached for the authenticated user would return the other
     * subject's roles here.
     */
    public function test_it_evaluates_the_supplied_subject_not_another_one(): void
    {
        $other = TestSubject::query()->create(['name' => 'Other']);
        $this->assignRole($other, $this->role);

        $snapshot = $this->resolve($this->subject);

        $this->assertSame($this->subject->getKey(), $snapshot->subject()->id);
        $this->assertTrue($snapshot->roles()->isEmpty());

        $this->assertTrue(
            $this->resolve($other)->hasRoleId($this->role->getKey()),
            'The other subject genuinely holds the role.',
        );
    }

    public function test_an_assignment_for_another_subject_type_is_excluded(): void
    {
        RoleAssignment::query()->create([
            'subject_type' => 'App\\Models\\SomethingElse',
            'subject_id' => $this->subject->getKey(),
            'role_id' => $this->role->getKey(),
            'assigned_at' => $this->time,
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'test-suite',
        ]);

        $this->assertTrue($this->resolve()->roles()->isEmpty());
    }

    // ------------------------------------------------------- 3. Lifecycle matrix

    #[DataProvider('lifecycleStates')]
    public function test_role_assignment_lifecycle(
        array $attributes,
        bool $included,
    ): void {
        $this->assignRole($this->subject, $this->role, $attributes);

        $this->assertSame(
            $included,
            $this->resolve()->hasRoleId($this->role->getKey()),
        );
    }

    #[DataProvider('lifecycleStates')]
    public function test_direct_permission_assignment_lifecycle(
        array $attributes,
        bool $included,
    ): void {
        $this->grantPermission($this->subject, $this->permission, $attributes);

        $this->assertSame(
            $included,
            $this->resolve()->hasPermissionId($this->permission->getKey()),
        );
    }

    /**
     * An inactive row must not merely be absent from its own answer — it must
     * not disturb the rows around it either.
     */
    public function test_inactive_rows_do_not_affect_active_ones(): void
    {
        $expired = $this->createRole('expired-role');
        $revoked = $this->createRole('revoked-role');

        $this->assignRole($this->subject, $this->role);
        $this->assignRole($this->subject, $expired, [
            'assigned_at' => '2026-08-17 09:00:00',
            'expires_at' => '2026-08-17 11:00:00',
        ]);
        $this->assignRole($this->subject, $revoked, [
            'revoked_at' => '2026-08-17 11:00:00',
            'revoked_by_type' => 'gatetrail.system',
            'revoked_by_id' => 'test-suite',
            'revocation_reason' => 'Test',
        ]);

        $snapshot = $this->resolve();

        $this->assertSame(1, $snapshot->roles()->count());
        $this->assertTrue($snapshot->hasRoleNamed('reviewer'));
        $this->assertFalse($snapshot->hasRoleNamed('expired-role'));
        $this->assertFalse($snapshot->hasRoleNamed('revoked-role'));
    }

    // --------------------------------------- 4. Definition lifecycle and guard

    public function test_a_deactivated_role_definition_is_excluded(): void
    {
        $this->assignRole($this->subject, $this->role);

        $this->assertTrue($this->resolve()->hasRoleId($this->role->getKey()));

        $this->role->delete();

        $this->assertFalse($this->resolve()->hasRoleId($this->role->getKey()));
        $this->assertTrue($this->resolve()->roles()->isEmpty());
    }

    public function test_a_deactivated_permission_definition_is_excluded(): void
    {
        $this->grantPermission($this->subject, $this->permission);

        $this->assertTrue(
            $this->resolve()->hasPermissionId($this->permission->getKey()),
        );

        $this->permission->delete();

        $this->assertTrue($this->resolve()->permissions()->isEmpty());
    }

    /**
     * The assignment row survives deactivation — specification section 9.1
     * requires that deactivation does not rewrite history — so the exclusion
     * has to come from the definition predicate, not from the assignment.
     */
    public function test_deactivation_excludes_without_touching_the_assignment(): void
    {
        $assignment = $this->assignRole($this->subject, $this->role);
        $this->role->delete();

        $this->assertFalse($this->resolve()->hasRoleId($this->role->getKey()));
        $this->assertTrue(
            RoleAssignment::query()->whereKey($assignment->getKey())->exists(),
        );
        $this->assertNull($assignment->refresh()->revoked_at);
    }

    public function test_a_restored_definition_becomes_effective_again(): void
    {
        $this->assignRole($this->subject, $this->role);
        $this->role->delete();

        $this->assertTrue($this->resolve()->roles()->isEmpty());

        $this->role->restore();

        $this->assertTrue($this->resolve()->hasRoleId($this->role->getKey()));
    }

    public function test_a_cross_guard_role_definition_is_excluded(): void
    {
        $apiRole = $this->createRole('api-reviewer', 'api');
        $this->assignRole($this->subject, $apiRole);

        $snapshot = $this->resolve();

        $this->assertTrue($snapshot->roles()->isEmpty());
        $this->assertFalse($snapshot->hasRoleNamed('api-reviewer'));
    }

    public function test_a_cross_guard_permission_definition_is_excluded(): void
    {
        $apiPermission = $this->createPermission('api.approve', 'api');
        $this->grantPermission($this->subject, $apiPermission);

        $this->assertTrue($this->resolve()->permissions()->isEmpty());
    }

    /**
     * The inner join is the fail-closed mechanism: an assignment whose
     * definition row is gone contributes nothing rather than defaulting.
     */
    public function test_an_assignment_without_a_definition_row_is_excluded(): void
    {
        $orphaned = $this->createRole('orphaned');
        $this->assignRole($this->subject, $orphaned);
        $this->assignRole($this->subject, $this->role);

        Schema::disableForeignKeyConstraints();
        Role::query()->whereKey($orphaned->getKey())->forceDelete();
        Schema::enableForeignKeyConstraints();

        $snapshot = $this->resolve();

        $this->assertSame(1, $snapshot->roles()->count());
        $this->assertTrue($snapshot->hasRoleNamed('reviewer'));
    }

    // ---------------------------------------------- 6-8. Increment 5 behaviour

    public function test_a_role_granting_no_permissions_is_still_effective(): void
    {
        $this->assignRole($this->subject, $this->role);

        $snapshot = $this->resolve();

        $this->assertTrue($snapshot->hasRoleId($this->role->getKey()));
        $this->assertTrue($snapshot->hasRoleNamed('reviewer'));
        $this->assertTrue($snapshot->permissions()->isEmpty());
    }

    public function test_a_direct_permission_grant_is_effective(): void
    {
        $this->grantPermission($this->subject, $this->permission);

        $snapshot = $this->resolve();

        $this->assertTrue($snapshot->hasPermissionId($this->permission->getKey()));
        $this->assertTrue($snapshot->hasPermissionNamed('documents.approve'));
        $this->assertTrue($snapshot->roles()->isEmpty());
    }

    /**
     * The specification's formula, end to end: direct grants plus permissions
     * reachable through active roles. `RoleDerivedPermissionResolutionTest`
     * owns the detailed matrix; this is the integration smoke test.
     */
    public function test_a_role_derived_permission_is_effective(): void
    {
        $this->assignRole($this->subject, $this->role);
        $this->grantPermissionToRole($this->role, $this->permission);

        $snapshot = $this->resolve();

        $this->assertTrue($snapshot->hasRoleId($this->role->getKey()));
        $this->assertTrue($snapshot->hasPermissionId($this->permission->getKey()));
        $this->assertTrue($snapshot->hasPermissionNamed('documents.approve'));

        $this->assertTrue(
            RolePermission::query()->activeAt($this->time)->exists(),
            'The role-permission grant is genuinely active.',
        );
    }

    /**
     * Specification section 5.5: the same permission through several sources is
     * one effective permission, and losing one source does not deny it.
     */
    public function test_a_permission_granted_directly_and_through_a_role_appears_once(): void
    {
        $this->assignRole($this->subject, $this->role);
        $this->grantPermissionToRole($this->role, $this->permission);
        $this->grantPermission($this->subject, $this->permission);

        $snapshot = $this->resolve();

        $this->assertSame(1, $snapshot->permissions()->count());
        $this->assertTrue($snapshot->hasPermissionId($this->permission->getKey()));
    }

    // ------------------------------------------- 9. Mapping and 12. snapshot rules

    public function test_mapped_values_carry_exact_identifiers_names_and_guards(): void
    {
        $this->assignRole($this->subject, $this->role);
        $this->grantPermission($this->subject, $this->permission);

        $snapshot = $this->resolve();
        $role = $snapshot->roles()->first();
        $permission = $snapshot->permissions()->first();

        $this->assertInstanceOf(EffectiveRole::class, $role);
        $this->assertInstanceOf(EffectivePermission::class, $permission);

        $this->assertSame($this->role->getKey(), $role->id);
        $this->assertIsInt($role->id);
        $this->assertSame('reviewer', $role->name);
        $this->assertSame('web', $role->guardName);

        $this->assertSame($this->permission->getKey(), $permission->id);
        $this->assertIsInt($permission->id);
        $this->assertSame('documents.approve', $permission->name);
        $this->assertSame('web', $permission->guardName);
    }

    public function test_the_snapshot_orders_by_raw_name_bytes(): void
    {
        foreach (['zulu', 'Apple', 'apple'] as $name) {
            $this->assignRole($this->subject, $this->createRole($name));
            $this->grantPermission($this->subject, $this->createPermission($name));
        }

        $snapshot = $this->resolve();

        $this->assertSame(
            ['Apple', 'apple', 'zulu'],
            $snapshot->roles()
                ->map(static fn (EffectiveRole $role): string => $role->name)
                ->all(),
        );
        $this->assertSame(
            ['Apple', 'apple', 'zulu'],
            $snapshot->permissions()
                ->map(static fn (EffectivePermission $p): string => $p->name)
                ->all(),
        );
    }

    /**
     * Two active assignments of the same role are two rows and one effective
     * role. Deduplication is the snapshot's job, and this proves the resolver
     * feeds it rather than second-guessing it.
     */
    public function test_repeated_assignments_collapse_to_one_entry(): void
    {
        $this->assignRole($this->subject, $this->role);
        $this->assignRole($this->subject, $this->role, [
            'assigned_at' => '2026-08-17 10:00:00',
        ]);
        $this->grantPermission($this->subject, $this->permission);
        $this->grantPermission($this->subject, $this->permission, [
            'assigned_at' => '2026-08-17 10:00:00',
        ]);

        $snapshot = $this->resolve();

        $this->assertSame(2, RoleAssignment::query()->count());
        $this->assertSame(1, $snapshot->roles()->count());
        $this->assertSame(1, $snapshot->permissions()->count());
    }

    // ------------------------------------------------------- 13-14. Query budget

    public function test_a_resolve_with_no_roles_costs_exactly_two_selects(): void
    {
        $statements = $this->captureStatements(fn () => $this->resolve());

        $this->assertCount(2, $statements);

        foreach ($statements as $sql) {
            $this->assertStringStartsWith('select ', $sql);
        }

        $this->assertStringContainsString('"acl_role_assignments"', $statements[0]);
        $this->assertStringContainsString(
            '"acl_permission_assignments"',
            $statements[1],
        );
    }

    /**
     * With roles present the cost is three: queries 1, 2 and one query-3 chunk.
     */
    public function test_the_with_roles_path_costs_three_selects(): void
    {
        $this->assignRole($this->subject, $this->role);
        $this->grantPermission($this->subject, $this->permission);

        $this->assertCount(3, $this->captureStatements(fn () => $this->resolve()));
    }

    /**
     * The N+1 assertion that matters: the number of definitions must not
     * change the number of statements.
     */
    public function test_the_statement_count_does_not_grow_with_the_row_count(): void
    {
        for ($index = 0; $index < 25; $index++) {
            $this->assignRole($this->subject, $this->createRole('role-'.$index));
            $this->grantPermission(
                $this->subject,
                $this->createPermission('permission-'.$index),
            );
        }

        $statements = $this->captureStatements(fn () => $this->resolve());

        $this->assertCount(3, $statements);
        $this->assertSame(25, $this->resolve()->roles()->count());
        $this->assertSame(25, $this->resolve()->permissions()->count());
    }

    public function test_it_writes_nothing_takes_no_lock_and_opens_no_transaction(): void
    {
        $this->assignRole($this->subject, $this->role);
        $this->grantPermission($this->subject, $this->permission);

        $statements = $this->captureStatements(fn () => $this->resolve());

        foreach ($statements as $sql) {
            // Word-bounded, because the selected soft-delete column is
            // legitimately named `deleted_at` and a bare substring match on
            // "delete" would flag the very predicate that keeps the read
            // correct.
            foreach ([
                'insert', 'update', 'delete', 'truncate',
                'create', 'alter', 'drop', 'begin', 'commit', 'rollback',
            ] as $keyword) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\b'.$keyword.'\b/i',
                    $sql,
                );
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

    /**
     * The resolver must be usable inside a caller's transaction and must
     * observe that transaction's uncommitted state, without taking ownership
     * of it.
     */
    public function test_it_reads_inside_a_caller_owned_transaction(): void
    {
        DB::connection('testing')->transaction(function (): void {
            $this->assignRole($this->subject, $this->role);

            $this->assertSame(1, DB::connection('testing')->transactionLevel());
            $this->assertTrue($this->resolve()->hasRoleId($this->role->getKey()));
            $this->assertSame(
                1,
                DB::connection('testing')->transactionLevel(),
                'The resolver must not open or commit a transaction of its own.',
            );
        });

        $this->assertSame(0, DB::connection('testing')->transactionLevel());
    }

    /**
     * Memoization is Increment 8. Until then every call must genuinely read.
     */
    public function test_every_call_executes_its_queries(): void
    {
        $statements = $this->captureStatements(function (): void {
            $this->resolve();
            $this->resolve();
        });

        $this->assertCount(4, $statements);
    }

    // ------------------------------------------------ 10. Safe failure behaviour

    public function test_an_unresolvable_subject_raises_before_any_statement(): void
    {
        $statements = [];

        $this->app['db']->listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        try {
            $this->app->make(EffectiveAccessResolver::class)->resolve(new TestSubject);
            $this->fail('Expected InvalidSubject.');
        } catch (InvalidSubject $exception) {
            $this->assertSame([], $statements);
            $this->assertStringNotContainsString(
                (string) $this->subject->getKey(),
                $exception->getMessage(),
            );
        }
    }

    public function test_an_unresolvable_guard_raises_before_any_statement(): void
    {
        $subject = GuardlessSubject::query()->create(['name' => 'Guardless']);

        config()->set('gatetrail.guards.default', '');
        config()->set('auth.defaults.guard', '');

        $statements = [];

        $this->app['db']->listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        try {
            $this->app->make(EffectiveAccessResolver::class)->resolve($subject);
            $this->fail('Expected InvalidGuard.');
        } catch (InvalidGuard $exception) {
            $this->assertSame([], $statements);
            $this->assertStringNotContainsString(
                (string) $subject->getKey(),
                $exception->getMessage(),
            );
        }
    }

    /**
     * A database failure is infrastructure, not policy. Converting it into an
     * empty snapshot would turn an outage into a silent mass denial with no
     * signal anywhere.
     */
    public function test_a_database_failure_propagates_unchanged(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::drop('acl_role_assignments');
        Schema::enableForeignKeyConstraints();

        $this->expectException(QueryException::class);

        $this->app->make(EffectiveAccessResolver::class)->resolve($this->subject);
    }

    /**
     * The same failure, caught, to show it is the driver's exception rather
     * than a package translation of it — and that nothing was swallowed into
     * an empty snapshot.
     */
    public function test_a_database_failure_is_not_converted_into_an_empty_snapshot(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::drop('acl_permission_assignments');
        Schema::enableForeignKeyConstraints();

        try {
            $this->app->make(EffectiveAccessResolver::class)->resolve($this->subject);
            $this->fail('Expected the driver exception to propagate.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString(
                'acl_permission_assignments',
                $exception->getMessage(),
            );
            $this->assertNotNull($exception->getPrevious());
        }
    }

    // ------------------------------------------------ 15. Configured components

    /**
     * The resolver reaches its definition models through `ModelRegistry`, so a
     * consumer's replacement models are what get queried and what decide the
     * identifier's key type. The mapper's own tests prove the key-type rule;
     * this one proves the resolver does not bypass the registry.
     */
    public function test_it_honours_configured_definition_models(): void
    {
        $this->assignRole($this->subject, $this->role);
        $this->grantPermission($this->subject, $this->permission);

        config()->set('gatetrail.models.role', ConfiguredRole::class);
        config()->set('gatetrail.models.permission', ConfiguredPermission::class);
        $this->app->forgetInstance(ModelRegistry::class);
        $this->app->forgetInstance(EffectiveAccessQueryBuilder::class);
        $this->app->forgetInstance(EffectiveAccessRowMapper::class);
        $this->app->forgetScopedInstances();

        $registry = $this->app->make(ModelRegistry::class);

        $this->assertSame(ConfiguredRole::class, $registry->role());
        $this->assertSame(ConfiguredPermission::class, $registry->permission());

        $snapshot = $this->resolve();

        $this->assertTrue($snapshot->hasRoleId($this->role->getKey()));
        $this->assertTrue($snapshot->hasPermissionId($this->permission->getKey()));
        $this->assertIsInt($snapshot->roles()->first()?->id);
        $this->assertIsInt($snapshot->permissions()->first()?->id);
    }

    public function test_it_reads_through_the_configured_gatetrail_connection(): void
    {
        $this->assignRole($this->subject, $this->role);

        $connections = [];

        $this->app['db']->listen(function ($query) use (&$connections): void {
            $connections[] = $query->connectionName;
        });

        $this->resolve();

        $this->assertSame(['testing', 'testing', 'testing'], $connections);
    }

    // ------------------------------------------------------------------ Helpers

    private function resolve(?Model $subject = null): EffectiveAccessSnapshot
    {
        return $this->app->make(EffectiveAccessResolver::class)
            ->resolve($subject ?? $this->subject);
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

    private function createRole(string $name, string $guard = 'web'): Role
    {
        return Role::query()->create([
            'name' => $name,
            'guard_name' => $guard,
        ]);
    }

    private function createPermission(
        string $name,
        string $guard = 'web',
    ): Permission {
        return Permission::query()->create([
            'name' => $name,
            'guard_name' => $guard,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assignRole(
        Model $subject,
        Role $role,
        array $attributes = [],
    ): RoleAssignment {
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
    private function grantPermission(
        Model $subject,
        Permission $permission,
        array $attributes = [],
    ): PermissionAssignment {
        return PermissionAssignment::query()->create([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'permission_id' => $permission->getKey(),
            'assigned_at' => '2026-08-17 11:00:00',
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'test-suite',
            ...$attributes,
        ]);
    }

    private function grantPermissionToRole(
        Role $role,
        Permission $permission,
    ): RolePermission {
        return RolePermission::query()->create([
            'role_id' => $role->getKey(),
            'permission_id' => $permission->getKey(),
            'assigned_at' => '2026-08-17 11:00:00',
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'test-suite',
        ]);
    }
}

final class ConfiguredRole extends Role {}

final class ConfiguredPermission extends Permission {}

/**
 * Has no guard of its own, so guard resolution falls through to configuration
 * and can be made to fail.
 */
final class GuardlessSubject extends Model
{
    public $timestamps = false;

    protected $table = 'test_subjects';

    protected $guarded = [];
}

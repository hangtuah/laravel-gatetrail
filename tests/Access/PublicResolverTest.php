<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Access;

use DateTimeImmutable;
use HangTuah\GateTrail\Access\EffectiveAccessQueryBuilder;
use HangTuah\GateTrail\Access\EffectiveAccessRowMapper;
use HangTuah\GateTrail\Access\EffectivePermission;
use HangTuah\GateTrail\Access\EffectiveRole;
use HangTuah\GateTrail\Contracts\PermissionContract;
use HangTuah\GateTrail\Contracts\PermissionResolver;
use HangTuah\GateTrail\Contracts\RoleContract;
use HangTuah\GateTrail\Contracts\RoleResolver;
use HangTuah\GateTrail\Exceptions\InvalidGuard;
use HangTuah\GateTrail\Exceptions\InvalidModelConfiguration;
use HangTuah\GateTrail\Exceptions\InvalidSubject;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\PermissionReference;
use HangTuah\GateTrail\RoleReference;
use HangTuah\GateTrail\Support\ModelRegistry;
use HangTuah\GateTrail\Tests\Fixtures\CountingClock;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;

/**
 * The public resolvers, through the container, against real rows.
 *
 * Two properties carry this file.
 *
 * **A bare string is always a name.** `allows($subject, '007')` asks whether a
 * permission *named* `007` is effective; `allows($subject, Reference::id('007'))`
 * asks about *identifier* 7. The fixtures below make those two questions have
 * different answers on purpose, because a matrix where both happen to be true
 * would prove nothing.
 *
 * **An impossible input denies before anything is resolved.** Not just before
 * the queries — before the subject identity, the guard and the clock. The
 * evidence is a counting clock reading zero alongside zero statements, and an
 * *unpersisted* subject returning `false` rather than raising `InvalidSubject`.
 *
 * Both resolvers are exercised from one matrix wherever the rule is shared,
 * because ADR 0012 requires the two to behave identically and two separate
 * matrices are two places for that to stop being true.
 */
final class PublicResolverTest extends TestCase
{
    private DateTimeImmutable $time;

    private TestSubject $subject;

    /**
     * @return array<string, array{string}>
     */
    public static function kinds(): array
    {
        return [
            'permission' => ['permission'],
            'role' => ['role'],
        ];
    }

    /**
     * Names that are effective, and names that are not. The fixture gives both
     * kinds the same names so one matrix covers both resolvers.
     *
     * @return array<string, array{string, bool}>
     */
    public static function nameMatrix(): array
    {
        return [
            'exact effective name' => ['seven', true],
            'exact numeric-looking name' => ['007', true],
            'exact dotted name' => ['records.read', true],
            'wrong case' => ['Seven', false],
            'upper case' => ['SEVEN', false],
            'leading whitespace' => [' seven', false],
            'trailing whitespace' => ['seven ', false],
            'surrounding whitespace' => [' seven ', false],
            'canonicalized numeric name' => ['7', false],
            'absent name' => ['nobody-has-this', false],
            'empty name' => ['', false],
            'whitespace-only name' => ['   ', false],
            'deactivated definition name' => ['deactivated', false],
            'cross-guard definition name' => ['api-only', false],
            'unheld definition name' => ['unheld', false],
        ];
    }

    /**
     * Identifier inputs. `7` is the definition named `seven`; `12` is the one
     * named `007`; `30` is deactivated, `40` cross-guard and `50` unheld.
     *
     * @return array<string, array{int|string, bool}>
     */
    public static function identifierMatrix(): array
    {
        return [
            'native integer' => [7, true],
            'decimal string' => ['7', true],
            'zero padded string' => ['007', true],
            'second effective identifier' => [12, true],
            'php int max' => [PHP_INT_MAX, false],
            'above php int max' => ['9223372036854775808', false],
            'far above php int max' => ['99999999999999999999', false],
            'negative string' => ['-1', false],
            'signed string' => ['+1', false],
            'leading space' => [' 7', false],
            'trailing space' => ['7 ', false],
            'decimal notation' => ['7.0', false],
            'exponent notation' => ['7e0', false],
            'hexadecimal notation' => ['0x7', false],
            'alphabetic' => ['seven', false],
            'uuid' => ['0193c4a1-6f2e-7a3b-9c4d-5e6f70819a2b', false],
            'deactivated identifier' => [30, false],
            'cross-guard identifier' => [40, false],
            'unheld identifier' => [50, false],
            'absent identifier' => [9999, false],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--database' => 'testing'])->assertExitCode(0);

        $this->time = new DateTimeImmutable('2026-08-17 12:00:00');
        $this->app->instance(ClockInterface::class, new FrozenClock($this->time));

        $this->subject = TestSubject::query()->create(['name' => 'Subject']);

        // Identifier 7 is named `seven`, and identifier 12 is named `007`, so
        // a name lookup and an identifier lookup for "007" land on different
        // rows and cannot pass for each other's reasons.
        $this->seedDefinition(7, 'seven');
        $this->seedDefinition(12, '007');
        $this->seedDefinition(20, 'records.read');
        $this->seedDefinition(30, 'deactivated', deactivated: true);
        $this->seedDefinition(40, 'api-only', guard: 'api');
        $this->seedDefinition(50, 'unheld', held: false);

        // Reachable only through the held role 20, so the public permission
        // resolver has to go through query 3 to see it.
        $this->seedRoleDerivedPermission(60, 'role-derived', 20);

        // Reachable both directly and through role 20, so deduplication is
        // observable through the public surface.
        $this->seedRoleDerivedPermission(70, 'shared', 20, direct: true);
    }

    // ------------------------------------------------------------ 1. Name matrix

    #[DataProvider('nameMatrix')]
    public function test_permission_name_lookup(string $name, bool $expected): void
    {
        $this->assertSame($expected, $this->permissions()->allows($this->subject, $name));
    }

    #[DataProvider('nameMatrix')]
    public function test_role_name_lookup(string $name, bool $expected): void
    {
        $this->assertSame($expected, $this->roles()->has($this->subject, $name));
    }

    // ------------------------------------------------------ 2. Identifier matrix

    #[DataProvider('identifierMatrix')]
    public function test_permission_reference_lookup(
        int|string $identifier,
        bool $expected,
    ): void {
        $this->assertSame(
            $expected,
            $this->permissions()->allows(
                $this->subject,
                PermissionReference::id($identifier),
            ),
        );
    }

    #[DataProvider('identifierMatrix')]
    public function test_role_reference_lookup(
        int|string $identifier,
        bool $expected,
    ): void {
        $this->assertSame(
            $expected,
            $this->roles()->has($this->subject, RoleReference::id($identifier)),
        );
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function nativeIntegerMatrix(): array
    {
        return [
            'effective' => [7, true],
            'second effective' => [12, true],
            'php int max' => [PHP_INT_MAX, false],
            'deactivated' => [30, false],
            'cross-guard' => [40, false],
            'unheld' => [50, false],
            'absent' => [9999, false],
        ];
    }

    #[DataProvider('nativeIntegerMatrix')]
    public function test_a_native_integer_matches_its_reference(
        int $identifier,
        bool $expected,
    ): void {
        $this->assertSame(
            $expected,
            $this->permissions()->allows($this->subject, $identifier),
        );
        $this->assertSame(
            $expected,
            $this->roles()->has($this->subject, $identifier),
        );
    }

    // -------------------------------------------- 3. The name/identifier divide

    /**
     * The distinction the whole input matrix exists to protect, stated on its
     * own with fixtures that give the two calls different answers.
     */
    public function test_a_bare_string_asks_about_a_name_and_a_reference_about_an_identifier(): void
    {
        $permissions = $this->permissions();

        // Definition 12 is literally named `007`.
        $this->assertTrue($permissions->allows($this->subject, '007'));

        // Reference `007` canonicalizes to identifier 7, which is `seven`.
        $this->assertTrue(
            $permissions->allows($this->subject, PermissionReference::id('007')),
        );

        // The two landed on different rows, which is what makes them different
        // questions rather than one question asked twice.
        $this->assertFalse(
            $permissions->allows($this->subject, '7'),
            'No definition is named "7", so the name lookup denies.',
        );
        $this->assertTrue(
            $permissions->allows($this->subject, PermissionReference::id(7)),
            'The identifier lookup for the same digits succeeds.',
        );
    }

    public function test_the_same_divide_holds_for_roles(): void
    {
        $roles = $this->roles();

        $this->assertTrue($roles->has($this->subject, '007'));
        $this->assertTrue($roles->has($this->subject, RoleReference::id('007')));
        $this->assertFalse($roles->has($this->subject, '7'));
        $this->assertTrue($roles->has($this->subject, RoleReference::id(7)));
    }

    // ------------------------------------------------------ 4. Definition models

    public function test_a_persisted_configured_model_is_honoured(): void
    {
        $permission = Permission::query()->find(7);
        $role = Role::query()->find(7);

        $this->assertInstanceOf(Permission::class, $permission);
        $this->assertInstanceOf(Role::class, $role);

        $this->assertTrue($this->permissions()->allows($this->subject, $permission));
        $this->assertTrue($this->roles()->has($this->subject, $role));
    }

    /**
     * Only the persisted key is read, so a hand-edited instance cannot steer
     * the decision toward a definition the subject does not hold.
     */
    public function test_a_configured_models_mutable_attributes_are_ignored(): void
    {
        $permission = Permission::query()->find(7);

        $this->assertInstanceOf(Permission::class, $permission);

        $permission->name = 'nobody-has-this';
        $permission->guard_name = 'api';

        $this->assertTrue($this->permissions()->allows($this->subject, $permission));

        $unheld = Permission::query()->find(50);

        $this->assertInstanceOf(Permission::class, $unheld);

        $unheld->name = 'seven';

        $this->assertFalse($this->permissions()->allows($this->subject, $unheld));
    }

    public function test_a_configured_replacement_model_and_its_subclass_are_accepted(): void
    {
        config()->set('gatetrail.models.permission', ReplacementPermissionDefinition::class);
        config()->set('gatetrail.models.role', ReplacementRoleDefinition::class);
        $this->forgetResolved();

        $permission = ReplacementPermissionDefinition::query()->find(7);
        $role = SubclassOfReplacementRole::query()->find(7);

        $this->assertNotNull($permission);
        $this->assertNotNull($role);

        $this->assertTrue($this->permissions()->allows($this->subject, $permission));
        $this->assertTrue(
            $this->roles()->has($this->subject, $role),
            'A subclass of the configured class is still a GateTrail definition.',
        );
    }

    public function test_an_unsaved_configured_model_denies(): void
    {
        $this->assertFalse($this->permissions()->allows($this->subject, new Permission));
        $this->assertFalse($this->roles()->has($this->subject, new Role));
    }

    public function test_a_model_without_a_usable_key_denies(): void
    {
        $permission = new Permission;
        $permission->exists = true;

        $this->assertFalse($this->permissions()->allows($this->subject, $permission));
    }

    /**
     * The escalation path: key `1` exists in nearly every table, so an
     * unrelated model implementing the empty marker interface must never be
     * compared by identifier.
     */
    public function test_an_unrelated_contract_implementation_with_a_colliding_key_denies(): void
    {
        $collidingPermission = new UnrelatedPermissionModel;
        $collidingPermission->exists = true;
        $collidingPermission->setAttribute('id', 7);

        $collidingRole = new UnrelatedRoleModel;
        $collidingRole->exists = true;
        $collidingRole->setAttribute('id', 7);

        $this->assertFalse(
            $this->permissions()->allows($this->subject, $collidingPermission),
        );
        $this->assertFalse($this->roles()->has($this->subject, $collidingRole));
    }

    public function test_an_unrelated_implementation_denies_without_throwing(): void
    {
        $unrelated = new UnrelatedPermissionModel;
        $unrelated->exists = true;
        $unrelated->setAttribute('id', 7);

        // No InvalidModelConfiguration: that reports a broken package
        // configuration, not a caller passing the wrong object.
        $this->assertFalse($this->permissions()->allows($this->subject, $unrelated));
    }

    public function test_a_cross_guard_configured_model_denies_without_disclosure(): void
    {
        $permission = Permission::query()->find(40);

        $this->assertInstanceOf(Permission::class, $permission);
        $this->assertSame('api', $permission->guard_name);

        $this->assertFalse($this->permissions()->allows($this->subject, $permission));
    }

    /**
     * A genuinely broken package configuration is a different failure and must
     * still surface.
     */
    public function test_a_genuinely_invalid_model_configuration_still_raises(): void
    {
        config()->set('gatetrail.models.permission', 'Not\\A\\Real\\Class');
        $this->forgetResolved();

        $this->expectException(InvalidModelConfiguration::class);

        $this->permissions()->allows($this->subject, 'seven');
    }

    // ----------------------------------------- 5. Rejected input costs nothing

    /**
     * @return array<string, array{mixed}>
     */
    public static function impossibleInputs(): array
    {
        return [
            'empty name' => [''],
            'whitespace-only name' => ['   '],
            'overflowing identifier' => ['__reference__:99999999999999999999'],
            'negative identifier' => ['__reference__:-1'],
            'alphabetic identifier' => ['__reference__:abc'],
            'unsaved model' => ['__unsaved__'],
            'unrelated model' => ['__unrelated__'],
        ];
    }

    #[DataProvider('impossibleInputs')]
    public function test_an_impossible_input_builds_no_snapshot(
        string $token,
    ): void {
        $clock = new CountingClock($this->time);
        $this->app->instance(ClockInterface::class, $clock);
        $this->forgetResolved();

        $statements = 0;
        $this->app['db']->listen(function () use (&$statements): void {
            $statements++;
        });

        $this->assertFalse(
            $this->permissions()->allows($this->subject, $this->impossible($token, 'permission')),
        );
        $this->assertFalse(
            $this->roles()->has($this->subject, $this->impossible($token, 'role')),
        );

        $this->assertSame(0, $statements, 'A rejected input must cost no query.');
        $this->assertSame(
            0,
            $clock->reads,
            'A rejected input must not build a snapshot at all.',
        );
        $this->assertSame(0, DB::connection('testing')->transactionLevel());
    }

    /**
     * The sharpest form of the ordering claim. If the subject were resolved
     * first, this would raise `InvalidSubject` instead of denying — so a
     * `false` here is proof that normalization ran before anything else.
     */
    public function test_an_impossible_input_denies_even_for_an_unresolvable_subject(): void
    {
        $unpersisted = new TestSubject;

        $this->assertFalse($this->permissions()->allows($unpersisted, ''));
        $this->assertFalse($this->roles()->has($unpersisted, ''));
    }

    public function test_a_valid_input_builds_exactly_one_snapshot(): void
    {
        $clock = new CountingClock($this->time);
        $this->app->instance(ClockInterface::class, $clock);
        $this->forgetResolved();

        $this->assertTrue($this->permissions()->allows($this->subject, 'seven'));

        $this->assertSame(1, $clock->reads);

        $this->assertTrue($this->roles()->has($this->subject, 'seven'));

        $this->assertSame(
            2,
            $clock->reads,
            'One snapshot per call, and no memoization yet.',
        );
    }

    /**
     * A check never asks the database about the requested definition, so the
     * only statements are the snapshot's own.
     */
    public function test_a_check_issues_no_separate_definition_lookup(): void
    {
        $statements = [];

        $this->app['db']->listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->assertTrue($this->permissions()->allows($this->subject, 'seven'));

        // Queries 1, 2 and one query-3 chunk. Nothing else.
        $this->assertCount(3, $statements);

        foreach ($statements as $sql) {
            $this->assertStringStartsWith('select ', $sql);
            $this->assertStringNotContainsString('seven', $sql);
        }
    }

    // ------------------------------------------------------------- 6. Failures

    public function test_an_unresolvable_subject_raises_for_a_valid_input(): void
    {
        $this->expectException(InvalidSubject::class);

        $this->permissions()->allows(new TestSubject, 'seven');
    }

    public function test_an_unresolvable_guard_raises_for_a_valid_input(): void
    {
        $subject = GuardlessResolverSubject::query()->create(['name' => 'Guardless']);

        config()->set('gatetrail.guards.default', '');
        config()->set('auth.defaults.guard', '');

        $this->expectException(InvalidGuard::class);

        $this->roles()->has($subject, 'seven');
    }

    public function test_a_database_failure_propagates_unchanged(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::drop('acl_role_assignments');
        Schema::enableForeignKeyConstraints();

        $this->expectException(QueryException::class);

        $this->roles()->has($this->subject, 'seven');
    }

    /**
     * A denial is an answer, not an error. Throwing would also disclose which
     * of the three absent cases applied.
     */
    public function test_a_denial_never_throws_and_discloses_nothing(): void
    {
        foreach (['deactivated', 'api-only', 'unheld', 'nobody-has-this'] as $name) {
            $this->assertFalse($this->permissions()->allows($this->subject, $name));
            $this->assertFalse($this->roles()->has($this->subject, $name));
        }

        foreach ([30, 40, 50, 9999] as $identifier) {
            $this->assertFalse($this->permissions()->allows($this->subject, $identifier));
            $this->assertFalse($this->roles()->has($this->subject, $identifier));
        }
    }

    // -------------------------------------------------------------- 7. Parity

    /**
     * The parity claim, in the only direction that can be exhaustively
     * checked: every entry in the collection is `true`, and a set of absent
     * entries is `false`.
     */
    public function test_allows_is_true_for_exactly_the_effective_permissions(): void
    {
        $permissions = $this->permissions();
        $effective = $permissions->effectivePermissions($this->subject);

        $this->assertGreaterThan(0, $effective->count());

        foreach ($effective as $permission) {
            $this->assertTrue($permissions->allows($this->subject, $permission->name));
            $this->assertTrue($permissions->allows($this->subject, $permission->id));
            $this->assertTrue(
                $permissions->allows(
                    $this->subject,
                    PermissionReference::id($permission->id),
                ),
            );
        }

        $present = $effective
            ->map(static fn (EffectivePermission $p): string => $p->name)
            ->all();

        foreach (['deactivated', 'api-only', 'unheld'] as $absent) {
            $this->assertNotContains($absent, $present);
            $this->assertFalse($permissions->allows($this->subject, $absent));
        }
    }

    public function test_has_is_true_for_exactly_the_effective_roles(): void
    {
        $roles = $this->roles();
        $effective = $roles->effectiveRoles($this->subject);

        $this->assertGreaterThan(0, $effective->count());

        foreach ($effective as $role) {
            $this->assertTrue($roles->has($this->subject, $role->name));
            $this->assertTrue($roles->has($this->subject, $role->id));
            $this->assertTrue(
                $roles->has($this->subject, RoleReference::id($role->id)),
            );
        }

        $present = $effective->map(static fn (EffectiveRole $r): string => $r->name)->all();

        foreach (['deactivated', 'api-only', 'unheld'] as $absent) {
            $this->assertNotContains($absent, $present);
            $this->assertFalse($roles->has($this->subject, $absent));
        }
    }

    /**
     * Both public resolvers must observe the same evaluated role state — that
     * is the whole reason they share one snapshot.
     */
    public function test_both_resolvers_observe_the_same_role_state(): void
    {
        $fromRoleResolver = $this->roles()->effectiveRoles($this->subject)
            ->map(static fn (EffectiveRole $r): string => $r->name)
            ->all();

        foreach ($fromRoleResolver as $name) {
            $this->assertTrue($this->roles()->has($this->subject, $name));
        }

        // The permission side is derived through those same roles, so a role
        // that grants a permission must be visible on both sides.
        $this->assertTrue($this->roles()->has($this->subject, 'records.read'));
        $this->assertTrue(
            $this->permissions()->allows($this->subject, 'role-derived'),
        );
    }

    public function test_a_role_that_grants_nothing_remains_visible(): void
    {
        $this->assertTrue($this->roles()->has($this->subject, 'seven'));
        $this->assertTrue(
            $this->roles()->effectiveRoles($this->subject)
                ->contains(static fn (EffectiveRole $r): bool => $r->name === 'seven'),
        );
    }

    public function test_direct_and_role_derived_duplicates_appear_once(): void
    {
        $named = $this->permissions()->effectivePermissions($this->subject)
            ->filter(static fn (EffectivePermission $p): bool => $p->name === 'shared')
            ->count();

        $this->assertSame(1, $named);
        $this->assertTrue($this->permissions()->allows($this->subject, 'shared'));
    }

    // --------------------------------------------------- 8. Collection safety

    #[DataProvider('kinds')]
    public function test_each_projection_returns_a_fresh_collection(string $kind): void
    {
        $first = $this->collection($kind);
        $second = $this->collection($kind);

        $this->assertInstanceOf(Collection::class, $first);
        $this->assertNotSame($first, $second);
        $this->assertEquals($first->all(), $second->all());
    }

    #[DataProvider('kinds')]
    public function test_mutating_a_projection_changes_nothing(string $kind): void
    {
        $original = $this->collection($kind)->count();

        $mutated = $this->collection($kind);
        $mutated->push($mutated->first());
        $mutated->forget(0);
        $mutated->transform(static fn (): string => 'clobbered');

        $this->assertSame($original, $this->collection($kind)->count());
        $this->assertTrue($this->permissions()->allows($this->subject, 'seven'));
        $this->assertTrue($this->roles()->has($this->subject, 'seven'));
    }

    // ------------------------------------------------------------------ Helpers

    private function permissions(): PermissionResolver
    {
        return $this->app->make(PermissionResolver::class);
    }

    private function roles(): RoleResolver
    {
        return $this->app->make(RoleResolver::class);
    }

    /**
     * @return Collection<int, EffectivePermission|EffectiveRole>
     */
    private function collection(string $kind): Collection
    {
        return $kind === 'permission'
            ? $this->permissions()->effectivePermissions($this->subject)
            : $this->roles()->effectiveRoles($this->subject);
    }

    /**
     * Data providers cannot carry live objects, so impossible inputs are
     * described by token and built here.
     */
    private function impossible(string $token, string $kind): mixed
    {
        if (str_starts_with($token, '__reference__:')) {
            $id = substr($token, strlen('__reference__:'));

            return $kind === 'permission'
                ? PermissionReference::id($id)
                : RoleReference::id($id);
        }

        if ($token === '__unsaved__') {
            return $kind === 'permission' ? new Permission : new Role;
        }

        if ($token === '__unrelated__') {
            $model = $kind === 'permission'
                ? new UnrelatedPermissionModel
                : new UnrelatedRoleModel;
            $model->exists = true;
            $model->setAttribute('id', 7);

            return $model;
        }

        return $token;
    }

    private function forgetResolved(): void
    {
        $this->app->forgetScopedInstances();
        $this->app->forgetInstance(ModelRegistry::class);
        $this->app->forgetInstance(
            EffectiveAccessQueryBuilder::class,
        );
        $this->app->forgetInstance(
            EffectiveAccessRowMapper::class,
        );
    }

    /**
     * Seeds a permission granted through a role, and optionally directly too.
     */
    private function seedRoleDerivedPermission(
        int $id,
        string $name,
        int $roleId,
        bool $direct = false,
    ): void {
        $connection = DB::connection('testing');

        $connection->table('acl_permissions')->insert([
            'id' => $id,
            'name' => $name,
            'guard_name' => 'web',
            'created_at' => '2026-08-17 11:00:00',
            'updated_at' => '2026-08-17 11:00:00',
        ]);
        $connection->table('acl_role_permissions')->insert([
            'role_id' => $roleId,
            'permission_id' => $id,
            'assigned_at' => '2026-08-17 11:00:00',
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'test-suite',
            'created_at' => '2026-08-17 11:00:00',
            'updated_at' => '2026-08-17 11:00:00',
        ]);

        if (! $direct) {
            return;
        }

        $connection->table('acl_permission_assignments')->insert([
            'subject_type' => $this->subject->getMorphClass(),
            'subject_id' => $this->subject->getKey(),
            'permission_id' => $id,
            'assigned_at' => '2026-08-17 11:00:00',
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'test-suite',
            'created_at' => '2026-08-17 11:00:00',
            'updated_at' => '2026-08-17 11:00:00',
        ]);
    }

    /**
     * Seeds a role and a permission with the same identifier and name, plus
     * the assignments that make them effective, so one matrix covers both
     * resolvers.
     */
    private function seedDefinition(
        int $id,
        string $name,
        string $guard = 'web',
        bool $deactivated = false,
        bool $held = true,
    ): void {
        $connection = DB::connection('testing');
        $timestamps = [
            'created_at' => '2026-08-17 11:00:00',
            'updated_at' => '2026-08-17 11:00:00',
            'deleted_at' => $deactivated ? '2026-08-17 11:30:00' : null,
        ];

        $connection->table('acl_roles')->insert([
            'id' => $id,
            'name' => $name,
            'guard_name' => $guard,
            ...$timestamps,
        ]);
        $connection->table('acl_permissions')->insert([
            'id' => $id,
            'name' => $name,
            'guard_name' => $guard,
            ...$timestamps,
        ]);

        if (! $held) {
            return;
        }

        $connection->table('acl_role_assignments')->insert([
            'subject_type' => $this->subject->getMorphClass(),
            'subject_id' => $this->subject->getKey(),
            'role_id' => $id,
            'assigned_at' => '2026-08-17 11:00:00',
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'test-suite',
            'created_at' => '2026-08-17 11:00:00',
            'updated_at' => '2026-08-17 11:00:00',
        ]);
        $connection->table('acl_permission_assignments')->insert([
            'subject_type' => $this->subject->getMorphClass(),
            'subject_id' => $this->subject->getKey(),
            'permission_id' => $id,
            'assigned_at' => '2026-08-17 11:00:00',
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'test-suite',
            'created_at' => '2026-08-17 11:00:00',
            'updated_at' => '2026-08-17 11:00:00',
        ]);
    }
}

final class ReplacementPermissionDefinition extends Permission {}

class ReplacementRoleDefinition extends Role {}

final class SubclassOfReplacementRole extends ReplacementRoleDefinition {}

/**
 * An application model that implements the empty marker interface and whose
 * key space collides with GateTrail's.
 */
final class UnrelatedPermissionModel extends Model implements PermissionContract
{
    protected $table = 'unrelated_definitions';
}

final class UnrelatedRoleModel extends Model implements RoleContract
{
    protected $table = 'unrelated_definitions';
}

final class GuardlessResolverSubject extends Model
{
    public $timestamps = false;

    protected $table = 'test_subjects';

    protected $guarded = [];
}

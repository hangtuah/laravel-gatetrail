<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Access;

use DateTimeImmutable;
use DateTimeZone;
use HangTuah\GateTrail\Access\DefaultPermissionResolver;
use HangTuah\GateTrail\Access\DefaultRoleResolver;
use HangTuah\GateTrail\Access\EffectiveAccessMemo;
use HangTuah\GateTrail\Access\EffectiveAccessQueryBuilder;
use HangTuah\GateTrail\Access\EffectiveAccessResolver;
use HangTuah\GateTrail\Access\EffectiveAccessRowMapper;
use HangTuah\GateTrail\Access\EffectiveAccessSnapshot;
use HangTuah\GateTrail\Access\EffectivePermission;
use HangTuah\GateTrail\Access\EffectiveRole;
use HangTuah\GateTrail\Access\FlushEffectiveAccessMemo;
use HangTuah\GateTrail\Access\RequestedDefinitionNormalizer;
use HangTuah\GateTrail\Contracts\AssignmentManager;
use HangTuah\GateTrail\Contracts\PermissionManager;
use HangTuah\GateTrail\Contracts\PermissionResolver;
use HangTuah\GateTrail\Contracts\RoleManager;
use HangTuah\GateTrail\Contracts\RoleResolver;
use HangTuah\GateTrail\Events\MutationCommitted;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\PermissionReference;
use HangTuah\GateTrail\RoleChanges;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Fixtures\MutableClock;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use ReflectionObject;
use ReflectionProperty;

/**
 * Request-local memoization, end to end.
 *
 * The property that matters is not speed. It is that **a warm hit is never
 * stale within its own scope**: once this scope commits a managed mutation,
 * the next check here must see it, with no tolerated interval of any duration.
 * That is asserted through the real managers and the real after-commit
 * boundary, not by hand-dispatching the event — a hand-dispatched event would
 * prove the listener works while proving nothing about whether it is wired to
 * anything.
 *
 * Three ordering hazards are pinned alongside it:
 *
 * - a **caller-owned transaction** bypasses the memo in both directions, so a
 *   snapshot derived from writes a rollback later discards is never stored;
 * - a **second boundary** always rebuilds, so a memo cannot outlive a
 *   lifecycle edge;
 * - a **scope boundary** discards everything, so a long-lived worker cannot
 *   answer one request from another's state.
 */
final class MemoizedResolutionTest extends TestCase
{
    private DateTimeImmutable $time;

    private MutableClock $clock;

    private TestSubject $subject;

    private Role $role;

    private Permission $permission;

    /**
     * @return array<string, array{string}>
     */
    public static function collections(): array
    {
        return [
            'permissions' => ['permissions'],
            'roles' => ['roles'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--database' => 'testing'])->assertExitCode(0);

        $this->time = new DateTimeImmutable('2026-08-17 12:00:00', new DateTimeZone('UTC'));
        $this->clock = new MutableClock($this->time);
        $this->app->instance(ClockInterface::class, $this->clock);

        $this->role = Role::query()->create(['name' => 'reviewer', 'guard_name' => 'web']);
        $this->permission = Permission::query()->create([
            'name' => 'documents.approve',
            'guard_name' => 'web',
        ]);
        $this->subject = TestSubject::query()->create(['name' => 'Subject']);
    }

    // ------------------------------------------------------------ Warm hits

    public function test_a_second_call_within_one_second_costs_nothing(): void
    {
        $this->assignRole();

        $cold = $this->captureStatements(fn () => $this->permissions()->allows($this->subject, 'documents.approve'));
        $warm = $this->captureStatements(fn () => $this->permissions()->allows($this->subject, 'documents.approve'));

        $this->assertCount(3, $cold);
        $this->assertCount(0, $warm, 'A warm hit executes no statement at all.');
    }

    public function test_ten_repeated_checks_share_one_snapshot(): void
    {
        $this->assignRole();
        $this->grantToRole();

        $statements = $this->captureStatements(function (): void {
            for ($index = 0; $index < 10; $index++) {
                $this->assertTrue(
                    $this->permissions()->allows($this->subject, 'documents.approve'),
                );
            }
        });

        $this->assertCount(3, $statements);
    }

    public function test_ten_role_checks_share_that_same_snapshot(): void
    {
        $this->assignRole();

        $statements = $this->captureStatements(function (): void {
            for ($index = 0; $index < 10; $index++) {
                $this->assertTrue($this->roles()->has($this->subject, 'reviewer'));
            }
        });

        $this->assertCount(3, $statements);
    }

    /**
     * Both public resolvers project over one scoped internal resolver, so they
     * share one memo entry rather than one each.
     */
    public function test_both_resolvers_share_one_memo_entry(): void
    {
        $this->assignRole();
        $this->grantToRole();

        $statements = $this->captureStatements(function (): void {
            $this->permissions()->allows($this->subject, 'documents.approve');
            $this->roles()->has($this->subject, 'reviewer');
            $this->permissions()->effectivePermissions($this->subject);
            $this->roles()->effectiveRoles($this->subject);
            $this->permissions()->allows($this->subject, PermissionReference::id($this->permission->getKey()));
        });

        $this->assertCount(3, $statements);
        $this->assertSame(1, $this->memoSize());
    }

    public function test_a_different_subject_is_a_different_entry(): void
    {
        $other = TestSubject::query()->create(['name' => 'Other']);

        $this->assignRole();

        $this->permissions()->allows($this->subject, 'documents.approve');
        $this->permissions()->allows($other, 'documents.approve');

        $this->assertSame(2, $this->memoSize());
    }

    /**
     * ADR 0003 requires the guard in every memoization key. A subject whose
     * guard comes from configuration lets the same subject be evaluated under
     * two guards in one second.
     */
    public function test_a_different_guard_is_a_different_entry(): void
    {
        $configured = ConfiguredGuardSubject::query()->create(['name' => 'Configured']);

        config()->set('gatetrail.guards.default', 'web');
        $this->permissions()->allows($configured, 'documents.approve');

        config()->set('gatetrail.guards.default', 'api');
        $this->permissions()->allows($configured, 'documents.approve');

        $this->assertSame(2, $this->memoSize());
    }

    /**
     * The correctness half of the design. A memo keyed without the second
     * could serve an answer built before an assignment expired.
     */
    public function test_a_second_boundary_rebuilds(): void
    {
        $this->assignRole();

        $first = $this->captureStatements(fn () => $this->permissions()->allows($this->subject, 'documents.approve'));

        $this->freezeAt($this->time->modify('+1 second'));

        $second = $this->captureStatements(fn () => $this->permissions()->allows($this->subject, 'documents.approve'));

        $this->assertCount(3, $first);
        $this->assertCount(3, $second, 'A new second is a new evaluation.');
        $this->assertSame(2, $this->memoSize());
    }

    /**
     * A clock that reports another timezone or microsecond precision must
     * still land in one entry within a second — normalization happens in the
     * resolver rather than being assumed of the clock.
     */
    public function test_a_non_utc_subsecond_clock_still_memoizes(): void
    {
        $this->assignRole();

        $local = new DateTimeImmutable(
            '2026-08-17 20:00:00.123456',
            new DateTimeZone('Asia/Kuala_Lumpur'),
        );

        $this->freezeAt($local);
        $cold = $this->captureStatements(fn () => $this->permissions()->allows($this->subject, 'documents.approve'));

        $this->freezeAt($local->modify('+400000 microseconds'));
        $warm = $this->captureStatements(fn () => $this->permissions()->allows($this->subject, 'documents.approve'));

        $this->assertCount(3, $cold);
        $this->assertCount(0, $warm);
        $this->assertSame(1, $this->memoSize());
    }

    public function test_the_stored_instant_is_a_utc_whole_second(): void
    {
        $this->freezeAt(new DateTimeImmutable(
            '2026-08-17 20:00:00.987654',
            new DateTimeZone('Asia/Kuala_Lumpur'),
        ));

        $snapshot = $this->app->make(EffectiveAccessResolver::class)
            ->resolve($this->subject);

        $this->assertSame('UTC', $snapshot->evaluatedAt()->getTimezone()->getName());
        $this->assertSame('000000', $snapshot->evaluatedAt()->format('u'));
        $this->assertSame(
            '2026-08-17 12:00:00',
            $snapshot->evaluatedAt()->format('Y-m-d H:i:s'),
        );
    }

    // -------------------------------------------- Caller-owned transactions

    public function test_every_call_inside_a_transaction_rebuilds(): void
    {
        $this->assignRole();

        DB::connection('testing')->transaction(function (): void {
            $first = $this->captureStatements(fn () => $this->permissions()->allows($this->subject, 'documents.approve'));
            $second = $this->captureStatements(fn () => $this->permissions()->allows($this->subject, 'documents.approve'));

            $this->assertCount(3, $first);
            $this->assertCount(3, $second, 'Inside a transaction there is no memo.');
        });
    }

    public function test_a_transaction_local_snapshot_is_never_stored(): void
    {
        $this->assignRole();

        DB::connection('testing')->transaction(function (): void {
            $this->permissions()->allows($this->subject, 'documents.approve');

            $this->assertSame(
                0,
                $this->memoSize(),
                'A snapshot a rollback could invalidate must not be memoized.',
            );
        });

        $this->assertSame(0, $this->memoSize());
    }

    /**
     * A pre-transaction entry holds committed state. It must be neither
     * returned inside the transaction — where uncommitted writes are visible —
     * nor evicted by it.
     */
    public function test_a_pre_transaction_entry_is_bypassed_but_preserved(): void
    {
        $this->assignRole();

        $this->assertFalse($this->permissions()->allows($this->subject, 'documents.approve'));
        $this->assertSame(1, $this->memoSize());

        $before = $this->memoEntries();

        DB::connection('testing')->transaction(function () use ($before): void {
            $this->grantToRole();

            $this->assertTrue(
                $this->permissions()->allows($this->subject, 'documents.approve'),
                'The transaction sees its own uncommitted grant.',
            );
            $this->assertSame(
                $before,
                $this->memoEntries(),
                'The committed entry is neither returned nor disturbed.',
            );
        });
    }

    public function test_a_pre_transaction_entry_survives_a_rollback(): void
    {
        $this->assignRole();

        $this->assertFalse($this->permissions()->allows($this->subject, 'documents.approve'));

        $before = $this->memoEntries();

        try {
            DB::connection('testing')->transaction(function (): void {
                $this->grantToRole();

                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
            // deliberate
        }

        $this->assertSame($before, $this->memoEntries());

        $warm = $this->captureStatements(fn () => $this->permissions()->allows($this->subject, 'documents.approve'));

        $this->assertCount(0, $warm, 'The committed entry is still warm.');
        $this->assertSame(0, DB::connection('testing')->transactionLevel());
    }

    public function test_reads_inside_a_transaction_take_no_lock_and_write_nothing(): void
    {
        $this->assignRole();

        DB::connection('testing')->transaction(function (): void {
            $statements = $this->captureStatements(fn () => $this->permissions()->allows($this->subject, 'documents.approve'));

            foreach ($statements as $sql) {
                $this->assertStringStartsWith('select ', $sql);
                $this->assertStringNotContainsStringIgnoringCase('for update', $sql);
                $this->assertStringNotContainsStringIgnoringCase('acl_assignment_locks', $sql);
            }

            $this->assertSame(1, DB::connection('testing')->transactionLevel());
        });
    }

    // ---------------------------------------- MutationCommitted coherence

    /**
     * The guarantee, through the real manager and the real after-commit
     * boundary: a memoized allow becomes a deny with no stale interval.
     */
    public function test_a_committed_revocation_is_visible_immediately(): void
    {
        $assignment = $this->manager()->assignRole(
            subject: $this->subject,
            role: 'reviewer',
            actor: SystemActor::named('test-suite'),
            reason: 'Memo coherence',
        );

        $this->assertTrue($this->roles()->has($this->subject, 'reviewer'));
        $this->assertSame(1, $this->memoSize());

        $this->manager()->revokeRole(
            assignment: $assignment->resource,
            actor: SystemActor::named('test-suite'),
            reason: 'Memo coherence',
        );

        $this->assertSame(0, $this->memoSize(), 'The commit flushed the memo.');
        $this->assertFalse($this->roles()->has($this->subject, 'reviewer'));
    }

    public function test_a_committed_grant_is_visible_immediately(): void
    {
        $this->assertFalse($this->permissions()->allows($this->subject, 'documents.approve'));
        $this->assertSame(1, $this->memoSize());

        $this->manager()->grantDirectPermission(
            subject: $this->subject,
            permission: 'documents.approve',
            actor: SystemActor::named('test-suite'),
            reason: 'Memo coherence',
        );

        $this->assertSame(0, $this->memoSize());
        $this->assertTrue($this->permissions()->allows($this->subject, 'documents.approve'));
    }

    public function test_a_committed_role_deactivation_is_visible_immediately(): void
    {
        $this->manager()->assignRole(
            subject: $this->subject,
            role: 'reviewer',
            actor: SystemActor::named('test-suite'),
            reason: 'Memo coherence',
        );

        $this->assertTrue($this->roles()->has($this->subject, 'reviewer'));

        $this->app->make(RoleManager::class)->deactivate(
            role: 'reviewer',
            actor: SystemActor::named('test-suite'),
            reason: 'Memo coherence',
        );

        $this->assertFalse($this->roles()->has($this->subject, 'reviewer'));
    }

    public function test_a_committed_permission_deactivation_is_visible_immediately(): void
    {
        $this->manager()->grantDirectPermission(
            subject: $this->subject,
            permission: 'documents.approve',
            actor: SystemActor::named('test-suite'),
            reason: 'Memo coherence',
        );

        $this->assertTrue($this->permissions()->allows($this->subject, 'documents.approve'));

        $this->app->make(PermissionManager::class)->deactivate(
            permission: 'documents.approve',
            actor: SystemActor::named('test-suite'),
            reason: 'Memo coherence',
        );

        $this->assertFalse($this->permissions()->allows($this->subject, 'documents.approve'));
    }

    public function test_a_committed_restore_is_visible_immediately(): void
    {
        $this->manager()->assignRole(
            subject: $this->subject,
            role: 'reviewer',
            actor: SystemActor::named('test-suite'),
            reason: 'Memo coherence',
        );
        $this->app->make(RoleManager::class)->deactivate(
            role: 'reviewer',
            actor: SystemActor::named('test-suite'),
            reason: 'Memo coherence',
        );

        $this->assertFalse($this->roles()->has($this->subject, 'reviewer'));

        $this->app->make(RoleManager::class)->restore(
            role: 'reviewer',
            actor: SystemActor::named('test-suite'),
            reason: 'Memo coherence',
        );

        $this->assertTrue($this->roles()->has($this->subject, 'reviewer'));
    }

    /**
     * A definition mutation carries no subject at all, which is exactly why
     * the flush is wholesale rather than targeted.
     */
    public function test_an_event_without_a_subject_still_flushes_everything(): void
    {
        $other = TestSubject::query()->create(['name' => 'Other']);

        $this->permissions()->allows($this->subject, 'documents.approve');
        $this->permissions()->allows($other, 'documents.approve');

        $this->assertSame(2, $this->memoSize());

        $this->app->make(RoleManager::class)->create(
            name: 'auditor',
            guard: 'web',
            actor: SystemActor::named('test-suite'),
        );

        $this->assertSame(0, $this->memoSize());
    }

    public function test_an_unchanged_mutation_dispatches_nothing_and_flushes_nothing(): void
    {
        $this->permissions()->allows($this->subject, 'documents.approve');

        $this->assertSame(1, $this->memoSize());

        $result = $this->app->make(RoleManager::class)->update(
            role: 'reviewer',
            changes: RoleChanges::description(null),
            actor: SystemActor::named('test-suite'),
            reason: 'No change at all',
        );

        $this->assertSame(MutationOutcome::Unchanged, $result->outcome);
        $this->assertSame(
            1,
            $this->memoSize(),
            'No event, so nothing to flush.',
        );
    }

    public function test_a_rolled_back_mutation_does_not_flush(): void
    {
        $this->permissions()->allows($this->subject, 'documents.approve');

        $before = $this->memoEntries();

        try {
            DB::connection('testing')->transaction(function (): void {
                $this->manager()->grantDirectPermission(
                    subject: $this->subject,
                    permission: 'documents.approve',
                    actor: SystemActor::named('test-suite'),
                    reason: 'Rolled back',
                );

                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
            // deliberate
        }

        $this->assertSame(
            $before,
            $this->memoEntries(),
            'No commit, no event, no flush.',
        );
    }

    /**
     * Nested transactions follow ADR 0010's outermost boundary: the event, and
     * therefore the flush, arrives only after the outer commit.
     */
    public function test_a_nested_transaction_flushes_only_after_the_outer_commit(): void
    {
        $this->permissions()->allows($this->subject, 'documents.approve');

        $this->assertSame(1, $this->memoSize());

        DB::connection('testing')->transaction(function (): void {
            DB::connection('testing')->transaction(function (): void {
                $this->manager()->grantDirectPermission(
                    subject: $this->subject,
                    permission: 'documents.approve',
                    actor: SystemActor::named('test-suite'),
                    reason: 'Nested',
                );
            });

            $this->assertSame(
                1,
                $this->memoSize(),
                'The inner commit is not the boundary.',
            );
        });

        $this->assertSame(0, $this->memoSize());
        $this->assertTrue($this->permissions()->allows($this->subject, 'documents.approve'));
    }

    // ------------------------------------------------- Listener lifecycle

    public function test_the_listener_retains_no_memo_instance(): void
    {
        $listener = $this->app->make(FlushEffectiveAccessMemo::class);

        foreach ((new ReflectionObject($listener))->getProperties() as $property) {
            $this->assertNotInstanceOf(
                EffectiveAccessMemo::class,
                $property->getValue($listener),
                'A captured memo would be the first scope\'s, forever.',
            );
        }
    }

    /**
     * The Octane and queue-worker hazard, reproduced: the listener is resolved
     * before the scope reset and must still clear the *new* scope's memo.
     */
    public function test_the_listener_clears_the_current_scope_after_a_reset(): void
    {
        $listener = $this->app->make(FlushEffectiveAccessMemo::class);

        $stale = $this->app->make(EffectiveAccessMemo::class);
        $this->permissions()->allows($this->subject, 'documents.approve');

        $this->assertSame(1, $this->entryCountOf($stale));

        $this->app->forgetScopedInstances();

        $fresh = $this->app->make(EffectiveAccessMemo::class);

        $this->assertNotSame($stale, $fresh);

        $this->permissions()->allows($this->subject, 'documents.approve');

        $this->assertSame(1, $this->entryCountOf($fresh));

        $listener->handle($this->event());

        $this->assertSame(0, $this->entryCountOf($fresh), 'The live scope cleared.');
        $this->assertSame(
            1,
            $this->entryCountOf($stale),
            'The discarded store was not the one flushed.',
        );
    }

    /**
     * A second application is a second scope. One flushing must not touch the
     * other, which is the same boundary as two web workers.
     */
    public function test_a_second_container_does_not_clear_this_scopes_memo(): void
    {
        $this->permissions()->allows($this->subject, 'documents.approve');

        $this->assertSame(1, $this->memoSize());

        $second = $this->createApplication();
        $second->make(FlushEffectiveAccessMemo::class)->handle($this->event());

        $this->assertSame(
            1,
            $this->memoSize(),
            'Another container is another process, as far as the memo goes.',
        );
    }

    public function test_a_new_scope_starts_empty(): void
    {
        $this->permissions()->allows($this->subject, 'documents.approve');

        $this->assertSame(1, $this->memoSize());

        $this->app->forgetScopedInstances();

        $this->assertSame(0, $this->memoSize());
    }

    public function test_no_singleton_retains_a_discarded_memo(): void
    {
        $stale = $this->app->make(EffectiveAccessMemo::class);

        foreach ([
            EffectiveAccessQueryBuilder::class,
            EffectiveAccessRowMapper::class,
            RequestedDefinitionNormalizer::class,
            FlushEffectiveAccessMemo::class,
        ] as $singleton) {
            $service = $this->app->make($singleton);

            foreach ((new ReflectionObject($service))->getProperties() as $property) {
                $this->assertNotSame($stale, $property->getValue($service));
            }
        }

        $this->app->forgetScopedInstances();

        $this->assertNotSame($stale, $this->app->make(EffectiveAccessMemo::class));
    }

    public function test_the_memo_and_every_reader_of_it_are_scoped(): void
    {
        $instances = [];

        foreach ([
            EffectiveAccessMemo::class,
            EffectiveAccessResolver::class,
            DefaultPermissionResolver::class,
            DefaultRoleResolver::class,
            PermissionResolver::class,
            RoleResolver::class,
        ] as $abstract) {
            $instances[$abstract] = $this->app->make($abstract);
        }

        $this->app->forgetScopedInstances();

        foreach ($instances as $abstract => $first) {
            $this->assertNotSame(
                $first,
                $this->app->make($abstract),
                sprintf('[%s] must not survive a scope flush.', $abstract),
            );
        }
    }

    // ------------------------------------------------- Collections and parity

    #[DataProvider('collections')]
    public function test_a_memoized_projection_is_still_fresh_each_call(
        string $kind,
    ): void {
        $this->assignRole();
        $this->grantToRole();

        $first = $this->collection($kind);
        $second = $this->collection($kind);

        $this->assertNotSame($first, $second);
        $this->assertEquals($first->all(), $second->all());
    }

    /**
     * The hazard memoization introduces: a consumer mutating a returned
     * collection could otherwise corrupt state that later checks reuse.
     */
    #[DataProvider('collections')]
    public function test_mutating_a_projection_cannot_corrupt_the_memo(
        string $kind,
    ): void {
        $this->assignRole();
        $this->grantToRole();

        $original = $this->collection($kind)->count();

        $mutated = $this->collection($kind);
        $mutated->push($mutated->first());
        $mutated->forget(0);
        $mutated->transform(static fn (): string => 'clobbered');
        $mutated->pop();

        $this->assertSame($original, $this->collection($kind)->count());
        $this->assertTrue($this->permissions()->allows($this->subject, 'documents.approve'));
        $this->assertTrue($this->roles()->has($this->subject, 'reviewer'));
    }

    public function test_only_immutable_values_are_retained(): void
    {
        $this->assignRole();
        $this->grantToRole();

        $this->permissions()->allows($this->subject, 'documents.approve');

        foreach ($this->memoEntries() as $snapshot) {
            foreach ($snapshot->roles() as $role) {
                $this->assertInstanceOf(EffectiveRole::class, $role);
            }

            foreach ($snapshot->permissions() as $permission) {
                $this->assertInstanceOf(EffectivePermission::class, $permission);
            }
        }
    }

    public function test_role_derived_and_direct_parity_survives_memoization(): void
    {
        $this->assignRole();
        $this->grantToRole();
        $this->manager()->grantDirectPermission(
            subject: $this->subject,
            permission: 'documents.approve',
            actor: SystemActor::named('test-suite'),
            reason: 'Both sources',
        );

        $permissions = $this->permissions();
        $effective = $permissions->effectivePermissions($this->subject);

        $this->assertSame(1, $effective->count());

        foreach ($effective as $permission) {
            $this->assertTrue($permissions->allows($this->subject, $permission->name));
            $this->assertTrue($permissions->allows($this->subject, $permission->id));
        }
    }

    // ---------------------------------------------------- Impossible input

    /**
     * Increment 7's normalization-first ordering is unchanged: an impossible
     * input never reaches the resolver, so it cannot create, promote, evict or
     * flush anything.
     */
    public function test_an_impossible_input_never_touches_the_memo(): void
    {
        $this->assignRole();

        $this->permissions()->allows($this->subject, 'documents.approve');

        $before = $this->memoEntries();

        $statements = $this->captureStatements(function (): void {
            $this->assertFalse($this->permissions()->allows($this->subject, ''));
            $this->assertFalse($this->roles()->has($this->subject, '   '));
            $this->assertFalse(
                $this->permissions()->allows(
                    $this->subject,
                    PermissionReference::id('99999999999999999999'),
                ),
            );
            $this->assertFalse($this->permissions()->allows($this->subject, new Permission));
        });

        $this->assertSame([], $statements);
        $this->assertSame($before, $this->memoEntries());
        $this->assertSame(1, $this->memoSize());
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

    private function manager(): AssignmentManager
    {
        return $this->app->make(AssignmentManager::class);
    }

    /**
     * @return Collection<int, EffectivePermission|EffectiveRole>
     */
    private function collection(string $kind): Collection
    {
        return $kind === 'permissions'
            ? $this->permissions()->effectivePermissions($this->subject)
            : $this->roles()->effectiveRoles($this->subject);
    }

    /**
     * Moves the instant without touching the container, so the memo under test
     * survives the change.
     */
    private function freezeAt(DateTimeImmutable $instant): void
    {
        $this->clock->moveTo($instant);
    }

    private function event(): MutationCommitted
    {
        return MutationCommitted::record(
            mutation: 'role.created',
            outcome: MutationOutcome::Created,
            targetType: 'role',
            targetId: 1,
            subjectType: null,
            subjectId: null,
            actorType: 'gatetrail.system',
            actorId: 'test-suite',
            auditId: null,
            occurredAt: $this->time,
            requestId: null,
        );
    }

    /**
     * @return list<string>
     */
    private function captureStatements(callable $work): array
    {
        $statements = [];

        $listener = function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        };

        $this->app['db']->listen($listener);

        $work();

        // Laravel has no public listener removal, so each call registers a new
        // one; only the statements observed during `$work` are returned.
        $captured = $statements;
        $statements = [];

        return $captured;
    }

    /**
     * @return array<string, EffectiveAccessSnapshot>
     */
    private function memoEntries(): array
    {
        return $this->entriesOf($this->app->make(EffectiveAccessMemo::class));
    }

    private function memoSize(): int
    {
        return count($this->memoEntries());
    }

    /**
     * @return array<string, EffectiveAccessSnapshot>
     */
    private function entriesOf(EffectiveAccessMemo $memo): array
    {
        /** @var array<string, EffectiveAccessSnapshot> $entries */
        $entries = (new ReflectionProperty($memo, 'entries'))->getValue($memo);

        return $entries;
    }

    private function entryCountOf(EffectiveAccessMemo $memo): int
    {
        return count($this->entriesOf($memo));
    }

    private function assignRole(): void
    {
        DB::connection('testing')->table('acl_role_assignments')->insert([
            'subject_type' => $this->subject->getMorphClass(),
            'subject_id' => $this->subject->getKey(),
            'role_id' => $this->role->getKey(),
            'assigned_at' => '2026-08-17 11:00:00',
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'test-suite',
            'created_at' => '2026-08-17 11:00:00',
            'updated_at' => '2026-08-17 11:00:00',
        ]);
    }

    private function grantToRole(): void
    {
        DB::connection('testing')->table('acl_role_permissions')->insert([
            'role_id' => $this->role->getKey(),
            'permission_id' => $this->permission->getKey(),
            'assigned_at' => '2026-08-17 11:00:00',
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'test-suite',
            'created_at' => '2026-08-17 11:00:00',
            'updated_at' => '2026-08-17 11:00:00',
        ]);
    }
}

/**
 * A subject with no declared guard, so its guard comes from configuration and
 * can differ between two evaluations in the same second.
 */
final class ConfiguredGuardSubject extends Model
{
    public $timestamps = false;

    protected $table = 'test_subjects';

    protected $guarded = [];
}

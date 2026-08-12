<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use HangTuah\GateTrail\Assignments\AssignmentScope;
use HangTuah\GateTrail\Concurrency\CoordinationInsertRace;
use HangTuah\GateTrail\Concurrency\RetryPolicy;
use HangTuah\GateTrail\Contracts\AssignmentManager;
use HangTuah\GateTrail\Contracts\PermissionManager;
use HangTuah\GateTrail\Contracts\RoleManager;
use HangTuah\GateTrail\Events\MutationCommitted;
use HangTuah\GateTrail\Exceptions\ConcurrencyRetryExhausted;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\Mutations\MutationName;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Fixtures\CoordinationRacePlanter;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Event;

/**
 * All six coordinated mutations, driven through their public managers, losing a
 * real coordination race and recovering.
 *
 * Nothing is stubbed. The planter writes the coordination row immediately before
 * each mutation's own insert, so SQLite raises the genuine duplicate-key failure
 * and the production classifier confirms it. What is asserted is what a consumer
 * would observe: one committed assignment, one audit row, one event, and never
 * the internal marker.
 */
final class AssignmentRetryIntegrationTest extends TestCase
{
    private const LOCK_TABLE = 'acl_assignment_locks';

    private TestSubject $subject;

    /**
     * @var list<MutationCommitted>
     */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);

        $this->subject = TestSubject::query()->create(['name' => 'Reviewer']);
        Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'web',
            'description' => null,
        ]);
        Permission::query()->create([
            'name' => 'articles.publish',
            'guard_name' => 'web',
            'description' => null,
        ]);

        $this->events = [];
        Event::listen(
            MutationCommitted::class,
            function (MutationCommitted $event): void {
                $this->events[] = $event;
            },
        );
    }

    public function test_assign_role_recovers_from_a_lost_race(): void
    {
        $planter = $this->plant(
            AssignmentScope::forRole(
                $this->subject->getMorphClass(),
                (int) $this->subject->getKey(),
                (int) Role::query()->sole()->getKey(),
            ),
        );

        $result = $this->assignments()->assignRole(
            $this->subject,
            'reviewer',
            SystemActor::named('test-suite'),
            'Assigned by administrator',
        );

        $this->assertRecovered(
            $planter,
            $result->outcome,
            MutationName::ROLE_ASSIGNED,
        );
        $this->assertDatabaseCount('acl_role_assignments', 1);
    }

    public function test_grant_direct_permission_recovers_from_a_lost_race(): void
    {
        $planter = $this->plant(
            AssignmentScope::forPermission(
                $this->subject->getMorphClass(),
                (int) $this->subject->getKey(),
                (int) Permission::query()->sole()->getKey(),
            ),
        );

        $result = $this->assignments()->grantDirectPermission(
            $this->subject,
            'articles.publish',
            SystemActor::named('test-suite'),
            'Granted by administrator',
        );

        $this->assertRecovered(
            $planter,
            $result->outcome,
            MutationName::PERMISSION_GRANTED_TO_SUBJECT,
        );
        $this->assertDatabaseCount('acl_permission_assignments', 1);
    }

    public function test_grant_permission_to_role_recovers_from_a_lost_race(): void
    {
        $planter = $this->plant(
            AssignmentScope::forRolePermission(
                (int) Role::query()->sole()->getKey(),
                (int) Permission::query()->sole()->getKey(),
            ),
        );

        $result = $this->assignments()->grantPermissionToRole(
            'reviewer',
            'articles.publish',
            SystemActor::named('test-suite'),
            'Granted by administrator',
        );

        $this->assertRecovered(
            $planter,
            $result->outcome,
            MutationName::PERMISSION_GRANTED_TO_ROLE,
        );
        $this->assertDatabaseCount('acl_role_permissions', 1);
    }

    public function test_the_three_revocations_take_no_coordination_row(): void
    {
        // Only the three mutations that create an assignment acquire a
        // coordination row; a revocation targets one already-identified
        // assignment row and takes no scope lock. That is pre-existing
        // behaviour, and it means the coordination insert race is not
        // reachable for them — they are still coordinated, so a classified
        // deadlock or serialization failure would still be retried, and the
        // structural proof that they call runCoordinated() with their own
        // constant lives in RetryBoundaryTest.
        $granted = [
            'role' => $this->assignments()->assignRole(
                $this->subject,
                'reviewer',
                SystemActor::named('test-suite'),
                'Assigned by administrator',
            ),
            'direct permission' => $this->assignments()->grantDirectPermission(
                $this->subject,
                'articles.publish',
                SystemActor::named('test-suite'),
                'Granted by administrator',
            ),
            'role permission' => $this->assignments()->grantPermissionToRole(
                'reviewer',
                'articles.publish',
                SystemActor::named('test-suite'),
                'Granted by administrator',
            ),
        ];

        $this->assertDatabaseCount(self::LOCK_TABLE, 3);
        $this->reset();
        $this->app['db']->table(self::LOCK_TABLE)->delete();

        $planter = new CoordinationRacePlanter(
            $this->connection(),
            self::LOCK_TABLE,
            [
                'assignment_kind' => 'role',
                'scope_hash' => str_repeat('b', 64),
                'scope_key' => 'unused',
                'created_at' => '2026-08-12 11:59:00',
                'updated_at' => '2026-08-12 11:59:00',
            ],
            times: 10,
        );
        $planter->attach();

        $revoked = [
            'role' => $this->assignments()->revokeRole(
                $granted['role']->resource,
                SystemActor::named('test-suite'),
                'Revoked by administrator',
            ),
            'direct permission' => $this->assignments()
                ->revokeDirectPermission(
                    $granted['direct permission']->resource,
                    SystemActor::named('test-suite'),
                    'Revoked by administrator',
                ),
            'role permission' => $this->assignments()
                ->revokePermissionFromRole(
                    $granted['role permission']->resource,
                    SystemActor::named('test-suite'),
                    'Revoked by administrator',
                ),
        ];

        foreach ($revoked as $label => $result) {
            $this->assertSame(
                MutationOutcome::Revoked,
                $result->outcome,
                sprintf('Revoking the [%s] should succeed.', $label),
            );
        }

        $this->assertSame(
            0,
            $planter->planted(),
            'A revocation inserts no coordination row.',
        );
        $this->assertDatabaseCount(self::LOCK_TABLE, 0);
        $this->assertCount(3, $this->events);
        $this->assertSame(
            [
                MutationName::ROLE_REVOKED,
                MutationName::PERMISSION_REVOKED_FROM_SUBJECT,
                MutationName::PERMISSION_REVOKED_FROM_ROLE,
            ],
            array_map(
                static fn (MutationCommitted $event): string => $event->mutation,
                $this->events,
            ),
        );
    }

    public function test_exhaustion_reaches_the_consumer_as_the_typed_exception(): void
    {
        config()->set(RetryPolicy::KEY, 2);
        $planter = $this->plant(
            AssignmentScope::forRole(
                $this->subject->getMorphClass(),
                (int) $this->subject->getKey(),
                (int) Role::query()->sole()->getKey(),
            ),
            times: 10,
        );

        try {
            $this->assignments()->assignRole(
                $this->subject,
                'reviewer',
                SystemActor::named('test-suite'),
                'Assigned by administrator',
            );

            $this->fail('Exhaustion should reach the consumer.');
        } catch (CoordinationInsertRace) {
            $this->fail('The internal marker must never reach a consumer.');
        } catch (ConcurrencyRetryExhausted $exhausted) {
            $this->assertSame(2, $planter->planted());
            $this->assertSame(2, $exhausted->attempts);
            $this->assertFalse($exhausted->callerOwned);
            $this->assertSame(
                MutationName::ROLE_ASSIGNED,
                $exhausted->operation,
            );
            $this->assertSame('coordination_race', $exhausted->category);
            $this->assertNull($exhausted->getPrevious());

            $this->assertDatabaseCount('acl_role_assignments', 0);
            $this->assertDatabaseCount('acl_access_audits', 0);
            $this->assertDatabaseCount(self::LOCK_TABLE, 0);
            $this->assertSame([], $this->events);
        }
    }

    public function test_the_exhaustion_message_names_only_the_operation(): void
    {
        config()->set(RetryPolicy::KEY, 1);
        $this->plant(
            AssignmentScope::forRole(
                $this->subject->getMorphClass(),
                (int) $this->subject->getKey(),
                (int) Role::query()->sole()->getKey(),
            ),
            times: 10,
        );

        try {
            $this->assignments()->assignRole(
                $this->subject,
                'reviewer',
                SystemActor::named('test-suite'),
                'Assigned by an administrator called hunter2',
                metadata: ['ticket' => 'SECRET-1234'],
            );

            $this->fail('Exhaustion should reach the consumer.');
        } catch (ConcurrencyRetryExhausted $exhausted) {
            $this->assertSame(
                'GateTrail could not complete [role.assigned] after 1 concurrent attempts.',
                $exhausted->getMessage(),
            );

            foreach ([
                'hunter2',
                'SECRET-1234',
                'acl_assignment_locks',
                'UNIQUE constraint',
                'insert into',
                'reviewer',
            ] as $leak) {
                $this->assertStringNotContainsString(
                    $leak,
                    $exhausted->getMessage(),
                );
            }
        }
    }

    public function test_definition_mutations_never_retry_and_never_coordinate(): void
    {
        // A definition mutation acquires no coordination row, so the planter
        // has nothing to plant and the mutation simply succeeds once.
        $planter = new CoordinationRacePlanter(
            $this->connection(),
            self::LOCK_TABLE,
            [
                'assignment_kind' => 'role',
                'scope_hash' => str_repeat('a', 64),
                'scope_key' => 'unused',
                'created_at' => '2026-08-12 11:59:00',
                'updated_at' => '2026-08-12 11:59:00',
            ],
            times: 10,
        );
        $planter->attach();

        $role = $this->app->make(RoleManager::class)->create(
            'auditor',
            'web',
            SystemActor::named('test-suite'),
        );
        $permission = $this->app->make(PermissionManager::class)->create(
            'articles.review',
            'web',
            SystemActor::named('test-suite'),
        );

        $this->assertSame(MutationOutcome::Created, $role->outcome);
        $this->assertSame(MutationOutcome::Created, $permission->outcome);
        $this->assertSame(
            0,
            $planter->planted(),
            'No definition mutation touches the coordination table.',
        );
        $this->assertDatabaseCount(self::LOCK_TABLE, 0);
    }

    private function assertRecovered(
        CoordinationRacePlanter $planter,
        MutationOutcome $outcome,
        string $mutation,
    ): void {
        $this->assertSame(1, $planter->planted(), 'One race was really lost.');
        $this->assertSame(MutationOutcome::Created, $outcome);

        // Exactly one audit row and one event survive the discarded attempt.
        $this->assertDatabaseCount('acl_access_audits', 1);
        $this->assertCount(1, $this->events);
        $this->assertSame($mutation, $this->events[0]->mutation);
        $this->assertDatabaseCount(self::LOCK_TABLE, 1);
    }

    private function reset(): void
    {
        $this->app['db']->table('acl_access_audits')->delete();
        $this->app['db']->table(self::LOCK_TABLE)->delete();
        $this->events = [];
    }

    private function plant(
        AssignmentScope $scope,
        int $times = 1,
    ): CoordinationRacePlanter {
        return (new CoordinationRacePlanter(
            $this->connection(),
            self::LOCK_TABLE,
            [
                'assignment_kind' => $scope->kind()->value,
                'scope_hash' => $scope->hash(),
                'scope_key' => $scope->key(),
                'created_at' => '2026-08-12 11:59:00',
                'updated_at' => '2026-08-12 11:59:00',
            ],
            $times,
        ))->attach();
    }

    private function assignments(): AssignmentManager
    {
        return $this->app->make(AssignmentManager::class);
    }

    private function connection(): Connection
    {
        $connection = $this->app['db']->connection('testing');

        $this->assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}

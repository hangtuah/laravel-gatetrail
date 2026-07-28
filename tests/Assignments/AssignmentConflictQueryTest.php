<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Assignments;

use Carbon\CarbonImmutable;
use HangTuah\GateTrail\Assignments\AssignmentConflictQuery;
use HangTuah\GateTrail\Assignments\AssignmentLockStore;
use HangTuah\GateTrail\Assignments\AssignmentScope;
use HangTuah\GateTrail\Assignments\EffectiveAssignmentPeriod;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\PermissionAssignment;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\Models\RoleAssignment;
use HangTuah\GateTrail\Models\RolePermission;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class AssignmentConflictQueryTest extends TestCase
{
    private CarbonImmutable $time;

    private Role $role;

    private Permission $permission;

    private TestSubject $subject;

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);

        $this->time = CarbonImmutable::parse('2026-07-28 12:00:00');
        $this->role = Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'web',
        ]);
        $this->permission = Permission::query()->create([
            'name' => 'documents.review',
            'guard_name' => 'web',
        ]);
        $this->subject = TestSubject::query()->create([
            'name' => 'Reviewer',
        ]);
        $this->connection = $this->app['db']->connection('testing');
    }

    public function test_overlapping_equivalent_role_assignment_is_returned(): void
    {
        $existing = $this->createRoleAssignment(
            start: $this->time,
            expiry: $this->time->addHour(),
        );
        $scope = $this->roleScope();
        $candidate = $this->period(
            $this->time->addMinutes(30),
            $this->time->addHours(2),
        );

        $conflicts = $this->find($scope, $candidate);

        $this->assertSame([$existing->getKey()], $conflicts->modelKeys());
    }

    public function test_adjacent_role_assignment_is_not_a_conflict(): void
    {
        $this->createRoleAssignment(
            start: $this->time,
            expiry: $this->time->addHour(),
        );

        $conflicts = $this->find(
            $this->roleScope(),
            $this->period(
                $this->time->addHour(),
                $this->time->addHours(2),
            ),
        );

        $this->assertTrue($conflicts->isEmpty());
    }

    public function test_revoked_assignment_does_not_block_reassignment(): void
    {
        $this->createRoleAssignment(
            start: $this->time,
            expiry: $this->time->addHour(),
            revokedAt: $this->time->addMinutes(15),
        );

        $conflicts = $this->find(
            $this->roleScope(),
            $this->period(
                $this->time->addMinutes(30),
                $this->time->addHours(2),
            ),
        );

        $this->assertTrue($conflicts->isEmpty());
    }

    public function test_expired_history_blocks_an_overlapping_past_period(): void
    {
        $existing = $this->createRoleAssignment(
            start: $this->time->subHours(3),
            expiry: $this->time->subHours(2),
        );

        $conflicts = $this->find(
            $this->roleScope(),
            $this->period(
                $this->time->subMinutes(150),
                $this->time->subMinutes(90),
            ),
        );

        $this->assertSame([$existing->getKey()], $conflicts->modelKeys());
    }

    public function test_non_equivalent_scope_is_not_a_conflict(): void
    {
        $otherRole = Role::query()->create([
            'name' => 'approver',
            'guard_name' => 'web',
        ]);
        $this->createRoleAssignment(
            start: $this->time,
            expiry: null,
            role: $otherRole,
        );

        $conflicts = $this->find(
            $this->roleScope(),
            $this->period($this->time, null),
        );

        $this->assertTrue($conflicts->isEmpty());
    }

    public function test_open_ended_period_conflicts_with_a_later_period(): void
    {
        $existing = $this->createRoleAssignment(
            start: $this->time,
            expiry: null,
        );

        $conflicts = $this->find(
            $this->roleScope(),
            $this->period($this->time->addYear(), null),
        );

        $this->assertSame([$existing->getKey()], $conflicts->modelKeys());
    }

    public function test_permission_and_role_permission_scopes_are_supported(): void
    {
        $permissionAssignment = PermissionAssignment::query()->create([
            'subject_type' => $this->subject->getMorphClass(),
            'subject_id' => $this->subject->getKey(),
            'permission_id' => $this->permission->getKey(),
            'assigned_at' => $this->time,
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'test-suite',
        ]);
        $rolePermission = RolePermission::query()->create([
            'role_id' => $this->role->getKey(),
            'permission_id' => $this->permission->getKey(),
            'assigned_at' => $this->time,
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'test-suite',
        ]);
        $period = $this->period($this->time, null);

        $permissionConflicts = $this->find(
            AssignmentScope::forPermission(
                $this->subject->getMorphClass(),
                $this->subject->getKey(),
                $this->permission->getKey(),
            ),
            $period,
        );
        $rolePermissionConflicts = $this->find(
            AssignmentScope::forRolePermission(
                $this->role->getKey(),
                $this->permission->getKey(),
            ),
            $period,
        );

        $this->assertSame(
            [$permissionAssignment->getKey()],
            $permissionConflicts->modelKeys(),
        );
        $this->assertSame(
            [$rolePermission->getKey()],
            $rolePermissionConflicts->modelKeys(),
        );
    }

    public function test_query_requires_an_existing_transaction(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('inside a database transaction');

        app(AssignmentConflictQuery::class)->find(
            $this->roleScope(),
            $this->period($this->time, null),
        );
    }

    /**
     * @return Collection<int, Model>
     */
    private function find(
        AssignmentScope $scope,
        EffectiveAssignmentPeriod $period,
    ): Collection {
        return $this->connection->transaction(
            function () use ($scope, $period) {
                app(AssignmentLockStore::class)->acquire(
                    $scope,
                    $this->time,
                );

                return app(AssignmentConflictQuery::class)->find(
                    $scope,
                    $period,
                );
            },
        );
    }

    private function roleScope(): AssignmentScope
    {
        return AssignmentScope::forRole(
            $this->subject->getMorphClass(),
            $this->subject->getKey(),
            $this->role->getKey(),
        );
    }

    private function period(
        CarbonImmutable $start,
        ?CarbonImmutable $expiry,
    ): EffectiveAssignmentPeriod {
        return EffectiveAssignmentPeriod::fromStored(
            $start,
            null,
            $expiry,
        );
    }

    private function createRoleAssignment(
        CarbonImmutable $start,
        ?CarbonImmutable $expiry,
        ?CarbonImmutable $revokedAt = null,
        ?Role $role = null,
    ): RoleAssignment {
        return RoleAssignment::query()->create([
            'subject_type' => $this->subject->getMorphClass(),
            'subject_id' => $this->subject->getKey(),
            'role_id' => ($role ?? $this->role)->getKey(),
            'assigned_at' => $start,
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'test-suite',
            'expires_at' => $expiry,
            'revoked_at' => $revokedAt,
            'revoked_by_type' => $revokedAt === null
                ? null
                : 'gatetrail.system',
            'revoked_by_id' => $revokedAt === null
                ? null
                : 'test-suite',
            'revocation_reason' => $revokedAt === null
                ? null
                : 'Conflict query test',
        ]);
    }
}

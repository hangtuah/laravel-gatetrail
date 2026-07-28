<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Assignments;

use Carbon\CarbonImmutable;
use HangTuah\GateTrail\Assignments\AssignmentLockStore;
use HangTuah\GateTrail\Assignments\AssignmentScope;
use HangTuah\GateTrail\Exceptions\AssignmentScopeCollision;
use HangTuah\GateTrail\Tests\TestCase;
use LogicException;

final class AssignmentLockStoreTest extends TestCase
{
    public function test_acquire_creates_one_persistent_coordination_row(): void
    {
        $this->migrate();
        $scope = AssignmentScope::forRole('user', 42, 7);
        $timestamp = CarbonImmutable::parse('2026-07-28 12:00:00');
        $store = app(AssignmentLockStore::class);
        $connection = $this->app['db']->connection('testing');

        $connection->transaction(
            static fn () => $store->acquire($scope, $timestamp),
        );
        $connection->transaction(
            static fn () => $store->acquire($scope, $timestamp->addMinute()),
        );

        $this->assertDatabaseCount('acl_assignment_locks', 1);
        $this->assertDatabaseHas('acl_assignment_locks', [
            'assignment_kind' => 'role',
            'scope_hash' => $scope->hash(),
            'scope_key' => $scope->key(),
            'created_at' => '2026-07-28 12:00:00',
            'updated_at' => '2026-07-28 12:00:00',
        ]);
    }

    public function test_acquire_uses_the_configured_table_context(): void
    {
        config()->set('gatetrail.database.prefix', 'x_');
        $this->migrate();
        $scope = AssignmentScope::forPermission('user', 42, 9);
        $timestamp = CarbonImmutable::parse('2026-07-28 12:00:00');
        $store = app(AssignmentLockStore::class);

        $this->app['db']->connection('testing')->transaction(
            static fn () => $store->acquire($scope, $timestamp),
        );

        $this->assertDatabaseHas('x_acl_assignment_locks', [
            'assignment_kind' => 'permission',
            'scope_hash' => $scope->hash(),
        ]);
    }

    public function test_scope_key_mismatch_fails_closed(): void
    {
        $this->migrate();
        $scope = AssignmentScope::forRolePermission(7, 9);

        $this->app['db']->table('acl_assignment_locks')->insert([
            'assignment_kind' => $scope->kind()->value,
            'scope_hash' => $scope->hash(),
            'scope_key' => 'corrupted-key',
            'created_at' => '2026-07-28 12:00:00',
            'updated_at' => '2026-07-28 12:00:00',
        ]);

        $this->expectException(AssignmentScopeCollision::class);
        $this->expectExceptionMessage('scope collision detected');

        $this->app['db']->connection('testing')->transaction(
            static fn () => app(AssignmentLockStore::class)->acquire(
                $scope,
                CarbonImmutable::parse('2026-07-28 12:01:00'),
            ),
        );
    }

    public function test_acquire_requires_an_existing_transaction(): void
    {
        $this->migrate();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('inside a database transaction');

        app(AssignmentLockStore::class)->acquire(
            AssignmentScope::forRole('user', 42, 7),
            CarbonImmutable::parse('2026-07-28 12:00:00'),
        );
    }

    private function migrate(): void
    {
        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);
    }
}

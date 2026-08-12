<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Assignments;

use HangTuah\GateTrail\Assignments\AssignmentScope;
use HangTuah\GateTrail\Contracts\AssignmentManager;
use HangTuah\GateTrail\Events\MutationCommitted;
use HangTuah\GateTrail\Exceptions\AssignmentNotActive;
use HangTuah\GateTrail\Exceptions\AssignmentScopeCollision;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Fixtures\ForcedRollback;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Event;

/**
 * ADR 0001 coordinates every managed assignment *revocation* as well as every
 * creation. This file proves the three revocations do that, in the one order
 * that cannot deadlock against the creation path.
 */
final class RevocationCoordinationTest extends TestCase
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

    public function test_each_revocation_acquires_its_creation_scope(): void
    {
        $cases = [
            'role' => [
                fn () => $this->assignments()->assignRole(
                    $this->subject,
                    'reviewer',
                    SystemActor::named('test-suite'),
                    'Assigned by administrator',
                ),
                fn (object $resource) => $this->assignments()->revokeRole(
                    $resource,
                    SystemActor::named('test-suite'),
                    'Revoked by administrator',
                ),
                fn (): AssignmentScope => AssignmentScope::forRole(
                    $this->subject->getMorphClass(),
                    (int) $this->subject->getKey(),
                    (int) Role::query()->sole()->getKey(),
                ),
            ],
            'direct permission' => [
                fn () => $this->assignments()->grantDirectPermission(
                    $this->subject,
                    'articles.publish',
                    SystemActor::named('test-suite'),
                    'Granted by administrator',
                ),
                fn (object $resource) => $this->assignments()
                    ->revokeDirectPermission(
                        $resource,
                        SystemActor::named('test-suite'),
                        'Revoked by administrator',
                    ),
                fn (): AssignmentScope => AssignmentScope::forPermission(
                    $this->subject->getMorphClass(),
                    (int) $this->subject->getKey(),
                    (int) Permission::query()->sole()->getKey(),
                ),
            ],
            'role permission' => [
                fn () => $this->assignments()->grantPermissionToRole(
                    'reviewer',
                    'articles.publish',
                    SystemActor::named('test-suite'),
                    'Granted by administrator',
                ),
                fn (object $resource) => $this->assignments()
                    ->revokePermissionFromRole(
                        $resource,
                        SystemActor::named('test-suite'),
                        'Revoked by administrator',
                    ),
                fn (): AssignmentScope => AssignmentScope::forRolePermission(
                    (int) Role::query()->sole()->getKey(),
                    (int) Permission::query()->sole()->getKey(),
                ),
            ],
        ];

        foreach ($cases as $label => [$create, $revoke, $expected]) {
            $granted = $create();
            $this->app['db']->table(self::LOCK_TABLE)->delete();

            $result = $revoke($granted->resource);
            $scope = $expected();

            $this->assertSame(MutationOutcome::Revoked, $result->outcome, $label);
            $this->assertSame(
                1,
                $this->lockRows($scope),
                sprintf('The [%s] revocation must coordinate.', $label),
            );
            $this->assertSame(
                1,
                (int) $this->app['db']->table(self::LOCK_TABLE)->count(),
                sprintf('The [%s] revocation coordinates once.', $label),
            );
        }
    }

    public function test_coordination_is_acquired_before_the_locked_reload(): void
    {
        $granted = $this->assignments()->assignRole(
            $this->subject,
            'reviewer',
            SystemActor::named('test-suite'),
            'Assigned by administrator',
        );
        $this->app['db']->table(self::LOCK_TABLE)->delete();

        $statements = $this->recordStatements();

        $this->assignments()->revokeRole(
            $granted->resource,
            SystemActor::named('test-suite'),
            'Revoked by administrator',
        );

        $probe = $this->firstIndex(
            $statements(),
            static fn (string $sql): bool => str_starts_with($sql, 'select')
                && str_contains($sql, 'acl_role_assignments')
                && str_contains($sql, '"subject_type"')
                && ! str_contains($sql, 'select *'),
        );
        $coordination = $this->firstIndex(
            $statements(),
            static fn (string $sql): bool => str_contains(
                $sql,
                self::LOCK_TABLE,
            ),
        );
        $lockedReload = $this->firstIndex(
            $statements(),
            static fn (string $sql): bool => str_starts_with($sql, 'select *')
                && str_contains($sql, 'acl_role_assignments'),
        );

        $this->assertNotNull($probe, 'The unlocked identity probe must run.');
        $this->assertNotNull($coordination);
        $this->assertNotNull($lockedReload);

        // The exact order ADR 0001 needs: identity probe without a lock, then
        // the coordination row, then the assignment row under its lock. Any
        // other order lets a revocation and a creation on one scope hold each
        // other's next lock.
        $this->assertLessThan($coordination, $probe);
        $this->assertLessThan($lockedReload, $coordination);
    }

    public function test_a_tampered_model_cannot_change_the_coordination_scope(): void
    {
        $granted = $this->assignments()->assignRole(
            $this->subject,
            'reviewer',
            SystemActor::named('test-suite'),
            'Assigned by administrator',
        );
        $this->app['db']->table(self::LOCK_TABLE)->delete();

        $decoy = Role::query()->create([
            'name' => 'decoy',
            'guard_name' => 'web',
            'description' => null,
        ]);
        $tampered = $granted->resource;
        // A consumer hands back a model whose scope columns have been edited
        // in memory. Only the identifier may be believed.
        $tampered->forceFill([
            'subject_type' => 'tampered\\Subject',
            'subject_id' => 999999,
            'role_id' => $decoy->getKey(),
        ]);

        $this->assignments()->revokeRole(
            $tampered,
            SystemActor::named('test-suite'),
            'Revoked by administrator',
        );

        $persisted = AssignmentScope::forRole(
            $this->subject->getMorphClass(),
            (int) $this->subject->getKey(),
            (int) Role::query()->where('name', 'reviewer')->sole()->getKey(),
        );
        $tamperedScope = AssignmentScope::forRole(
            'tampered\\Subject',
            999999,
            (int) $decoy->getKey(),
        );

        $this->assertSame(1, $this->lockRows($persisted));
        $this->assertSame(0, $this->lockRows($tamperedScope));
        $this->assertSame(
            1,
            (int) $this->app['db']->table(self::LOCK_TABLE)->count(),
        );
    }

    public function test_a_scope_that_moves_between_probe_and_lock_fails_closed(): void
    {
        $granted = $this->assignments()->assignRole(
            $this->subject,
            'reviewer',
            SystemActor::named('test-suite'),
            'Assigned by administrator',
        );
        $this->app['db']->table(self::LOCK_TABLE)->delete();
        $this->events = [];

        $decoy = Role::query()->create([
            'name' => 'decoy',
            'guard_name' => 'web',
            'description' => null,
        ]);
        $connection = $this->connection();
        $moved = false;

        // Between the unlocked probe and the locked reload, the assignment's
        // identity changes underneath. GateTrail never does this, so it means
        // the row was rewritten outside the package.
        $connection->beforeExecuting(
            function (string $sql) use (
                $connection,
                $decoy,
                $granted,
                &$moved,
            ): void {
                if ($moved || ! str_contains($sql, self::LOCK_TABLE)) {
                    return;
                }

                $moved = true;
                $connection->table('acl_role_assignments')
                    ->where('id', $granted->resource->getKey())
                    ->update(['role_id' => $decoy->getKey()]);
            },
        );

        try {
            $this->assignments()->revokeRole(
                $granted->resource,
                SystemActor::named('test-suite'),
                'Revoked by administrator',
            );

            $this->fail('A moved scope must fail closed.');
        } catch (AssignmentScopeCollision $collision) {
            $this->assertTrue($moved);
            $this->assertStringContainsString(
                'scope collision detected',
                $collision->getMessage(),
            );

            // Only the hashed scope appears; no subject type, role name,
            // permission name or caller reason.
            $probed = AssignmentScope::forRole(
                $this->subject->getMorphClass(),
                (int) $this->subject->getKey(),
                (int) Role::query()->where('name', 'reviewer')->sole()->getKey(),
            );

            $this->assertSame(
                sprintf(
                    'GateTrail assignment scope collision detected for [role:%s].',
                    $probed->hash(),
                ),
                $collision->getMessage(),
            );

            foreach ([
                'reviewer',
                'decoy',
                'Revoked by administrator',
                $this->subject->getMorphClass(),
            ] as $leak) {
                $this->assertStringNotContainsString(
                    $leak,
                    $collision->getMessage(),
                );
            }

            // Fail closed: nothing revoked, nothing audited, nothing announced.
            $this->assertNull(
                $this->app['db']->table('acl_role_assignments')
                    ->where('id', $granted->resource->getKey())
                    ->value('revoked_at'),
            );
            $this->assertSame([], $this->events);
            $this->assertSame(
                0,
                (int) $this->app['db']->table(self::LOCK_TABLE)->count(),
                'The rolled-back attempt leaves no coordination row.',
            );
        }
    }

    public function test_an_already_revoked_assignment_still_fails_the_same_way(): void
    {
        $granted = $this->assignments()->assignRole(
            $this->subject,
            'reviewer',
            SystemActor::named('test-suite'),
            'Assigned by administrator',
        );
        $this->assignments()->revokeRole(
            $granted->resource,
            SystemActor::named('test-suite'),
            'Revoked by administrator',
        );
        $this->app['db']->table(self::LOCK_TABLE)->delete();
        $audits = (int) $this->app['db']->table('acl_access_audits')->count();

        try {
            $this->assignments()->revokeRole(
                $granted->resource,
                SystemActor::named('test-suite'),
                'Revoked again',
            );

            $this->fail('A revoked assignment is not active.');
        } catch (AssignmentNotActive) {
            $this->assertSame(
                $audits,
                (int) $this->app['db']->table('acl_access_audits')->count(),
            );
            // The attempt rolled back, so its coordination row went with it.
            $this->assertSame(
                0,
                (int) $this->app['db']->table(self::LOCK_TABLE)->count(),
            );
        }
    }

    public function test_a_missing_assignment_never_coordinates(): void
    {
        try {
            $this->assignments()->revokeRole(
                999999,
                SystemActor::named('test-suite'),
                'Revoked by administrator',
            );

            $this->fail('A missing assignment is not active.');
        } catch (AssignmentNotActive) {
            $this->assertSame(
                0,
                (int) $this->app['db']->table(self::LOCK_TABLE)->count(),
                'An unidentifiable assignment takes no coordination row.',
            );
        }
    }

    public function test_a_malformed_identifier_never_reaches_a_binding(): void
    {
        foreach (['   ', '1e3', '0x10', '-1', '1.0', 'abc'] as $malformed) {
            try {
                $this->assignments()->revokeRole(
                    $malformed,
                    SystemActor::named('test-suite'),
                    'Revoked by administrator',
                );

                $this->fail("[$malformed] should be rejected.");
            } catch (AssignmentNotActive $exception) {
                // The reference is hashed, so the raw input never appears.
                $this->assertStringNotContainsString(
                    $malformed,
                    $exception->getMessage(),
                );
            }
        }

        $this->assertSame(
            0,
            (int) $this->app['db']->table(self::LOCK_TABLE)->count(),
        );
    }

    public function test_a_caller_rollback_leaves_assignment_and_audit_unchanged(): void
    {
        $granted = $this->assignments()->assignRole(
            $this->subject,
            'reviewer',
            SystemActor::named('test-suite'),
            'Assigned by administrator',
        );
        $this->app['db']->table(self::LOCK_TABLE)->delete();
        $this->app['db']->table('acl_access_audits')->delete();
        $this->events = [];

        try {
            $this->connection()->transaction(function () use ($granted): void {
                $this->assignments()->revokeRole(
                    $granted->resource,
                    SystemActor::named('test-suite'),
                    'Revoked by administrator',
                );

                throw new ForcedRollback('Roll back outer transaction.');
            });

            $this->fail('The outer transaction should roll back.');
        } catch (ForcedRollback) {
            $this->assertNull(
                $this->app['db']->table('acl_role_assignments')
                    ->where('id', $granted->resource->getKey())
                    ->value('revoked_at'),
            );
            $this->assertSame(
                0,
                (int) $this->app['db']->table('acl_access_audits')->count(),
            );
            $this->assertSame(
                0,
                (int) $this->app['db']->table(self::LOCK_TABLE)->count(),
            );
            $this->assertSame([], $this->events);
        }
    }

    private function lockRows(AssignmentScope $scope): int
    {
        return (int) $this->app['db']->table(self::LOCK_TABLE)
            ->where('assignment_kind', $scope->kind()->value)
            ->where('scope_hash', $scope->hash())
            ->where('scope_key', $scope->key())
            ->count();
    }

    /**
     * @param  list<string>  $statements
     * @param  callable(string): bool  $matches
     */
    private function firstIndex(array $statements, callable $matches): ?int
    {
        foreach ($statements as $index => $sql) {
            if ($matches($sql)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @return callable(): list<string>
     */
    private function recordStatements(): callable
    {
        $statements = [];

        $this->connection()->beforeExecuting(
            static function (string $sql) use (&$statements): void {
                $statements[] = $sql;
            },
        );

        return static function () use (&$statements): array {
            return $statements;
        };
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

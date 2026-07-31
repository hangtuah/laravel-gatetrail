<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use DateTimeImmutable;
use HangTuah\GateTrail\Audit\AuditFailureReporter;
use HangTuah\GateTrail\Contracts\PermissionManager;
use HangTuah\GateTrail\Contracts\RoleManager;
use HangTuah\GateTrail\Events\AuditPersistenceFailed;
use HangTuah\GateTrail\Models\AccessAudit;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\PermissionChanges;
use HangTuah\GateTrail\RoleChanges;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Concerns\ExercisesEveryMutation;
use HangTuah\GateTrail\Tests\Fixtures\ForcedRollback;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use HangTuah\GateTrail\Tests\Fixtures\RecordingLogger;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Depends;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Proves every managed mutation behaves identically under strict and enabled
 * non-strict auditing, and that a non-strict audit failure never invalidates
 * an already committed mutation.
 */
final class NonStrictAuditModeTest extends TestCase
{
    use ExercisesEveryMutation;

    /**
     * @var list<AuditPersistenceFailed>
     */
    private array $failures = [];

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);
        $this->app->instance(
            ClockInterface::class,
            new FrozenClock(
                new DateTimeImmutable('2026-07-30 08:00:00 UTC'),
            ),
        );

        $this->failures = [];
        $this->logger = new RecordingLogger;
        $this->app->instance(LoggerInterface::class, $this->logger);

        Event::listen(
            AuditPersistenceFailed::class,
            function (AuditPersistenceFailed $event): void {
                $this->failures[] = $event;
            },
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function test_strict_mode_audits_every_managed_mutation(): array
    {
        $results = $this->everyMutation();
        $audits = $this->audits();

        $this->assertSame(
            self::EVENTS,
            array_column($audits, 'event'),
        );
        $this->assertCount(count(self::EVENTS), $results);

        foreach ($results as $event => $result) {
            $this->assertNotNull(
                $result->auditId,
                "Strict [$event] should return an audit identifier.",
            );
        }

        return $audits;
    }

    /**
     * @param  list<array<string, mixed>>  $strict
     */
    #[Depends('test_strict_mode_audits_every_managed_mutation')]
    public function test_non_strict_mode_audits_every_managed_mutation(
        array $strict,
    ): void {
        config()->set('gatetrail.audit.strict', false);

        $results = $this->everyMutation();
        $audits = $this->audits();

        $this->assertSame(
            self::EVENTS,
            array_column($audits, 'event'),
        );

        foreach ($results as $event => $result) {
            $this->assertNotNull(
                $result->auditId,
                "Non-strict [$event] should return an audit identifier.",
            );
        }

        // Each run starts from an identical empty schema under a frozen
        // clock, so every canonical audit column must match byte for byte.
        // Only the generated audit identifier may differ, and it is stripped.
        $this->assertSame($strict, $audits);

        $this->assertSame([], $this->failures);
        $this->assertSame([], $this->logger->errors());
    }

    public function test_non_strict_failure_preserves_every_mutation_kind(): void
    {
        config()->set('gatetrail.audit.strict', false);
        config()->set(
            'gatetrail.models.access_audit',
            UnwritableAccessAudit::class,
        );

        $results = $this->everyMutation();

        $this->assertCount(count(self::EVENTS), $results);

        foreach ($results as $event => $result) {
            $this->assertNull(
                $result->auditId,
                "A failed [$event] audit must report no identifier.",
            );
            $this->assertTrue($result->changed());
        }

        // Every mutation stayed committed even though no audit exists.
        $this->assertSame(
            1,
            Role::query()->withTrashed()->count(),
        );
        $this->assertSame(
            1,
            Permission::query()->withTrashed()->count(),
        );
        $this->assertDatabaseCount('acl_role_assignments', 1);
        $this->assertDatabaseCount('acl_permission_assignments', 1);
        $this->assertDatabaseCount('acl_role_permissions', 1);
        $this->assertNull(Role::query()->sole()->getAttribute('deleted_at'));
        $this->assertNull(
            Permission::query()->sole()->getAttribute('deleted_at'),
        );

        $this->assertCount(count(self::EVENTS), $this->failures);
        $this->assertSame(
            self::EVENTS,
            array_map(
                static fn (AuditPersistenceFailed $failure): string => $failure
                    ->event,
                $this->failures,
            ),
        );

        $errors = $this->logger->errors();

        $this->assertCount(count(self::EVENTS), $errors);
        $this->assertSame(
            AuditFailureReporter::MESSAGE,
            $errors[0]['message'],
        );
    }

    public function test_non_strict_audits_wait_for_a_caller_owned_commit(): void
    {
        config()->set('gatetrail.audit.strict', false);
        $connection = $this->app['db']->connection('testing');

        $connection->transaction(function (): void {
            $results = $this->everyMutation();

            foreach ($results as $event => $result) {
                $this->assertNull(
                    $result->auditId,
                    "A pending [$event] audit must report no identifier.",
                );
            }

            $this->assertDatabaseCount('acl_access_audits', 0);
        });

        $this->assertSame(
            self::EVENTS,
            array_column($this->audits(), 'event'),
        );
        $this->assertSame([], $this->failures);
    }

    public function test_a_caller_rollback_discards_every_pending_audit(): void
    {
        config()->set('gatetrail.audit.strict', false);
        $connection = $this->app['db']->connection('testing');

        try {
            $connection->transaction(function (): void {
                $this->everyMutation();

                throw new ForcedRollback('Roll back outer transaction.');
            });

            $this->fail('The outer transaction should roll back.');
        } catch (ForcedRollback) {
            $this->assertDatabaseCount('acl_access_audits', 0);
            $this->assertDatabaseCount('acl_roles', 0);
            $this->assertDatabaseCount('acl_permissions', 0);
            $this->assertDatabaseCount('acl_role_assignments', 0);
            $this->assertSame([], $this->failures);
            $this->assertSame([], $this->logger->errors());
        }
    }

    public function test_an_unchanged_mutation_writes_nothing_in_non_strict_mode(): void
    {
        config()->set('gatetrail.audit.strict', false);
        $actor = SystemActor::named('audit-suite');
        $roles = app(RoleManager::class);
        $permissions = app(PermissionManager::class);
        $role = $roles->create(
            'reviewer',
            'web',
            $actor,
            description: 'Reviews documents.',
        )->resource;
        $permission = $permissions->create(
            'documents.approve',
            'web',
            $actor,
            description: 'Approve documents.',
        )->resource;
        $before = $this->audits();

        $unchangedRole = $roles->update(
            $role,
            RoleChanges::description('Reviews documents.'),
            $actor,
        );
        $unchangedPermission = $permissions->update(
            $permission,
            PermissionChanges::description('Approve documents.'),
            $actor,
        );

        $this->assertSame(
            MutationOutcome::Unchanged,
            $unchangedRole->outcome,
        );
        $this->assertSame(
            MutationOutcome::Unchanged,
            $unchangedPermission->outcome,
        );
        $this->assertNull($unchangedRole->auditId);
        $this->assertNull($unchangedPermission->auditId);
        $this->assertSame($before, $this->audits());
        $this->assertSame([], $this->failures);
        $this->assertSame([], $this->logger->errors());
    }

    public function test_configured_custom_models_work_in_non_strict_mode(): void
    {
        config()->set('gatetrail.audit.strict', false);
        config()->set('gatetrail.models.role', NonStrictCustomRole::class);
        config()->set(
            'gatetrail.models.permission',
            NonStrictCustomPermission::class,
        );

        $actor = SystemActor::named('audit-suite');
        $role = app(RoleManager::class)->create('reviewer', 'web', $actor);
        $permission = app(PermissionManager::class)->create(
            'documents.approve',
            'web',
            $actor,
        );

        $this->assertInstanceOf(NonStrictCustomRole::class, $role->resource);
        $this->assertInstanceOf(
            NonStrictCustomPermission::class,
            $permission->resource,
        );
        $this->assertNotNull($role->auditId);
        $this->assertNotNull($permission->auditId);
        $this->assertSame(
            ['role.created', 'permission.created'],
            array_column($this->audits(), 'event'),
        );
    }

    /**
     * Every persisted audit row without its generated identifier.
     *
     * @return list<array<string, mixed>>
     */
    private function audits(): array
    {
        return array_map(
            static function (object $row): array {
                $columns = (array) $row;
                unset($columns['id']);

                return $columns;
            },
            $this->app['db']
                ->table('acl_access_audits')
                ->orderBy('id')
                ->get()
                ->all(),
        );
    }
}

final class UnwritableAccessAudit extends AccessAudit
{
    public function getTable(): string
    {
        return 'unwritable_access_audits';
    }
}

final class NonStrictCustomRole extends Role {}

final class NonStrictCustomPermission extends Permission {}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use DateTimeImmutable;
use HangTuah\GateTrail\Contracts\GateTrailEvent;
use HangTuah\GateTrail\Contracts\RoleManager;
use HangTuah\GateTrail\Events\AuditPersistenceFailed;
use HangTuah\GateTrail\Events\MutationCommitted;
use HangTuah\GateTrail\Events\MutationEventDispatcher;
use HangTuah\GateTrail\Exceptions\AuditWriteFailed;
use HangTuah\GateTrail\Exceptions\UnsupportedAfterCommitBoundary;
use HangTuah\GateTrail\Models\AccessAudit;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\MutationResult;
use HangTuah\GateTrail\RoleChanges;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Concerns\ExercisesEveryMutation;
use HangTuah\GateTrail\Tests\Fixtures\ForcedRollback;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use HangTuah\GateTrail\Tests\Fixtures\RecordingLogger;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Proves the accepted after-commit mutation event contract end to end.
 *
 * @see docs/decisions/0010-after-commit-mutation-events.md
 */
final class MutationCommittedDispatchTest extends TestCase
{
    use ExercisesEveryMutation;

    /**
     * The mutations that carry a subject pair. Every other event describes a
     * definition and has none.
     */
    private const SUBJECT_SCOPED = [
        'role.assigned',
        'role.revoked',
        'permission.granted_to_subject',
        'permission.revoked_from_subject',
    ];

    /**
     * @var list<MutationCommitted>
     */
    private array $events = [];

    /**
     * @var list<string>
     */
    private array $sequence = [];

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

        $this->events = [];
        $this->sequence = [];
        $this->logger = new RecordingLogger;
        $this->app->instance(LoggerInterface::class, $this->logger);

        Event::listen(
            MutationCommitted::class,
            function (MutationCommitted $event): void {
                $this->events[] = $event;
                $this->sequence[] = 'committed:'.$event->mutation;
            },
        );
        Event::listen(
            AuditPersistenceFailed::class,
            function (AuditPersistenceFailed $event): void {
                $this->sequence[] = 'audit-failed:'.$event->event;
            },
        );
    }

    public function test_every_managed_mutation_dispatches_exactly_one_event(): void
    {
        $results = $this->everyMutation();

        $this->assertSame(
            self::EVENTS,
            array_map(
                static fn (MutationCommitted $event): string => $event->mutation,
                $this->events,
            ),
            'Each of the fourteen handlers dispatches once, in order.',
        );

        foreach ($this->events as $event) {
            $result = $results[$event->mutation];

            $this->assertSame($result->outcome, $event->outcome);
            $this->assertNotSame(MutationOutcome::Unchanged, $event->outcome);
            $this->assertSame(
                (string) $result->resource->getKey(),
                (string) $event->targetId,
            );
            $this->assertSame($result->auditId, $event->auditId);
            $this->assertSame('gatetrail.system', $event->actorType);
            $this->assertSame('audit-suite', $event->actorId);
            $this->assertSame(
                '2026-07-30T08:00:00.000000Z',
                $event->context()['occurred_at'],
            );
            $this->assertNull($event->requestId);

            if (in_array($event->mutation, self::SUBJECT_SCOPED, true)) {
                $this->assertNotNull($event->subjectType);
                $this->assertNotNull($event->subjectId);
            } else {
                $this->assertNull($event->subjectType);
                $this->assertNull($event->subjectId);
            }
        }
    }

    public function test_event_target_types_match_the_audit_vocabulary(): void
    {
        $this->everyMutation();
        $audits = $this->app['db']
            ->table('acl_access_audits')
            ->orderBy('id')
            ->get()
            ->all();

        $this->assertCount(count($this->events), $audits);

        foreach ($this->events as $index => $event) {
            $audit = $audits[$index];

            $this->assertSame($audit->event, $event->mutation);
            $this->assertSame($audit->target_type, $event->targetType);
            $this->assertSame(
                (string) $audit->target_id,
                (string) $event->targetId,
            );
            $this->assertSame($audit->subject_type, $event->subjectType);
            $this->assertSame($audit->actor_type, $event->actorType);
            $this->assertSame($audit->actor_id, $event->actorId);
            $this->assertSame((string) $audit->id, (string) $event->auditId);
        }
    }

    public function test_a_listener_registered_for_the_marker_interface_receives_events(): void
    {
        $seen = [];
        Event::listen(
            GateTrailEvent::class,
            static function (GateTrailEvent $event) use (&$seen): void {
                $seen[] = $event::class;
            },
        );

        $this->create();

        $this->assertSame([MutationCommitted::class], $seen);
    }

    public function test_dispatching_with_no_listeners_succeeds(): void
    {
        Event::forget(MutationCommitted::class);
        Event::forget(AuditPersistenceFailed::class);

        $result = $this->create();

        $this->assertSame(MutationOutcome::Created, $result->outcome);
        $this->assertNotNull($result->auditId);
        $this->assertSame([], $this->logger->records);
    }

    public function test_strict_owned_dispatches_with_the_audit_identifier(): void
    {
        $result = $this->create();

        $this->assertCount(1, $this->events);
        $this->assertSame($result->auditId, $this->events[0]->auditId);
        $this->assertNotNull($this->events[0]->auditId);
    }

    public function test_strict_caller_owned_dispatches_after_the_outer_commit(): void
    {
        $this->app['db']->connection('testing')->transaction(function (): void {
            $result = $this->create();

            $this->assertNotNull($result->auditId);
            $this->assertSame([], $this->events);
        });

        $this->assertCount(1, $this->events);
        $this->assertNotNull($this->events[0]->auditId);
    }

    public function test_strict_audit_failure_dispatches_nothing(): void
    {
        $this->breakAuditStorage();

        try {
            $this->create();

            $this->fail('Strict auditing should throw.');
        } catch (AuditWriteFailed) {
            $this->assertSame([], $this->events);
            $this->assertDatabaseCount('acl_roles', 0);
        }
    }

    public function test_non_strict_owned_dispatches_with_the_audit_identifier(): void
    {
        config()->set('gatetrail.audit.strict', false);

        $result = $this->create();

        $this->assertCount(1, $this->events);
        $this->assertSame($result->auditId, $this->events[0]->auditId);
        $this->assertNotNull($this->events[0]->auditId);
    }

    public function test_non_strict_owned_audit_failure_dispatches_after_the_failure(): void
    {
        config()->set('gatetrail.audit.strict', false);
        $this->breakAuditStorage();

        $result = $this->create();

        $this->assertNull($result->auditId);
        $this->assertCount(1, $this->events);
        $this->assertNull($this->events[0]->auditId);
        $this->assertDatabaseCount('acl_roles', 1);

        // The operational failure is reported before the domain event.
        $this->assertSame(
            ['audit-failed:role.created', 'committed:role.created'],
            $this->sequence,
        );
    }

    public function test_non_strict_caller_owned_carries_the_deferred_audit_id(): void
    {
        config()->set('gatetrail.audit.strict', false);

        $this->app['db']->connection('testing')->transaction(function (): void {
            $result = $this->create();

            // The manager returns before the outer commit, so it cannot know
            // the identifier the event will carry.
            $this->assertNull($result->auditId);
            $this->assertSame([], $this->events);
            $this->assertDatabaseCount('acl_access_audits', 0);
        });

        $audit = $this->app['db']->table('acl_access_audits')->sole();

        $this->assertCount(1, $this->events);
        $this->assertSame(
            (string) $audit->id,
            (string) $this->events[0]->auditId,
        );
    }

    public function test_non_strict_caller_owned_audit_failure_dispatches_with_null(): void
    {
        config()->set('gatetrail.audit.strict', false);
        $this->breakAuditStorage();

        $this->app['db']->connection('testing')->transaction(function (): void {
            $this->assertNull($this->create()->auditId);
        });

        $this->assertCount(1, $this->events);
        $this->assertNull($this->events[0]->auditId);
        $this->assertSame(
            ['audit-failed:role.created', 'committed:role.created'],
            $this->sequence,
        );
    }

    public function test_disabled_owned_and_caller_owned_dispatch_once_with_null(): void
    {
        config()->set('gatetrail.audit.enabled', false);

        $owned = $this->create();

        $this->assertCount(1, $this->events);
        $this->assertNull($this->events[0]->auditId);
        $this->assertNull($owned->auditId);

        Role::query()->withTrashed()->forceDelete();

        $this->app['db']->connection('testing')->transaction(function (): void {
            $this->create();

            $this->assertCount(1, $this->events);
        });

        $this->assertCount(2, $this->events);
        $this->assertNull($this->events[1]->auditId);
        $this->assertDatabaseCount('acl_access_audits', 0);
    }

    public function test_an_outer_rollback_dispatches_nothing(): void
    {
        foreach ([true, false] as $strict) {
            foreach ([true, false] as $enabled) {
                Role::query()->withTrashed()->forceDelete();
                config()->set('gatetrail.audit.enabled', $enabled);
                config()->set('gatetrail.audit.strict', $strict);

                try {
                    $this->app['db']->connection('testing')->transaction(
                        function (): void {
                            $this->create();

                            throw new ForcedRollback('Roll back.');
                        },
                    );

                    $this->fail('The outer transaction should roll back.');
                } catch (ForcedRollback) {
                    $this->assertSame([], $this->events);
                    $this->assertDatabaseCount('acl_roles', 0);
                }
            }
        }

        $this->assertSame([], $this->logger->records);
    }

    public function test_nested_transactions_dispatch_exactly_once(): void
    {
        $connection = $this->app['db']->connection('testing');

        $connection->transaction(function () use ($connection): void {
            $connection->transaction(function () use ($connection): void {
                $connection->transaction(function (): void {
                    $this->create();
                });

                $this->assertSame([], $this->events);
            });

            $this->assertSame([], $this->events);
        });

        $this->assertCount(1, $this->events);
    }

    public function test_an_unchanged_mutation_dispatches_nothing(): void
    {
        foreach ([true, false] as $strict) {
            foreach ([true, false] as $enabled) {
                Role::query()->withTrashed()->forceDelete();
                config()->set('gatetrail.audit.enabled', true);
                config()->set('gatetrail.audit.strict', true);

                $role = $this->create('Reviews documents.')->resource;
                $this->events = [];

                config()->set('gatetrail.audit.enabled', $enabled);
                config()->set('gatetrail.audit.strict', $strict);

                $unchanged = app(RoleManager::class)->update(
                    $role,
                    RoleChanges::description('Reviews documents.'),
                    SystemActor::named('audit-suite'),
                );

                $this->assertSame(
                    MutationOutcome::Unchanged,
                    $unchanged->outcome,
                );
                $this->assertSame([], $this->events);
                $this->assertSame([], $this->logger->records);
            }
        }
    }

    public function test_an_unchanged_caller_owned_mutation_schedules_no_callback(): void
    {
        $role = $this->create('Reviews documents.')->resource;
        $this->events = [];

        $this->app['db']->connection('testing')->transaction(
            function () use ($role): void {
                app(RoleManager::class)->update(
                    $role,
                    RoleChanges::description('Reviews documents.'),
                    SystemActor::named('audit-suite'),
                );

                $this->assertCount(0, $this->pendingCallbacks());
            },
        );

        $this->assertSame([], $this->events);
    }

    public function test_every_mode_rejects_an_unsupported_boundary_before_mutating(): void
    {
        foreach ([
            'strict' => ['enabled' => true, 'strict' => true],
            'non-strict' => ['enabled' => true, 'strict' => false],
            'disabled' => ['enabled' => false, 'strict' => true],
        ] as $label => $mode) {
            config()->set('gatetrail.audit.enabled', $mode['enabled']);
            config()->set('gatetrail.audit.strict', $mode['strict']);

            $connection = $this->app['db']->connection('testing');
            $connection->beginTransaction();
            $connection->unsetTransactionManager();

            try {
                $this->create();

                $this->fail("[$label] should reject the boundary.");
            } catch (UnsupportedAfterCommitBoundary $exception) {
                $this->assertSame('testing', $exception->connection);
                $this->assertDatabaseCount('acl_roles', 0);
                $this->assertSame([], $this->events);
            } finally {
                $connection->rollBack();
                $connection->setTransactionManager(
                    $this->app->make('db.transactions'),
                );
            }
        }
    }

    public function test_a_throwing_listener_never_escapes_a_committed_mutation(): void
    {
        Event::listen(
            MutationCommitted::class,
            static function (): void {
                throw new RuntimeException('Listener exploded.');
            },
        );

        $result = $this->create();

        $this->assertSame(MutationOutcome::Created, $result->outcome);
        $this->assertNotNull($result->auditId);
        $this->assertDatabaseCount('acl_roles', 1);
        $this->assertDatabaseCount('acl_access_audits', 1);

        $errors = $this->logger->errors();

        $this->assertCount(1, $errors);
        $this->assertSame(
            MutationEventDispatcher::MESSAGE,
            $errors[0]['message'],
        );
        $this->assertSame(
            RuntimeException::class,
            $errors[0]['context']['exception'],
        );
        $this->assertSame('role.created', $errors[0]['context']['mutation']);

        $encoded = json_encode($errors[0]['context'], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('Listener exploded', $encoded);
        $this->assertStringNotContainsString('Reviews documents.', $encoded);
        $this->assertStringNotContainsString('Introduce reviewer', $encoded);
        $this->assertStringNotContainsString('SEC-1', $encoded);
    }

    public function test_a_throwing_logger_during_that_report_is_swallowed(): void
    {
        $this->app->instance(
            LoggerInterface::class,
            new RecordingLogger(throws: true),
        );
        Event::listen(
            MutationCommitted::class,
            static function (): void {
                throw new RuntimeException('Listener exploded.');
            },
        );

        $result = $this->create();

        $this->assertSame(MutationOutcome::Created, $result->outcome);
        $this->assertDatabaseCount('acl_roles', 1);
    }

    public function test_a_dispatch_failure_reports_no_recursive_event(): void
    {
        $failures = [];
        Event::listen(
            AuditPersistenceFailed::class,
            static function () use (&$failures): void {
                $failures[] = true;
            },
        );
        Event::listen(
            MutationCommitted::class,
            static function (): void {
                throw new RuntimeException('Listener exploded.');
            },
        );

        $this->create();

        // A dispatch failure is logged, never re-dispatched through the
        // dispatcher that just failed.
        $this->assertSame([], $failures);
        $this->assertCount(1, $this->logger->errors());
    }

    /**
     * @return MutationResult<Model>
     */
    private function create(?string $description = null): MutationResult
    {
        return app(RoleManager::class)->create(
            'reviewer',
            'web',
            SystemActor::named('audit-suite'),
            reason: 'Introduce reviewer',
            description: $description,
            metadata: ['ticket' => 'SEC-1'],
        );
    }

    private function breakAuditStorage(): void
    {
        config()->set(
            'gatetrail.models.access_audit',
            EventlessAccessAudit::class,
        );
    }

    /**
     * @return list<callable>
     */
    private function pendingCallbacks(): array
    {
        $applicable = $this->app->make('db.transactions')
            ->callbackApplicableTransactions()
            ->last();

        return $applicable === null ? [] : $applicable->getCallbacks();
    }
}

final class EventlessAccessAudit extends AccessAudit
{
    public function getTable(): string
    {
        return 'eventless_access_audits';
    }
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Events;

use DateTimeImmutable;
use HangTuah\GateTrail\Audit\PendingAuditRecord;
use HangTuah\GateTrail\Contracts\GateTrailEvent;
use HangTuah\GateTrail\Events\AuditPersistenceFailed;
use HangTuah\GateTrail\Events\MutationCommitted;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\Mutations\ActorIdentity;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Contracts\Events\Dispatcher;
use RuntimeException;

final class GateTrailEventListenerTest extends TestCase
{
    private const SENSITIVE_VALUES = [
        'Confidential reason',
        'before-secret',
        'after-secret',
        'SEC-42',
        'hunter2',
        'consumer metadata',
        'The database exposed a credential.',
    ];

    public function test_the_event_contract_receives_both_safe_event_types(): void
    {
        $received = [];
        $contexts = [];
        $narrowedFields = [];
        $dispatcher = $this->app->make(Dispatcher::class);

        $dispatcher->listen(
            GateTrailEvent::class,
            static function (GateTrailEvent $event) use (
                &$received,
                &$contexts,
                &$narrowedFields,
            ): void {
                $received[] = $event::class;
                $contexts[] = $event->context();

                if ($event instanceof MutationCommitted) {
                    $narrowedFields[] = $event->mutation;

                    return;
                }

                if ($event instanceof AuditPersistenceFailed) {
                    $narrowedFields[] = $event->exceptionClass;
                }
            },
        );

        $dispatcher->dispatch($this->mutationCommitted());
        $dispatcher->dispatch($this->auditPersistenceFailed());

        $this->assertSame(
            [MutationCommitted::class, AuditPersistenceFailed::class],
            $received,
        );
        $this->assertSame(
            ['permission.granted_to_subject', RuntimeException::class],
            $narrowedFields,
        );
        $this->assertSame(
            [
                'mutation',
                'outcome',
                'target_type',
                'target_id',
                'subject_type',
                'subject_id',
                'actor_type',
                'actor_id',
                'audit_id',
                'occurred_at',
                'request_id',
            ],
            array_keys($contexts[0]),
        );
        $this->assertSame(
            [
                'event',
                'target_type',
                'target_id',
                'subject_type',
                'subject_id',
                'exception',
                'request_id',
            ],
            array_keys($contexts[1]),
        );

        $serialized = json_encode($contexts, JSON_THROW_ON_ERROR);

        foreach (self::SENSITIVE_VALUES as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $serialized);
        }

        array_walk_recursive(
            $contexts,
            function (mixed $value): void {
                $this->assertFalse(
                    is_object($value),
                    'A generic event context must not expose an Eloquent model or another object.',
                );
            },
        );
    }

    private function mutationCommitted(): MutationCommitted
    {
        return MutationCommitted::record(
            mutation: 'permission.granted_to_subject',
            outcome: MutationOutcome::Created,
            targetType: 'permission_assignment',
            targetId: 42,
            subjectType: 'test_subject',
            subjectId: 7,
            actorType: 'gatetrail.system',
            actorId: 'event-suite',
            auditId: 9,
            occurredAt: new DateTimeImmutable('2026-08-26T00:00:00+00:00'),
            requestId: 'request-safe-1',
        );
    }

    private function auditPersistenceFailed(): AuditPersistenceFailed
    {
        $record = new PendingAuditRecord(
            event: 'permission.granted_to_subject',
            actor: new ActorIdentity('gatetrail.system', 'event-suite'),
            subjectType: 'test_subject',
            subjectId: 7,
            targetType: 'permission_assignment',
            targetId: 42,
            before: '{"snapshot":"before-secret"}',
            after: '{"snapshot":"after-secret"}',
            reason: 'Confidential reason',
            context: '{"metadata":"consumer metadata","ticket":"SEC-42","password":"hunter2"}',
            ipAddress: null,
            userAgent: null,
            requestId: 'request-safe-1',
            createdAt: new DateTimeImmutable('2026-08-26T00:00:00+00:00'),
        );

        return AuditPersistenceFailed::forRecord(
            $record,
            new RuntimeException('The database exposed a credential.'),
        );
    }
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Events;

use Carbon\CarbonImmutable;
use HangTuah\GateTrail\Audit\PendingAuditRecord;
use HangTuah\GateTrail\Events\AuditPersistenceFailed;
use HangTuah\GateTrail\Exceptions\AuditWriteFailed;
use HangTuah\GateTrail\Mutations\ActorIdentity;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AuditPersistenceFailedTest extends TestCase
{
    private const SECRETS = [
        'Approve submitted documents',
        'Confidential justification',
        'SEC-42',
        'documents.approve',
        'hunter2',
        'The audit table is missing.',
    ];

    public function test_context_exposes_only_approved_fields(): void
    {
        $event = AuditPersistenceFailed::forRecord(
            $this->record(),
            new RuntimeException('The audit table is missing.'),
        );

        $this->assertSame(
            [
                'event',
                'target_type',
                'target_id',
                'subject_type',
                'subject_id',
                'exception',
            ],
            array_keys($event->context()),
        );
        $this->assertSame('permission.granted_to_subject', $event->event);
        $this->assertSame('permission_assignment', $event->targetType);
        $this->assertSame(RuntimeException::class, $event->exceptionClass);
    }

    public function test_no_sensitive_value_reaches_the_payload(): void
    {
        $event = AuditPersistenceFailed::forRecord(
            $this->record(),
            new RuntimeException('The audit table is missing.'),
        );
        $serialized = json_encode(
            [$event->context(), get_object_vars($event)],
            JSON_THROW_ON_ERROR,
        );

        foreach (self::SECRETS as $secret) {
            $this->assertStringNotContainsString(
                $secret,
                $serialized,
                "[$secret] must never leave GateTrail through this event.",
            );
        }

        $this->assertStringNotContainsString('schema_version', $serialized);
        $this->assertStringNotContainsString('metadata', $serialized);
        $this->assertStringNotContainsString('reason', $serialized);
    }

    public function test_request_id_is_included_only_when_available(): void
    {
        $without = AuditPersistenceFailed::forRecord(
            $this->record(),
            new RuntimeException('failed'),
        );

        $this->assertNull($without->requestId);
        $this->assertArrayNotHasKey('request_id', $without->context());

        $with = AuditPersistenceFailed::forRecord(
            $this->record(requestId: '018f4ac0-73a6-7c3e-9a34-0242ac120002'),
            new RuntimeException('failed'),
        );

        $this->assertSame(
            '018f4ac0-73a6-7c3e-9a34-0242ac120002',
            $with->context()['request_id'],
        );
    }

    public function test_integer_and_string_identifiers_are_reported_identically(): void
    {
        $integer = AuditPersistenceFailed::forRecord(
            $this->record(targetId: 42, subjectId: 7),
            new RuntimeException('failed'),
        );
        $string = AuditPersistenceFailed::forRecord(
            $this->record(targetId: '42', subjectId: '7'),
            new RuntimeException('failed'),
        );

        $this->assertSame('42', $integer->context()['target_id']);
        $this->assertSame('7', $integer->context()['subject_id']);
        $this->assertSame(
            $integer->context(),
            $string->context(),
        );
    }

    public function test_an_absent_subject_stays_absent(): void
    {
        $event = AuditPersistenceFailed::forRecord(
            $this->record(subjectType: null, subjectId: null),
            new RuntimeException('failed'),
        );

        $this->assertNull($event->context()['subject_type']);
        $this->assertNull($event->context()['subject_id']);
    }

    public function test_the_underlying_cause_class_is_reported(): void
    {
        $event = AuditPersistenceFailed::forRecord(
            $this->record(),
            AuditWriteFailed::forEvent(
                'permission.granted_to_subject',
                new RuntimeException('The audit table is missing.'),
            ),
        );

        // GateTrail's own wrapper would otherwise be the only class ever
        // recorded, which tells an operator nothing.
        $this->assertSame(RuntimeException::class, $event->exceptionClass);
    }

    public function test_an_unwrapped_failure_reports_its_own_class(): void
    {
        $event = AuditPersistenceFailed::forRecord(
            $this->record(),
            new RuntimeException('failed'),
        );

        $this->assertSame(RuntimeException::class, $event->exceptionClass);
    }

    private function record(
        int|string $targetId = 42,
        ?string $subjectType = 'test_subject',
        int|string|null $subjectId = 7,
        ?string $requestId = null,
    ): PendingAuditRecord {
        return new PendingAuditRecord(
            event: 'permission.granted_to_subject',
            actor: new ActorIdentity('gatetrail.system', 'audit-suite'),
            subjectType: $subjectType,
            subjectId: $subjectId,
            targetType: 'permission_assignment',
            targetId: $targetId,
            before: null,
            after: '{"id":"42","permission_name":"documents.approve",'
                .'"assignment_reason":"Approve submitted documents"}',
            reason: 'Confidential justification',
            context: '{"schema_version":1,"guard_name":"web",'
                .'"permission_name":"documents.approve",'
                .'"metadata":{"ticket":"SEC-42","token":"hunter2"}}',
            ipAddress: null,
            userAgent: null,
            requestId: $requestId,
            createdAt: CarbonImmutable::parse(
                '2026-07-30 08:00:00',
                'UTC',
            )->toDateTimeImmutable(),
        );
    }
}

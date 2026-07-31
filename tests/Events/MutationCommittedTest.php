<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Events;

use DateTimeImmutable;
use DateTimeZone;
use HangTuah\GateTrail\Contracts\GateTrailEvent;
use HangTuah\GateTrail\Events\MutationCommitted;
use HangTuah\GateTrail\MutationOutcome;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class MutationCommittedTest extends TestCase
{
    private const MUTATIONS = [
        'role.assigned',
        'role.revoked',
        'permission.granted_to_subject',
        'permission.revoked_from_subject',
        'permission.granted_to_role',
        'permission.revoked_from_role',
        'role.created',
        'role.updated',
        'role.deactivated',
        'role.restored',
        'permission.created',
        'permission.updated',
        'permission.deactivated',
        'permission.restored',
    ];

    private const SECRETS = [
        'Reviews documents.',
        'Assigned by administrator',
        'SEC-42',
        'hunter2',
        'deleted_at',
        'schema_version',
    ];

    public function test_the_event_is_final_readonly_and_publicly_marked(): void
    {
        $reflection = new ReflectionClass(MutationCommitted::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
        $this->assertTrue(
            $reflection->implementsInterface(GateTrailEvent::class),
        );
        $this->assertTrue(
            $reflection->getConstructor()?->isPrivate() ?? false,
            'Consumers must not construct the event.',
        );
    }

    public function test_every_accepted_mutation_name_is_allowed(): void
    {
        foreach (self::MUTATIONS as $mutation) {
            $this->assertSame(
                $mutation,
                $this->event(mutation: $mutation)->mutation,
            );
        }

        $this->assertCount(14, self::MUTATIONS);
    }

    public function test_an_unknown_mutation_name_is_rejected(): void
    {
        foreach ([
            'permission.granted_directly',
            'permission.revoked_directly',
            'role.renamed',
            '',
        ] as $mutation) {
            try {
                $this->event(mutation: $mutation);

                $this->fail("[$mutation] should be rejected.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString(
                    'unknown mutation event',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_an_unchanged_outcome_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('changed no authorization state');

        $this->event(outcome: MutationOutcome::Unchanged);
    }

    public function test_the_subject_pair_must_be_complete_or_absent(): void
    {
        foreach ([
            ['test_subject', null],
            [null, 7],
        ] as [$type, $id]) {
            try {
                $this->event(subjectType: $type, subjectId: $id);

                $this->fail('A half subject pair should be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString(
                    'both be present or absent',
                    $exception->getMessage(),
                );
            }
        }

        $this->assertNull($this->event()->subjectType);
        $this->assertSame(
            7,
            $this->event(subjectType: 'test_subject', subjectId: 7)->subjectId,
        );
    }

    public function test_target_and_actor_identity_are_required(): void
    {
        try {
            $this->event(targetType: '   ');

            $this->fail('A blank target type should be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString(
                'require a target type',
                $exception->getMessage(),
            );
        }

        foreach ([['', '7'], ['test_subject', ' ']] as [$type, $id]) {
            try {
                $this->event(actorType: $type, actorId: $id);

                $this->fail('A blank actor identity should be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString(
                    'actor type and identifier',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_integer_and_string_identifiers_survive_unchanged(): void
    {
        $integer = $this->event(targetId: 42, subjectType: 's', subjectId: 7);

        $this->assertSame(42, $integer->targetId);
        $this->assertSame(7, $integer->subjectId);
        $this->assertSame('42', $integer->context()['target_id']);
        $this->assertSame('7', $integer->context()['subject_id']);

        foreach ([
            '018f4ac0-73a6-7c3e-9a34-0242ac120002',
            '01J9Z3M7QX8B2N4P6R8T0V2W4Y',
        ] as $key) {
            $event = $this->event(
                targetId: $key,
                subjectType: 's',
                subjectId: $key,
            );

            $this->assertSame($key, $event->targetId);
            $this->assertSame($key, $event->context()['target_id']);
            $this->assertSame($key, $event->context()['subject_id']);
        }
    }

    public function test_the_audit_identifier_is_optional(): void
    {
        $this->assertNull($this->event(auditId: null)->auditId);
        $this->assertNull($this->event(auditId: null)->context()['audit_id']);
        $this->assertSame('9', $this->event(auditId: 9)->context()['audit_id']);
        $this->assertSame(
            '018f4ac0',
            $this->event(auditId: '018f4ac0')->context()['audit_id'],
        );
    }

    public function test_the_timestamp_is_rendered_in_canonical_utc(): void
    {
        $event = $this->event(
            occurredAt: new DateTimeImmutable(
                '2026-07-30 16:00:00',
                new DateTimeZone('Asia/Kuala_Lumpur'),
            ),
        );

        $this->assertSame(
            '2026-07-30T08:00:00.000000Z',
            $event->context()['occurred_at'],
        );
    }

    public function test_context_uses_the_accepted_key_order(): void
    {
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
            ],
            array_keys($this->event()->context()),
        );

        $this->assertSame(
            'created',
            $this->event(outcome: MutationOutcome::Created)
                ->context()['outcome'],
        );
    }

    public function test_the_request_id_appears_only_when_available(): void
    {
        $this->assertArrayNotHasKey(
            'request_id',
            $this->event()->context(),
        );

        $context = $this->event(requestId: 'req-1')->context();

        $this->assertSame('req-1', $context['request_id']);
        $this->assertSame('request_id', array_key_last($context));
    }

    public function test_the_event_survives_a_serialization_round_trip(): void
    {
        $event = $this->event(
            subjectType: 'test_subject',
            subjectId: 7,
            auditId: 9,
            requestId: 'req-1',
        );
        $restored = unserialize(serialize($event));

        $this->assertInstanceOf(MutationCommitted::class, $restored);
        $this->assertEquals($event, $restored);
        $this->assertSame($event->context(), $restored->context());
        $this->assertIsString(
            json_encode($event->context(), JSON_THROW_ON_ERROR),
        );
    }

    public function test_no_sensitive_value_can_reach_the_payload(): void
    {
        $event = $this->event(
            subjectType: 'test_subject',
            subjectId: 7,
            auditId: 9,
        );
        $serialized = json_encode(
            [$event->context(), get_object_vars($event)],
            JSON_THROW_ON_ERROR,
        );

        foreach (self::SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }

        $this->assertSame(
            [
                'mutation',
                'outcome',
                'targetType',
                'targetId',
                'subjectType',
                'subjectId',
                'actorType',
                'actorId',
                'auditId',
                'occurredAt',
                'requestId',
            ],
            array_keys(get_object_vars($event)),
            'The event exposes no snapshot, metadata, reason, or model field.',
        );
    }

    private function event(
        string $mutation = 'role.created',
        MutationOutcome $outcome = MutationOutcome::Created,
        string $targetType = 'role',
        int|string $targetId = 1,
        ?string $subjectType = null,
        int|string|null $subjectId = null,
        string $actorType = 'gatetrail.system',
        string $actorId = 'event-suite',
        int|string|null $auditId = null,
        ?DateTimeImmutable $occurredAt = null,
        ?string $requestId = null,
    ): MutationCommitted {
        return MutationCommitted::record(
            mutation: $mutation,
            outcome: $outcome,
            targetType: $targetType,
            targetId: $targetId,
            subjectType: $subjectType,
            subjectId: $subjectId,
            actorType: $actorType,
            actorId: $actorId,
            auditId: $auditId,
            occurredAt: $occurredAt ?? new DateTimeImmutable(
                '2026-07-30 08:00:00',
                new DateTimeZone('UTC'),
            ),
            requestId: $requestId,
        );
    }
}

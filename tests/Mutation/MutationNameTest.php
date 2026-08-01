<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use DateTimeImmutable;
use DateTimeZone;
use HangTuah\GateTrail\Events\MutationCommitted;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\Mutations\MutationName;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class MutationNameTest extends TestCase
{
    public function test_the_vocabulary_holds_fourteen_unique_names(): void
    {
        $all = MutationName::all();

        $this->assertCount(14, $all);
        $this->assertSame($all, array_values(array_unique($all)));
    }

    public function test_exactly_six_names_are_coordinated_assignments(): void
    {
        $assignments = MutationName::assignments();

        $this->assertCount(6, $assignments);
        $this->assertSame(
            [
                'role.assigned',
                'role.revoked',
                'permission.granted_to_subject',
                'permission.revoked_from_subject',
                'permission.granted_to_role',
                'permission.revoked_from_role',
            ],
            $assignments,
        );
    }

    public function test_every_assignment_name_is_known(): void
    {
        foreach (MutationName::assignments() as $name) {
            $this->assertTrue(MutationName::isKnown($name));
            $this->assertTrue(MutationName::isAssignment($name));
            $this->assertContains($name, MutationName::all());
        }
    }

    public function test_every_definition_name_is_known_but_not_an_assignment(): void
    {
        $definitions = array_values(array_diff(
            MutationName::all(),
            MutationName::assignments(),
        ));

        $this->assertSame(
            [
                'role.created',
                'role.updated',
                'role.deactivated',
                'role.restored',
                'permission.created',
                'permission.updated',
                'permission.deactivated',
                'permission.restored',
            ],
            $definitions,
        );

        foreach ($definitions as $name) {
            $this->assertTrue(MutationName::isKnown($name));
            $this->assertFalse(MutationName::isAssignment($name));
        }
    }

    public function test_unknown_and_empty_names_are_rejected(): void
    {
        foreach ([
            '',
            ' ',
            'role.renamed',
            'permission.granted_directly',
            'permission.revoked_directly',
            'ROLE.ASSIGNED',
            'role.assigned ',
        ] as $name) {
            $this->assertFalse(
                MutationName::isKnown($name),
                "[$name] must not be a known mutation.",
            );
            $this->assertFalse(MutationName::isAssignment($name));
        }
    }

    public function test_the_constants_match_the_published_vocabulary(): void
    {
        $constants = (new ReflectionClass(MutationName::class))->getConstants();
        $public = array_values(array_filter(
            $constants,
            static fn (mixed $value): bool => is_string($value),
        ));

        sort($public);
        $all = MutationName::all();
        sort($all);

        $this->assertSame($all, $public);
    }

    public function test_the_event_accepts_every_vocabulary_name(): void
    {
        foreach (MutationName::all() as $name) {
            $this->assertSame($name, $this->event($name)->mutation);
        }
    }

    public function test_the_event_rejects_a_name_outside_the_vocabulary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown mutation event');

        $this->event('role.renamed');
    }

    public function test_the_event_keeps_no_private_mutation_list(): void
    {
        // One vocabulary, one place: a duplicate list here is exactly the
        // drift MutationName exists to remove.
        $this->assertSame(
            [],
            (new ReflectionClass(MutationCommitted::class))->getConstants(),
        );
    }

    private function event(string $mutation): MutationCommitted
    {
        return MutationCommitted::record(
            mutation: $mutation,
            outcome: MutationOutcome::Created,
            targetType: 'role',
            targetId: 1,
            subjectType: null,
            subjectId: null,
            actorType: 'gatetrail.system',
            actorId: 'vocabulary-suite',
            auditId: null,
            occurredAt: new DateTimeImmutable(
                '2026-08-02 08:00:00',
                new DateTimeZone('UTC'),
            ),
            requestId: null,
        );
    }
}

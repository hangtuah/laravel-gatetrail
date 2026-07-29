<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use HangTuah\GateTrail\AssignmentReference;
use HangTuah\GateTrail\Exceptions\AssignmentNotActive;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\Models\RoleAssignment;
use HangTuah\GateTrail\Mutations\RoleAssignmentInputResolver;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;
use LogicException;

final class RoleAssignmentInputResolverTest extends TestCase
{
    private RoleAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);
        $subject = TestSubject::query()->create(['name' => 'Reviewer']);
        $role = Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'web',
            'description' => null,
        ]);
        $this->assignment = RoleAssignment::query()->create([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'role_id' => $role->getKey(),
            'assigned_at' => '2026-07-29 12:00:00',
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'test-suite',
            'assignment_reason' => 'Resolver fixture',
        ]);
    }

    public function test_model_integer_string_and_reference_resolve_same_row(): void
    {
        $inputs = [
            $this->assignment,
            $this->assignment->getKey(),
            (string) $this->assignment->getKey(),
            AssignmentReference::id($this->assignment->getKey()),
        ];

        $this->app['db']->connection('testing')->transaction(
            function () use ($inputs): void {
                foreach ($inputs as $input) {
                    $resolved = app(RoleAssignmentInputResolver::class)
                        ->resolve($input);

                    $this->assertSame(
                        $this->assignment->getKey(),
                        $resolved->getKey(),
                    );
                }
            },
        );
    }

    public function test_missing_assignment_throws_safe_typed_exception(): void
    {
        try {
            $this->app['db']->connection('testing')->transaction(
                fn () => app(RoleAssignmentInputResolver::class)
                    ->resolve('missing-assignment'),
            );

            $this->fail('Missing assignment should be rejected.');
        } catch (AssignmentNotActive $exception) {
            $this->assertSame('role', $exception->assignmentKind);
            $this->assertSame(
                hash('sha256', 'missing-assignment'),
                $exception->referenceHash,
            );
            $this->assertStringNotContainsString(
                'missing-assignment',
                $exception->getMessage(),
            );
        }
    }

    public function test_resolver_requires_existing_transaction(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('inside a database transaction');

        app(RoleAssignmentInputResolver::class)
            ->resolve($this->assignment);
    }
}

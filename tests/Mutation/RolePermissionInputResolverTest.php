<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use HangTuah\GateTrail\AssignmentReference;
use HangTuah\GateTrail\Exceptions\AssignmentNotActive;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\Models\RolePermission;
use HangTuah\GateTrail\Mutations\RolePermissionInputResolver;
use HangTuah\GateTrail\Tests\TestCase;
use LogicException;

final class RolePermissionInputResolverTest extends TestCase
{
    private RolePermission $assignment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);
        $role = Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'web',
            'description' => null,
        ]);
        $permission = Permission::query()->create([
            'name' => 'documents.approve',
            'guard_name' => 'web',
        ]);
        $this->assignment = RolePermission::query()->create([
            'role_id' => $role->getKey(),
            'permission_id' => $permission->getKey(),
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
            '0'.$this->assignment->getKey(),
            AssignmentReference::id($this->assignment->getKey()),
        ];

        $this->app['db']->connection('testing')->transaction(
            function () use ($inputs): void {
                foreach ($inputs as $input) {
                    $resolved = app(RolePermissionInputResolver::class)
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
                fn () => app(RolePermissionInputResolver::class)
                    ->resolve('missing-assignment'),
            );

            $this->fail('Missing assignment should be rejected.');
        } catch (AssignmentNotActive $exception) {
            $this->assertSame(
                'role_permission',
                $exception->assignmentKind,
            );
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

        app(RolePermissionInputResolver::class)
            ->resolve($this->assignment);
    }

    public function test_malformed_string_identifiers_are_rejected_before_querying(): void
    {
        $malformed = [' 1', '1 ', '+1', '-1', '1.0', '1e3', 'abc'];

        $this->app['db']->connection('testing')->transaction(
            function () use ($malformed): void {
                foreach ($malformed as $identifier) {
                    try {
                        app(RolePermissionInputResolver::class)
                            ->resolve($identifier);

                        $this->fail(sprintf(
                            'Identifier [%s] should be rejected.',
                            $identifier,
                        ));
                    } catch (AssignmentNotActive $exception) {
                        $this->assertSame(
                            'role_permission',
                            $exception->assignmentKind,
                        );
                        $this->assertSame(
                            hash('sha256', $identifier),
                            $exception->referenceHash,
                        );
                        $this->assertStringNotContainsString(
                            $identifier,
                            $exception->getMessage(),
                        );
                    }
                }
            },
        );
    }
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use HangTuah\GateTrail\Exceptions\AssignmentNotActive;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\PermissionAssignment;
use HangTuah\GateTrail\Mutations\PermissionAssignmentInputResolver;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;
use LogicException;

final class PermissionAssignmentInputResolverTest extends TestCase
{
    private PermissionAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--database' => 'testing']);
        $s = TestSubject::query()->create(['name' => 'x']);
        $p = Permission::query()->create(['name' => 'x', 'guard_name' => 'web']);
        $this->assignment = PermissionAssignment::query()->create(['subject_type' => $s->getMorphClass(), 'subject_id' => $s->getKey(), 'permission_id' => $p->getKey(), 'assigned_at' => '2026-07-29 10:00:00', 'assigned_by_type' => 'gatetrail.system', 'assigned_by_id' => 'x']);
    }

    public function test_resolves_under_transaction(): void
    {
        $this->app['db']->connection('testing')->transaction(fn () => $this->assertSame($this->assignment->getKey(), app(PermissionAssignmentInputResolver::class)->resolve((string) $this->assignment->getKey())->getKey()));
    }

    public function test_requires_transaction(): void
    {
        $this->expectException(LogicException::class);
        app(PermissionAssignmentInputResolver::class)->resolve($this->assignment);
    }

    public function test_malformed_string_identifiers_are_rejected_before_querying(): void
    {
        $this->app['db']->connection('testing')->transaction(function (): void {
            foreach ([' 1', '1 ', '+1', '-1', '1.0', '1e3', 'abc'] as $identifier) {
                try {
                    app(PermissionAssignmentInputResolver::class)->resolve($identifier);
                    $this->fail(sprintf('Identifier [%s] should be rejected.', $identifier));
                } catch (AssignmentNotActive $exception) {
                    $this->assertSame('permission', $exception->assignmentKind);
                    $this->assertSame(hash('sha256', $identifier), $exception->referenceHash);
                    $this->assertStringNotContainsString($identifier, $exception->getMessage());
                }
            }
        });
    }
}

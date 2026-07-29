<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use DateTimeImmutable;
use HangTuah\GateTrail\AssignmentPeriod;
use HangTuah\GateTrail\Exceptions\AuditWriteFailed;
use HangTuah\GateTrail\Exceptions\DuplicateActiveAssignment;
use HangTuah\GateTrail\Models\AccessAudit;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\Mutations\Commands\GrantDirectPermissionCommand;
use HangTuah\GateTrail\Mutations\Handlers\GrantDirectPermissionHandler;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Fixtures\ForcedRollback;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;

final class GrantDirectPermissionHandlerTest extends TestCase
{
    private TestSubject $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--database' => 'testing'])->assertExitCode(0);
        $this->subject = TestSubject::query()->create(['name' => 'Reviewer']);
        Permission::query()->create(['name' => 'documents.approve', 'guard_name' => 'web']);
    }

    public function test_create_and_replay_have_canonical_audit_behavior(): void
    {
        $handler = app(GrantDirectPermissionHandler::class);
        $first = $handler->handle($this->command(metadata: ['ticket' => 'SEC-42']));
        $second = $handler->handle($this->command());
        $audit = $this->app['db']->table('acl_access_audits')->where('id', $first->auditId)->first();

        $this->assertSame(MutationOutcome::Created, $first->outcome);
        $this->assertSame(MutationOutcome::Unchanged, $second->outcome);
        $this->assertNull($second->auditId);
        $this->assertSame('permission.granted_to_subject', $audit->event);
        $this->assertSame('permission_assignment', $audit->target_type);
        $this->assertSame(
            'documents.approve',
            json_decode($audit->context, true, flags: JSON_THROW_ON_ERROR)['permission_name'],
        );
        $this->assertDatabaseCount('acl_permission_assignments', 1);
        $this->assertDatabaseCount('acl_access_audits', 1);
    }

    public function test_scheduled_overlap_is_rejected(): void
    {
        $handler = app(GrantDirectPermissionHandler::class);
        $handler->handle($this->command(period: AssignmentPeriod::between(
            new DateTimeImmutable('2026-08-01 08:00:00 UTC'),
            new DateTimeImmutable('2026-08-31 08:00:00 UTC'),
        )));

        $this->expectException(DuplicateActiveAssignment::class);
        $handler->handle($this->command(period: AssignmentPeriod::between(
            new DateTimeImmutable('2026-08-15 08:00:00 UTC'),
            new DateTimeImmutable('2026-09-01 08:00:00 UTC'),
        )));
    }

    public function test_audit_failure_rolls_back_permission_assignment(): void
    {
        config()->set(
            'gatetrail.models.access_audit',
            MissingGrantAccessAudit::class,
        );

        try {
            app(GrantDirectPermissionHandler::class)->handle($this->command());
            $this->fail('Audit persistence should fail.');
        } catch (AuditWriteFailed) {
            $this->assertDatabaseCount('acl_assignment_locks', 0);
            $this->assertDatabaseCount('acl_permission_assignments', 0);
        }
    }

    public function test_outer_rollback_removes_permission_assignment_and_audit(): void
    {
        try {
            $this->app['db']->connection('testing')->transaction(function (): void {
                $result = app(GrantDirectPermissionHandler::class)->handle($this->command());
                $this->assertSame(MutationOutcome::Created, $result->outcome);
                $this->assertDatabaseCount('acl_permission_assignments', 1);
                $this->assertDatabaseCount('acl_access_audits', 1);
                throw new ForcedRollback('Roll back outer transaction.');
            });
            $this->fail('The outer transaction should roll back.');
        } catch (ForcedRollback) {
            $this->assertDatabaseCount('acl_assignment_locks', 0);
            $this->assertDatabaseCount('acl_permission_assignments', 0);
            $this->assertDatabaseCount('acl_access_audits', 0);
        }
    }

    /** @param array<string, mixed> $metadata */
    private function command(
        ?AssignmentPeriod $period = null,
        array $metadata = [],
    ): GrantDirectPermissionCommand {
        return GrantDirectPermissionCommand::capture(
            $this->subject,
            'documents.approve',
            SystemActor::named('test-suite'),
            'Direct grant',
            $period ?? AssignmentPeriod::immediate(),
            $metadata,
            new FrozenClock(new DateTimeImmutable('2026-07-29 12:00:00 UTC')),
        );
    }
}

final class MissingGrantAccessAudit extends AccessAudit
{
    public function getTable(): string
    {
        return 'missing_grant_access_audits';
    }
}

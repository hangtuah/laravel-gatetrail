<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use DateTimeImmutable;
use HangTuah\GateTrail\AssignmentPeriod;
use HangTuah\GateTrail\Audit\SensitiveMetadataRedactor;
use HangTuah\GateTrail\Exceptions\AuditWriteFailed;
use HangTuah\GateTrail\Exceptions\DuplicateActiveAssignment;
use HangTuah\GateTrail\Exceptions\GuardMismatch;
use HangTuah\GateTrail\Exceptions\InvalidAuditMetadata;
use HangTuah\GateTrail\Models\AccessAudit;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\Mutations\Commands\AssignRoleCommand;
use HangTuah\GateTrail\Mutations\Handlers\AssignRoleHandler;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Fixtures\ForcedRollback;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;

final class AssignRoleHandlerTest extends TestCase
{
    private TestSubject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);

        $this->subject = TestSubject::query()->create([
            'name' => 'Reviewer',
        ]);
        Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'web',
            'description' => null,
        ]);
    }

    public function test_handler_creates_assignment_and_exact_audit_record(): void
    {
        $result = app(AssignRoleHandler::class)->handle(
            $this->command(metadata: ['source' => 'administrator']),
        );

        $this->assertSame(MutationOutcome::Created, $result->outcome);
        $this->assertNotNull($result->auditId);
        $this->assertTrue($result->changed());
        $this->assertSame(
            $this->subject->getMorphClass(),
            $result->resource->getAttribute('subject_type'),
        );
        $this->assertSame(
            'Assigned by administrator',
            $result->resource->getAttribute('assignment_reason'),
        );

        $audit = $this->app['db']
            ->table('acl_access_audits')
            ->where('id', $result->auditId)
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame('role.assigned', $audit->event);
        $this->assertSame('gatetrail.system', $audit->actor_type);
        $this->assertSame('test-suite', $audit->actor_id);
        $this->assertSame(
            $this->subject->getMorphClass(),
            $audit->subject_type,
        );
        $this->assertSame('role_assignment', $audit->target_type);
        $this->assertNull($audit->before);
        $this->assertSame(
            'Assigned by administrator',
            $audit->reason,
        );
        $this->assertSame([
            'schema_version' => 1,
            'guard_name' => 'web',
            'role_name' => 'reviewer',
            'metadata' => ['source' => 'administrator'],
        ], json_decode($audit->context, true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame(
            'Assigned by administrator',
            json_decode(
                $audit->after,
                true,
                flags: JSON_THROW_ON_ERROR,
            )['assignment_reason'],
        );
    }

    public function test_immediate_replay_is_unchanged_without_second_audit(): void
    {
        $first = app(AssignRoleHandler::class)->handle($this->command());
        $second = app(AssignRoleHandler::class)->handle($this->command());

        $this->assertSame(MutationOutcome::Created, $first->outcome);
        $this->assertSame(MutationOutcome::Unchanged, $second->outcome);
        $this->assertFalse($second->changed());
        $this->assertNull($second->auditId);
        $this->assertSame(
            $first->resource->getKey(),
            $second->resource->getKey(),
        );
        $this->assertDatabaseCount('acl_role_assignments', 1);
        $this->assertDatabaseCount('acl_access_audits', 1);
    }

    public function test_overlapping_scheduled_assignment_is_rejected(): void
    {
        app(AssignRoleHandler::class)->handle(
            $this->command(
                period: AssignmentPeriod::between(
                    new DateTimeImmutable('2026-07-29 13:00:00 UTC'),
                    new DateTimeImmutable('2026-07-29 15:00:00 UTC'),
                ),
            ),
        );

        $this->expectException(DuplicateActiveAssignment::class);

        app(AssignRoleHandler::class)->handle(
            $this->command(
                period: AssignmentPeriod::between(
                    new DateTimeImmutable('2026-07-29 14:00:00 UTC'),
                    new DateTimeImmutable('2026-07-29 16:00:00 UTC'),
                ),
            ),
        );
    }

    public function test_guard_mismatch_remains_a_typed_failure(): void
    {
        Role::query()->create([
            'name' => 'administrator',
            'guard_name' => 'admin',
            'description' => null,
        ]);

        $this->expectException(GuardMismatch::class);

        app(AssignRoleHandler::class)->handle(
            $this->command(role: 2),
        );
    }

    public function test_invalid_metadata_fails_before_any_assignment_write(): void
    {
        $recursive = [];
        $recursive['self'] = &$recursive;

        try {
            app(AssignRoleHandler::class)->handle(
                $this->command(metadata: ['payload' => $recursive]),
            );

            $this->fail('Recursive metadata should be rejected.');
        } catch (InvalidAuditMetadata) {
            $this->assertDatabaseCount('acl_assignment_locks', 0);
            $this->assertDatabaseCount('acl_role_assignments', 0);
            $this->assertDatabaseCount('acl_access_audits', 0);
        }
    }

    public function test_sensitive_reasons_are_redacted_only_in_audit_data(): void
    {
        config()->set('gatetrail.sensitive_fields', [
            'assignment_reason',
            'reason',
        ]);

        $result = app(AssignRoleHandler::class)->handle($this->command());
        $audit = $this->app['db']
            ->table('acl_access_audits')
            ->where('id', $result->auditId)
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame(
            'Assigned by administrator',
            $result->resource->getAttribute('assignment_reason'),
        );
        $this->assertSame(
            SensitiveMetadataRedactor::MARKER,
            $audit->reason,
        );
        $this->assertSame(
            SensitiveMetadataRedactor::MARKER,
            json_decode(
                $audit->after,
                true,
                flags: JSON_THROW_ON_ERROR,
            )['assignment_reason'],
        );
    }

    public function test_audit_failure_rolls_back_assignment_and_lock(): void
    {
        config()->set(
            'gatetrail.models.access_audit',
            MissingHandlerAccessAudit::class,
        );

        try {
            app(AssignRoleHandler::class)->handle($this->command());

            $this->fail('Audit persistence should fail.');
        } catch (AuditWriteFailed) {
            $this->assertDatabaseCount('acl_assignment_locks', 0);
            $this->assertDatabaseCount('acl_role_assignments', 0);
            $this->assertDatabaseCount('acl_access_audits', 0);
        }
    }

    public function test_caller_owned_rollback_removes_assignment_and_audit(): void
    {
        $connection = $this->app['db']->connection('testing');

        try {
            $connection->transaction(function (): void {
                $result = app(AssignRoleHandler::class)
                    ->handle($this->command());

                $this->assertSame(
                    MutationOutcome::Created,
                    $result->outcome,
                );
                $this->assertDatabaseCount('acl_role_assignments', 1);
                $this->assertDatabaseCount('acl_access_audits', 1);

                throw new ForcedRollback('Roll back outer transaction.');
            });

            $this->fail('The outer transaction should roll back.');
        } catch (ForcedRollback) {
            $this->assertDatabaseCount('acl_assignment_locks', 0);
            $this->assertDatabaseCount('acl_role_assignments', 0);
            $this->assertDatabaseCount('acl_access_audits', 0);
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function command(
        string|int $role = 'reviewer',
        ?AssignmentPeriod $period = null,
        array $metadata = [],
    ): AssignRoleCommand {
        return AssignRoleCommand::capture(
            subject: $this->subject,
            role: $role,
            actor: SystemActor::named('test-suite'),
            reason: 'Assigned by administrator',
            period: $period ?? AssignmentPeriod::immediate(),
            metadata: $metadata,
            clock: new FrozenClock(
                new DateTimeImmutable('2026-07-29 12:00:00 UTC'),
            ),
        );
    }
}

final class MissingHandlerAccessAudit extends AccessAudit
{
    public function getTable(): string
    {
        return 'missing_handler_access_audits';
    }
}

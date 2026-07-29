<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use DateTimeImmutable;
use HangTuah\GateTrail\AssignmentReference;
use HangTuah\GateTrail\Audit\SensitiveMetadataRedactor;
use HangTuah\GateTrail\Exceptions\AssignmentNotActive;
use HangTuah\GateTrail\Exceptions\AuditWriteFailed;
use HangTuah\GateTrail\Models\AccessAudit;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\Models\RoleAssignment;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\Mutations\Commands\RevokeRoleCommand;
use HangTuah\GateTrail\Mutations\Handlers\RevokeRoleHandler;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;
use RuntimeException;

final class RevokeRoleHandlerTest extends TestCase
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
            'assigned_at' => '2026-07-29 10:00:00',
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'assignment-suite',
            'assignment_reason' => 'Initial assignment',
            'starts_at' => null,
            'expires_at' => null,
        ]);
    }

    public function test_handler_revokes_assignment_with_exact_audit_snapshots(): void
    {
        $result = app(RevokeRoleHandler::class)->handle(
            $this->command(
                $this->assignment,
                ['ticket' => 'SEC-42'],
            ),
        );

        $this->assertSame(MutationOutcome::Revoked, $result->outcome);
        $this->assertNotNull($result->auditId);
        $this->assertSame(
            '2026-07-29 12:00:00',
            $result->resource->getAttribute('revoked_at')
                ?->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            'gatetrail.system',
            $result->resource->getAttribute('revoked_by_type'),
        );
        $this->assertSame(
            'revocation-suite',
            $result->resource->getAttribute('revoked_by_id'),
        );
        $this->assertSame(
            'Transferred to another unit',
            $result->resource->getAttribute('revocation_reason'),
        );

        $audit = $this->app['db']
            ->table('acl_access_audits')
            ->where('id', $result->auditId)
            ->first();
        $before = json_decode(
            $audit->before,
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $after = json_decode(
            $audit->after,
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $context = json_decode(
            $audit->context,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('role.revoked', $audit->event);
        $this->assertSame('role_assignment', $audit->target_type);
        $this->assertNull($before['revoked_at']);
        $this->assertNull($before['revoked_by_type']);
        $this->assertNull($before['revocation_reason']);
        $this->assertSame(
            '2026-07-29T12:00:00.000000Z',
            $after['revoked_at'],
        );
        $this->assertSame(
            'Transferred to another unit',
            $after['revocation_reason'],
        );
        $this->assertSame('reviewer', $context['role_name']);
        $this->assertSame(['ticket' => 'SEC-42'], $context['metadata']);
    }

    public function test_scheduled_assignment_can_be_cancelled_before_start(): void
    {
        $this->assignment->forceFill([
            'starts_at' => '2026-08-01 08:00:00',
            'expires_at' => '2026-09-01 08:00:00',
        ])->save();

        $result = app(RevokeRoleHandler::class)->handle(
            $this->command(AssignmentReference::id(
                $this->assignment->getKey(),
            )),
        );

        $this->assertSame(MutationOutcome::Revoked, $result->outcome);
    }

    public function test_expired_assignment_is_not_revocable(): void
    {
        $this->assignment->forceFill([
            'expires_at' => '2026-07-29 11:59:59',
        ])->save();

        $this->expectException(AssignmentNotActive::class);

        app(RevokeRoleHandler::class)->handle(
            $this->command($this->assignment->getKey()),
        );
    }

    public function test_repeated_revocation_is_not_active(): void
    {
        $handler = app(RevokeRoleHandler::class);
        $handler->handle($this->command($this->assignment));

        $this->expectException(AssignmentNotActive::class);

        $handler->handle($this->command(
            (string) $this->assignment->getKey(),
        ));
    }

    public function test_sensitive_revocation_values_are_redacted_in_audit_only(): void
    {
        config()->set('gatetrail.sensitive_fields', [
            'after.revocation_reason',
            'reason',
        ]);

        $result = app(RevokeRoleHandler::class)->handle(
            $this->command($this->assignment),
        );
        $audit = $this->app['db']
            ->table('acl_access_audits')
            ->where('id', $result->auditId)
            ->first();

        $this->assertSame(
            'Transferred to another unit',
            $result->resource->getAttribute('revocation_reason'),
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
            )['revocation_reason'],
        );
        $this->assertNull(
            json_decode(
                $audit->before,
                true,
                flags: JSON_THROW_ON_ERROR,
            )['revocation_reason'],
        );
    }

    public function test_audit_failure_rolls_back_revocation(): void
    {
        config()->set(
            'gatetrail.models.access_audit',
            MissingRevokeAccessAudit::class,
        );

        try {
            app(RevokeRoleHandler::class)->handle(
                $this->command($this->assignment),
            );

            $this->fail('Audit persistence should fail.');
        } catch (AuditWriteFailed) {
            $this->assertNull(
                $this->assignment->fresh()?->getAttribute('revoked_at'),
            );
            $this->assertDatabaseCount('acl_access_audits', 0);
        }
    }

    public function test_caller_owned_rollback_removes_revocation_and_audit(): void
    {
        $connection = $this->app['db']->connection('testing');

        try {
            $connection->transaction(function (): void {
                app(RevokeRoleHandler::class)->handle(
                    $this->command($this->assignment),
                );

                throw new RuntimeException('Roll back outer transaction.');
            });
        } catch (RuntimeException) {
            $this->assertNull(
                $this->assignment->fresh()?->getAttribute('revoked_at'),
            );
            $this->assertDatabaseCount('acl_access_audits', 0);
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function command(
        RoleAssignment|AssignmentReference|string|int $assignment,
        array $metadata = [],
    ): RevokeRoleCommand {
        return RevokeRoleCommand::capture(
            assignment: $assignment,
            actor: SystemActor::named('revocation-suite'),
            reason: 'Transferred to another unit',
            metadata: $metadata,
            clock: new FrozenClock(
                new DateTimeImmutable('2026-07-29 12:00:00 UTC'),
            ),
        );
    }
}

final class MissingRevokeAccessAudit extends AccessAudit
{
    public function getTable(): string
    {
        return 'missing_revoke_access_audits';
    }
}

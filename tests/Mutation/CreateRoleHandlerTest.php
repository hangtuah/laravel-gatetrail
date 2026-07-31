<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use DateTimeImmutable;
use HangTuah\GateTrail\Exceptions\AuditWriteFailed;
use HangTuah\GateTrail\Exceptions\DuplicateRole;
use HangTuah\GateTrail\Exceptions\InvalidAuditMetadata;
use HangTuah\GateTrail\Exceptions\ReasonRequired;
use HangTuah\GateTrail\Models\AccessAudit;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\Mutations\Commands\CreateRoleCommand;
use HangTuah\GateTrail\Mutations\Handlers\CreateRoleHandler;
use HangTuah\GateTrail\Mutations\MutationReasonValidator;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Fixtures\ForcedRollback;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class CreateRoleHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);
    }

    public function test_handler_creates_role_with_canonical_audit_evidence(): void
    {
        $result = app(CreateRoleHandler::class)->handle($this->command(
            reason: 'Initial provisioning',
            description: 'Reviews documents.',
            metadata: ['ticket' => 'SEC-42'],
        ));

        $this->assertSame(MutationOutcome::Created, $result->outcome);
        $this->assertNotNull($result->auditId);
        $this->assertSame(
            'reviewer',
            $result->resource->getAttribute('name'),
        );
        $this->assertSame(
            'web',
            $result->resource->getAttribute('guard_name'),
        );
        $this->assertSame(
            'Reviews documents.',
            $result->resource->getAttribute('description'),
        );
        $this->assertSame(
            '2026-07-29 12:00:00',
            $result->resource->getAttribute('created_at')
                ?->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-07-29 12:00:00',
            $result->resource->getAttribute('updated_at')
                ?->format('Y-m-d H:i:s'),
        );
        $this->assertNull($result->resource->getAttribute('deleted_at'));

        $audit = $this->app['db']
            ->table('acl_access_audits')
            ->where('id', $result->auditId)
            ->first();

        $this->assertSame('role.created', $audit->event);
        $this->assertSame('role', $audit->target_type);
        $this->assertSame(
            (string) $result->resource->getKey(),
            (string) $audit->target_id,
        );
        $this->assertNull($audit->subject_type);
        $this->assertNull($audit->subject_id);
        $this->assertSame('gatetrail.system', $audit->actor_type);
        $this->assertSame('definition-suite', $audit->actor_id);
        $this->assertSame('Initial provisioning', $audit->reason);
        $this->assertNull($audit->before);
        $this->assertSame(
            '{"id":"1","name":"reviewer","guard_name":"web",'
            .'"description":"Reviews documents.","deleted_at":null}',
            $audit->after,
        );
        $this->assertSame(
            '{"schema_version":1,"guard_name":"web",'
            .'"role_name":"reviewer","metadata":{"ticket":"SEC-42"}}',
            $audit->context,
        );
    }

    public function test_human_actor_and_null_reason_are_recorded(): void
    {
        $actor = TestSubject::query()->create(['name' => 'Admin']);

        $result = app(CreateRoleHandler::class)->handle(
            $this->command(actor: $actor),
        );
        $audit = $this->app['db']
            ->table('acl_access_audits')
            ->where('id', $result->auditId)
            ->first();

        $this->assertSame(MutationOutcome::Created, $result->outcome);
        $this->assertSame($actor->getMorphClass(), $audit->actor_type);
        $this->assertSame((string) $actor->getKey(), $audit->actor_id);
        $this->assertNull($audit->reason);
    }

    public function test_required_definition_reason_rejects_null_before_transaction(): void
    {
        config()->set('gatetrail.definitions.require_reason', true);
        app()->forgetInstance(MutationReasonValidator::class);

        try {
            app(CreateRoleHandler::class)->handle($this->command());

            $this->fail('A missing definition reason should be rejected.');
        } catch (ReasonRequired) {
            $this->assertDatabaseCount('acl_roles', 0);
            $this->assertDatabaseCount('acl_access_audits', 0);
        }
    }

    public function test_invalid_metadata_is_rejected_before_transaction(): void
    {
        try {
            app(CreateRoleHandler::class)->handle($this->command(
                metadata: ['file' => fopen('php://memory', 'r')],
            ));

            $this->fail('Invalid metadata should be rejected.');
        } catch (InvalidAuditMetadata) {
            $this->assertDatabaseCount('acl_roles', 0);
            $this->assertDatabaseCount('acl_access_audits', 0);
        }
    }

    public function test_duplicate_active_role_is_rejected(): void
    {
        $handler = app(CreateRoleHandler::class);
        $handler->handle($this->command());

        try {
            $handler->handle($this->command());

            $this->fail('A duplicate active role should be rejected.');
        } catch (DuplicateRole $exception) {
            $this->assertStringNotContainsString(
                'reviewer',
                $exception->getMessage(),
            );
            $this->assertDatabaseCount('acl_roles', 1);
            $this->assertDatabaseCount('acl_access_audits', 1);
        }
    }

    public function test_duplicate_deactivated_role_is_rejected_without_restore(): void
    {
        $handler = app(CreateRoleHandler::class);
        $created = $handler->handle($this->command());
        $role = Role::query()
            ->whereKey($created->resource->getKey())
            ->firstOrFail();
        $role->delete();

        try {
            $handler->handle($this->command());

            $this->fail('A deactivated namespace should be rejected.');
        } catch (DuplicateRole) {
            $this->assertNotNull(
                Role::withTrashed()
                    ->whereKey($role->getKey())
                    ->firstOrFail()
                    ->getAttribute('deleted_at'),
            );
            $this->assertDatabaseCount('acl_roles', 1);
        }
    }

    public function test_audit_failure_rolls_back_role_creation(): void
    {
        config()->set(
            'gatetrail.models.access_audit',
            MissingRoleCreateAccessAudit::class,
        );

        try {
            app(CreateRoleHandler::class)->handle($this->command());

            $this->fail('Audit persistence should fail.');
        } catch (AuditWriteFailed) {
            $this->assertDatabaseCount('acl_roles', 0);
            $this->assertDatabaseCount('acl_access_audits', 0);
        }
    }

    public function test_caller_owned_rollback_removes_role_and_audit(): void
    {
        $connection = $this->app['db']->connection('testing');

        try {
            $connection->transaction(function (): void {
                $result = app(CreateRoleHandler::class)
                    ->handle($this->command());

                $this->assertSame(
                    MutationOutcome::Created,
                    $result->outcome,
                );
                $this->assertDatabaseCount('acl_roles', 1);
                $this->assertDatabaseCount('acl_access_audits', 1);

                throw new ForcedRollback('Roll back outer transaction.');
            });

            $this->fail('The outer transaction should roll back.');
        } catch (ForcedRollback) {
            $this->assertDatabaseCount('acl_roles', 0);
            $this->assertDatabaseCount('acl_access_audits', 0);
        }
    }

    public function test_handler_requires_enabled_auditing(): void
    {
        // Non-strict auditing is supported; disabled auditing is not yet.
        config()->set('gatetrail.audit.enabled', false);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires enabled auditing');

        app(CreateRoleHandler::class)->handle($this->command());
    }

    public function test_configured_role_subclass_remains_supported(): void
    {
        config()->set(
            'gatetrail.models.role',
            HandlerCustomRole::class,
        );

        $result = app(CreateRoleHandler::class)->handle($this->command());

        $this->assertInstanceOf(HandlerCustomRole::class, $result->resource);
        $this->assertSame(MutationOutcome::Created, $result->outcome);
        $this->assertNotNull($result->auditId);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function command(
        Model|SystemActor|null $actor = null,
        ?string $reason = null,
        ?string $description = null,
        array $metadata = [],
    ): CreateRoleCommand {
        return CreateRoleCommand::capture(
            name: 'reviewer',
            guard: 'web',
            actor: $actor ?? SystemActor::named('definition-suite'),
            reason: $reason,
            description: $description,
            metadata: $metadata,
            clock: new FrozenClock(
                new DateTimeImmutable('2026-07-29 12:00:00 UTC'),
            ),
        );
    }
}

final class MissingRoleCreateAccessAudit extends AccessAudit
{
    public function getTable(): string
    {
        return 'missing_role_create_access_audits';
    }
}

final class HandlerCustomRole extends Role {}

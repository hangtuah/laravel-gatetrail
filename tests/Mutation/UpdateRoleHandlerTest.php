<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use DateTimeImmutable;
use HangTuah\GateTrail\Exceptions\AuditWriteFailed;
use HangTuah\GateTrail\Exceptions\InvalidActor;
use HangTuah\GateTrail\Exceptions\InvalidAuditMetadata;
use HangTuah\GateTrail\Exceptions\ReasonRequired;
use HangTuah\GateTrail\Exceptions\RoleNotFound;
use HangTuah\GateTrail\Models\AccessAudit;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\Mutations\Commands\UpdateRoleCommand;
use HangTuah\GateTrail\Mutations\Handlers\UpdateRoleHandler;
use HangTuah\GateTrail\Mutations\MutationReasonValidator;
use HangTuah\GateTrail\RoleChanges;
use HangTuah\GateTrail\RoleReference;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Fixtures\ForcedRollback;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class UpdateRoleHandlerTest extends TestCase
{
    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);
        $this->role = Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'web',
            'description' => 'Reviews documents.',
        ]);
        $this->role->forceFill([
            'created_at' => '2026-07-28 09:00:00',
            'updated_at' => '2026-07-28 09:00:00',
        ])->save();
    }

    public function test_handler_updates_description_with_canonical_audit_evidence(): void
    {
        $result = app(UpdateRoleHandler::class)->handle($this->command(
            changes: RoleChanges::description(
                'Reviews submitted documents',
            ),
            reason: 'Clarify role purpose',
            metadata: ['ticket' => 'SEC-42'],
        ));

        $this->assertSame(MutationOutcome::Updated, $result->outcome);
        $this->assertNotNull($result->auditId);
        $this->assertTrue($result->changed());
        $this->assertSame(
            'Reviews submitted documents',
            $result->resource->getAttribute('description'),
        );
        $this->assertSame(
            '2026-07-29 12:00:00',
            $result->resource->getAttribute('updated_at')
                ?->format('Y-m-d H:i:s'),
        );

        $audit = $this->audit($result->auditId);

        $this->assertSame('role.updated', $audit->event);
        $this->assertSame('role', $audit->target_type);
        $this->assertSame(
            (string) $this->role->getKey(),
            (string) $audit->target_id,
        );
        $this->assertNull($audit->subject_type);
        $this->assertNull($audit->subject_id);
        $this->assertSame('gatetrail.system', $audit->actor_type);
        $this->assertSame('definition-suite', $audit->actor_id);
        $this->assertSame('Clarify role purpose', $audit->reason);
        $this->assertSame(
            '{"id":"1","name":"reviewer","guard_name":"web",'
            .'"description":"Reviews documents.","deleted_at":null}',
            $audit->before,
        );
        $this->assertSame(
            '{"id":"1","name":"reviewer","guard_name":"web",'
            .'"description":"Reviews submitted documents",'
            .'"deleted_at":null}',
            $audit->after,
        );
        $this->assertSame(
            '{"schema_version":1,"guard_name":"web",'
            .'"role_name":"reviewer","metadata":{"ticket":"SEC-42"}}',
            $audit->context,
        );
    }

    public function test_explicit_null_clears_the_persisted_description(): void
    {
        $result = app(UpdateRoleHandler::class)->handle($this->command(
            changes: RoleChanges::description(null),
        ));

        $this->assertSame(MutationOutcome::Updated, $result->outcome);
        $this->assertNull($result->resource->getAttribute('description'));
        $this->assertNull(
            $this->role->fresh()?->getAttribute('description'),
        );
        $this->assertStringContainsString(
            '"description":null',
            (string) $this->audit($result->auditId)->after,
        );
    }

    public function test_explicit_empty_string_is_persisted(): void
    {
        $result = app(UpdateRoleHandler::class)->handle($this->command(
            changes: RoleChanges::description(''),
        ));

        $this->assertSame(MutationOutcome::Updated, $result->outcome);
        $this->assertSame(
            '',
            $this->role->fresh()?->getAttribute('description'),
        );
        $this->assertStringContainsString(
            '"description":""',
            (string) $this->audit($result->auditId)->after,
        );
    }

    public function test_identical_description_returns_unchanged_without_audit(): void
    {
        $result = app(UpdateRoleHandler::class)->handle($this->command(
            changes: RoleChanges::description('Reviews documents.'),
        ));

        $this->assertSame(MutationOutcome::Unchanged, $result->outcome);
        $this->assertNull($result->auditId);
        $this->assertFalse($result->changed());
        $this->assertSame(
            $this->role->getKey(),
            $result->resource->getKey(),
        );
        $this->assertDatabaseCount('acl_access_audits', 0);
        $this->assertSame(
            '2026-07-28 09:00:00',
            $this->role->fresh()?->getAttribute('updated_at')
                ?->format('Y-m-d H:i:s'),
        );
    }

    public function test_identical_null_description_returns_unchanged(): void
    {
        $this->role->forceFill(['description' => null])->save();
        $this->role->forceFill([
            'updated_at' => '2026-07-28 09:00:00',
        ])->save();

        $result = app(UpdateRoleHandler::class)->handle($this->command(
            changes: RoleChanges::description(null),
        ));

        $this->assertSame(MutationOutcome::Unchanged, $result->outcome);
        $this->assertNull($result->auditId);
        $this->assertDatabaseCount('acl_access_audits', 0);
        $this->assertSame(
            '2026-07-28 09:00:00',
            $this->role->fresh()?->getAttribute('updated_at')
                ?->format('Y-m-d H:i:s'),
        );
    }

    public function test_name_and_guard_remain_immutable(): void
    {
        app(UpdateRoleHandler::class)->handle($this->command(
            changes: RoleChanges::description('Updated purpose'),
        ));
        $persisted = $this->role->fresh();

        $this->assertSame('reviewer', $persisted?->getAttribute('name'));
        $this->assertSame('web', $persisted?->getAttribute('guard_name'));
        $this->assertNull($persisted?->getAttribute('deleted_at'));
        $this->assertSame(
            '2026-07-28 09:00:00',
            $persisted?->getAttribute('created_at')
                ?->format('Y-m-d H:i:s'),
        );
    }

    public function test_human_actor_is_recorded(): void
    {
        $actor = TestSubject::query()->create(['name' => 'Admin']);

        $result = app(UpdateRoleHandler::class)->handle($this->command(
            changes: RoleChanges::description('Updated purpose'),
            actor: $actor,
        ));
        $audit = $this->audit($result->auditId);

        $this->assertSame($actor->getMorphClass(), $audit->actor_type);
        $this->assertSame((string) $actor->getKey(), $audit->actor_id);
        $this->assertNull($audit->reason);
    }

    public function test_reference_and_name_inputs_resolve_the_same_role(): void
    {
        $first = app(UpdateRoleHandler::class)->handle($this->command(
            role: RoleReference::id($this->role->getKey()),
            changes: RoleChanges::description('First update'),
        ));
        $second = app(UpdateRoleHandler::class)->handle($this->command(
            role: 'reviewer',
            changes: RoleChanges::description('Second update'),
        ));

        $this->assertSame(MutationOutcome::Updated, $first->outcome);
        $this->assertSame(MutationOutcome::Updated, $second->outcome);
        $this->assertSame(
            'Second update',
            $this->role->fresh()?->getAttribute('description'),
        );
    }

    public function test_required_definition_reason_fails_before_transaction(): void
    {
        config()->set('gatetrail.definitions.require_reason', true);
        app()->forgetInstance(MutationReasonValidator::class);

        try {
            app(UpdateRoleHandler::class)->handle($this->command(
                changes: RoleChanges::description('Updated purpose'),
            ));

            $this->fail('A missing definition reason should be rejected.');
        } catch (ReasonRequired) {
            $this->assertUnchangedState();
        }
    }

    public function test_invalid_metadata_fails_before_transaction(): void
    {
        try {
            app(UpdateRoleHandler::class)->handle($this->command(
                changes: RoleChanges::description('Updated purpose'),
                metadata: ['handle' => fopen('php://memory', 'r')],
            ));

            $this->fail('Invalid metadata should be rejected.');
        } catch (InvalidAuditMetadata) {
            $this->assertUnchangedState();
        }
    }

    public function test_invalid_actor_fails_before_transaction(): void
    {
        try {
            app(UpdateRoleHandler::class)->handle($this->command(
                changes: RoleChanges::description('Updated purpose'),
                actor: new TestSubject,
            ));

            $this->fail('An unpersisted actor should be rejected.');
        } catch (InvalidActor) {
            $this->assertUnchangedState();
        }
    }

    public function test_soft_deleted_role_is_rejected(): void
    {
        $this->role->delete();

        $this->expectException(RoleNotFound::class);

        app(UpdateRoleHandler::class)->handle($this->command(
            changes: RoleChanges::description('Updated purpose'),
        ));
    }

    public function test_audit_failure_rolls_back_the_description(): void
    {
        config()->set(
            'gatetrail.models.access_audit',
            MissingRoleUpdateAccessAudit::class,
        );

        try {
            app(UpdateRoleHandler::class)->handle($this->command(
                changes: RoleChanges::description('Updated purpose'),
            ));

            $this->fail('Audit persistence should fail.');
        } catch (AuditWriteFailed) {
            $this->assertUnchangedState();
        }
    }

    public function test_caller_owned_rollback_restores_description_and_audit(): void
    {
        try {
            $this->app['db']->connection('testing')->transaction(
                function (): void {
                    $result = app(UpdateRoleHandler::class)->handle(
                        $this->command(
                            changes: RoleChanges::description(
                                'Updated purpose',
                            ),
                        ),
                    );

                    $this->assertSame(
                        MutationOutcome::Updated,
                        $result->outcome,
                    );
                    $this->assertSame(
                        'Updated purpose',
                        $this->role->fresh()?->getAttribute('description'),
                    );
                    $this->assertDatabaseCount('acl_access_audits', 1);

                    throw new ForcedRollback(
                        'Roll back outer transaction.',
                    );
                },
            );

            $this->fail('The outer transaction should roll back.');
        } catch (ForcedRollback) {
            $this->assertUnchangedState();
        }
    }

    public function test_handler_requires_enabled_auditing(): void
    {
        // Non-strict auditing is supported; disabled auditing is not yet.
        config()->set('gatetrail.audit.enabled', false);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires enabled auditing');

        app(UpdateRoleHandler::class)->handle($this->command(
            changes: RoleChanges::description('Updated purpose'),
        ));
    }

    public function test_configured_role_subclass_remains_supported(): void
    {
        config()->set(
            'gatetrail.models.role',
            UpdateHandlerCustomRole::class,
        );

        $result = app(UpdateRoleHandler::class)->handle($this->command(
            changes: RoleChanges::description('Updated purpose'),
        ));

        $this->assertInstanceOf(
            UpdateHandlerCustomRole::class,
            $result->resource,
        );
        $this->assertSame(MutationOutcome::Updated, $result->outcome);
        $this->assertNotNull($result->auditId);
    }

    private function assertUnchangedState(): void
    {
        $persisted = $this->role->fresh();

        $this->assertSame(
            'Reviews documents.',
            $persisted?->getAttribute('description'),
        );
        $this->assertSame(
            '2026-07-28 09:00:00',
            $persisted?->getAttribute('updated_at')
                ?->format('Y-m-d H:i:s'),
        );
        $this->assertDatabaseCount('acl_access_audits', 0);
    }

    private function audit(int|string|null $id): object
    {
        $audit = $this->app['db']
            ->table('acl_access_audits')
            ->where('id', $id)
            ->first();

        $this->assertNotNull($audit);

        return $audit;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function command(
        RoleReference|string|int|null $role = null,
        ?RoleChanges $changes = null,
        Model|SystemActor|null $actor = null,
        ?string $reason = null,
        array $metadata = [],
    ): UpdateRoleCommand {
        return UpdateRoleCommand::capture(
            role: $role ?? (int) $this->role->getKey(),
            changes: $changes ?? RoleChanges::description(null),
            actor: $actor ?? SystemActor::named('definition-suite'),
            reason: $reason,
            metadata: $metadata,
            clock: new FrozenClock(
                new DateTimeImmutable('2026-07-29 12:00:00 UTC'),
            ),
        );
    }
}

final class MissingRoleUpdateAccessAudit extends AccessAudit
{
    public function getTable(): string
    {
        return 'missing_role_update_access_audits';
    }
}

final class UpdateHandlerCustomRole extends Role {}

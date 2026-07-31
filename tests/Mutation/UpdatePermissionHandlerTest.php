<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use DateTimeImmutable;
use HangTuah\GateTrail\Audit\SensitiveMetadataRedactor;
use HangTuah\GateTrail\Contracts\AssignmentManager;
use HangTuah\GateTrail\Exceptions\AuditWriteFailed;
use HangTuah\GateTrail\Exceptions\InvalidActor;
use HangTuah\GateTrail\Exceptions\InvalidAuditMetadata;
use HangTuah\GateTrail\Exceptions\InvalidGuard;
use HangTuah\GateTrail\Exceptions\PermissionNotFound;
use HangTuah\GateTrail\Exceptions\ReasonRequired;
use HangTuah\GateTrail\Models\AccessAudit;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\Mutations\Commands\UpdatePermissionCommand;
use HangTuah\GateTrail\Mutations\Handlers\UpdatePermissionHandler;
use HangTuah\GateTrail\Mutations\MutationReasonValidator;
use HangTuah\GateTrail\PermissionChanges;
use HangTuah\GateTrail\PermissionReference;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Fixtures\ForcedRollback;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;

final class UpdatePermissionHandlerTest extends TestCase
{
    private const BEFORE_SNAPSHOT = '{"id":"1","name":"documents.approve",'
        .'"guard_name":"web","description":"Approve submitted documents",'
        .'"deleted_at":null}';

    private Permission $permission;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);
        $this->permission = Permission::query()->create([
            'name' => 'documents.approve',
            'guard_name' => 'web',
            'description' => 'Approve submitted documents',
        ]);
        $this->permission->forceFill([
            'created_at' => '2026-07-28 09:00:00',
            'updated_at' => '2026-07-28 09:00:00',
        ])->save();
    }

    public function test_handler_updates_description_with_canonical_audit_evidence(): void
    {
        $result = app(UpdatePermissionHandler::class)->handle($this->command(
            changes: PermissionChanges::description(
                'Approve or return submitted documents',
            ),
            reason: 'Clarify permission purpose',
            metadata: ['ticket' => 'SEC-42'],
        ));

        $this->assertSame(MutationOutcome::Updated, $result->outcome);
        $this->assertTrue($result->changed());
        $this->assertNotNull($result->auditId);
        $this->assertSame(
            'Approve or return submitted documents',
            $result->resource->getAttribute('description'),
        );
        $this->assertSame(
            '2026-07-29 12:00:00',
            $result->resource->getAttribute('updated_at')
                ?->format('Y-m-d H:i:s'),
        );

        $audit = $this->audit($result->auditId);

        $this->assertSame('permission.updated', $audit->event);
        $this->assertSame('permission', $audit->target_type);
        $this->assertSame(
            (string) $this->permission->getKey(),
            (string) $audit->target_id,
        );
        $this->assertNull($audit->subject_type);
        $this->assertNull($audit->subject_id);
        $this->assertSame('gatetrail.system', $audit->actor_type);
        $this->assertSame('definition-suite', $audit->actor_id);
        $this->assertSame('Clarify permission purpose', $audit->reason);
        $this->assertSame(self::BEFORE_SNAPSHOT, $audit->before);
        $this->assertSame(
            '{"id":"1","name":"documents.approve","guard_name":"web",'
            .'"description":"Approve or return submitted documents",'
            .'"deleted_at":null}',
            $audit->after,
        );
        $this->assertSame(
            '{"schema_version":1,"guard_name":"web",'
            .'"permission_name":"documents.approve",'
            .'"metadata":{"ticket":"SEC-42"}}',
            $audit->context,
        );
    }

    public function test_only_the_description_differs_between_before_and_after(): void
    {
        $result = app(UpdatePermissionHandler::class)->handle($this->command(
            changes: PermissionChanges::description('Approve documents'),
        ));
        $audit = $this->audit($result->auditId);
        $before = $this->decode((string) $audit->before);
        $after = $this->decode((string) $audit->after);
        $order = ['id', 'name', 'guard_name', 'description', 'deleted_at'];

        $this->assertSame($order, array_keys($before));
        $this->assertSame($order, array_keys($after));

        foreach (['id', 'name', 'guard_name', 'deleted_at'] as $field) {
            $this->assertSame(
                $before[$field],
                $after[$field],
                "Field [$field] must not change.",
            );
        }

        $this->assertSame('Approve submitted documents', $before['description']);
        $this->assertSame('Approve documents', $after['description']);
        $this->assertNull($after['deleted_at']);
    }

    public function test_explicit_null_clears_the_persisted_description(): void
    {
        $result = app(UpdatePermissionHandler::class)->handle($this->command(
            changes: PermissionChanges::description(null),
        ));

        $this->assertSame(MutationOutcome::Updated, $result->outcome);
        $this->assertNull($result->resource->getAttribute('description'));
        $this->assertNull(
            $this->permission->fresh()?->getAttribute('description'),
        );
        $this->assertStringContainsString(
            '"description":null',
            (string) $this->audit($result->auditId)->after,
        );
    }

    public function test_explicit_empty_string_is_persisted(): void
    {
        $result = app(UpdatePermissionHandler::class)->handle($this->command(
            changes: PermissionChanges::description(''),
        ));

        $this->assertSame(MutationOutcome::Updated, $result->outcome);
        $this->assertSame(
            '',
            $this->permission->fresh()?->getAttribute('description'),
        );
        $this->assertStringContainsString(
            '"description":""',
            (string) $this->audit($result->auditId)->after,
        );
    }

    public function test_whitespace_is_persisted_without_trimming(): void
    {
        $result = app(UpdatePermissionHandler::class)->handle($this->command(
            changes: PermissionChanges::description('  Approve.  '),
        ));

        $this->assertSame(
            '  Approve.  ',
            $this->permission->fresh()?->getAttribute('description'),
        );
        $this->assertStringContainsString(
            '"description":"  Approve.  "',
            (string) $this->audit($result->auditId)->after,
        );
    }

    public function test_name_guard_and_deletion_state_remain_immutable(): void
    {
        app(UpdatePermissionHandler::class)->handle($this->command(
            changes: PermissionChanges::description('Approve documents'),
        ));
        $persisted = $this->permission->fresh();

        $this->assertSame(
            'documents.approve',
            $persisted?->getAttribute('name'),
        );
        $this->assertSame('web', $persisted?->getAttribute('guard_name'));
        $this->assertNull($persisted?->getAttribute('deleted_at'));
        $this->assertSame(
            '2026-07-28 09:00:00',
            $persisted?->getAttribute('created_at')
                ?->format('Y-m-d H:i:s'),
        );
    }

    public function test_identical_descriptions_return_unchanged_without_audit(): void
    {
        $connection = $this->app['db']->connection('testing');
        $statements = [];
        $connection->beforeExecuting(
            static function (string $query) use (&$statements): void {
                $statements[] = $query;
            },
        );
        $cases = [
            'non-null' => 'Approve submitted documents',
            'null' => null,
            'empty string' => '',
        ];

        foreach ($cases as $label => $description) {
            // Seeding through the query builder keeps Eloquent's timestamp
            // handling out of the fixture, so `updated_at` is only ever
            // rewritten by the code under test.
            $connection->table('acl_permissions')
                ->where('id', $this->permission->getKey())
                ->update([
                    'description' => $description,
                    'updated_at' => '2026-07-28 09:00:00',
                ]);
            $statements = [];

            $result = app(UpdatePermissionHandler::class)->handle(
                $this->command(
                    changes: PermissionChanges::description($description),
                ),
            );
            $executed = $statements;

            $this->assertSame(
                MutationOutcome::Unchanged,
                $result->outcome,
                "An identical $label description must not change state.",
            );
            $this->assertNull($result->auditId);
            $this->assertFalse($result->changed());
            $this->assertSame(
                $this->permission->getKey(),
                $result->resource->getKey(),
            );
            $this->assertDatabaseCount('acl_access_audits', 0);
            $this->assertSame(
                '2026-07-28 09:00:00',
                $this->permission->fresh()?->getAttribute('updated_at')
                    ?->format('Y-m-d H:i:s'),
            );

            // The locked select is the only statement the unchanged path may
            // issue: no update, and no audit insert.
            foreach ($executed as $statement) {
                $this->assertStringStartsWith('select', $statement);
            }
        }
    }

    public function test_human_actor_and_null_reason_are_recorded(): void
    {
        $actor = TestSubject::query()->create(['name' => 'Admin']);

        $result = app(UpdatePermissionHandler::class)->handle($this->command(
            changes: PermissionChanges::description('Approve documents'),
            actor: $actor,
        ));
        $audit = $this->audit($result->auditId);

        $this->assertSame($actor->getMorphClass(), $audit->actor_type);
        $this->assertSame((string) $actor->getKey(), $audit->actor_id);
        $this->assertNull($audit->reason);
    }

    public function test_blank_reason_normalizes_to_null_when_not_required(): void
    {
        $result = app(UpdatePermissionHandler::class)->handle($this->command(
            changes: PermissionChanges::description('Approve documents'),
            reason: '   ',
        ));

        $this->assertNull($this->audit($result->auditId)->reason);
    }

    public function test_sensitive_reason_is_redacted_in_the_audit(): void
    {
        config()->set('gatetrail.sensitive_fields', ['reason']);

        $result = app(UpdatePermissionHandler::class)->handle($this->command(
            changes: PermissionChanges::description('Approve documents'),
            reason: 'Confidential justification',
        ));

        $this->assertSame(
            SensitiveMetadataRedactor::MARKER,
            $this->audit($result->auditId)->reason,
        );
    }

    public function test_required_definition_reason_fails_before_transaction(): void
    {
        config()->set('gatetrail.definitions.require_reason', true);
        app()->forgetInstance(MutationReasonValidator::class);

        foreach ([null, '   '] as $reason) {
            try {
                app(UpdatePermissionHandler::class)->handle($this->command(
                    changes: PermissionChanges::description(
                        'Approve documents',
                    ),
                    reason: $reason,
                ));

                $this->fail('A missing definition reason should be rejected.');
            } catch (ReasonRequired) {
                $this->assertUnchangedState();
            }
        }
    }

    public function test_invalid_description_fails_before_transaction(): void
    {
        $connection = $this->app['db']->connection('testing');
        $statements = [];
        $connection->beforeExecuting(
            static function (string $query) use (&$statements): void {
                $statements[] = $query;
            },
        );

        try {
            // PermissionChanges guards the public path, so the handler is
            // driven with a raw invalid value to prove it validates too.
            app(UpdatePermissionHandler::class)->handle($this->command(
                changes: $this->rawChanges("\xB1\x31"),
            ));

            $this->fail('A malformed description should be rejected.');
        } catch (InvalidArgumentException $exception) {
            $executed = $statements;

            $this->assertStringContainsString(
                'must be valid UTF-8',
                $exception->getMessage(),
            );
            $this->assertUnchangedState();

            foreach ($executed as $statement) {
                $this->assertStringNotContainsString(
                    'acl_permissions',
                    $statement,
                );
            }
        }
    }

    public function test_invalid_metadata_fails_before_transaction(): void
    {
        try {
            app(UpdatePermissionHandler::class)->handle($this->command(
                changes: PermissionChanges::description('Approve documents'),
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
            app(UpdatePermissionHandler::class)->handle($this->command(
                changes: PermissionChanges::description('Approve documents'),
                actor: new TestSubject,
            ));

            $this->fail('An unpersisted actor should be rejected.');
        } catch (InvalidActor) {
            $this->assertUnchangedState();
        }
    }

    public function test_every_accepted_input_form_resolves_the_permission(): void
    {
        $handler = app(UpdatePermissionHandler::class);
        $inputs = [
            'model' => $this->permission,
            'integer' => (int) $this->permission->getKey(),
            'reference' => PermissionReference::id(
                $this->permission->getKey(),
            ),
            'name' => 'documents.approve',
        ];

        foreach ($inputs as $label => $input) {
            $result = $handler->handle($this->command(
                permission: $input,
                changes: PermissionChanges::description(
                    "Updated through $label",
                ),
            ));

            $this->assertSame(
                MutationOutcome::Updated,
                $result->outcome,
                "Input [$label] should resolve the permission.",
            );
            $this->assertSame(
                "Updated through $label",
                $this->permission->fresh()?->getAttribute('description'),
            );
        }

        $this->assertDatabaseCount('acl_access_audits', count($inputs));
    }

    public function test_explicit_inputs_work_when_the_default_guard_is_invalid(): void
    {
        $apiPermission = Permission::query()->create([
            'name' => 'documents.reject',
            'guard_name' => 'api',
            'description' => null,
        ]);
        config()->set('gatetrail.guards.default', ' ');

        $result = app(UpdatePermissionHandler::class)->handle($this->command(
            permission: PermissionReference::id($apiPermission->getKey()),
            changes: PermissionChanges::description('Reject documents'),
        ));

        $this->assertSame(MutationOutcome::Updated, $result->outcome);
        $this->assertSame(
            'api',
            $result->resource->getAttribute('guard_name'),
        );
        $this->assertSame(
            '{"schema_version":1,"guard_name":"api",'
            .'"permission_name":"documents.reject","metadata":{}}',
            $this->audit($result->auditId)->context,
        );
    }

    public function test_string_name_input_requires_a_valid_default_guard(): void
    {
        config()->set('gatetrail.guards.default', ' ');

        try {
            app(UpdatePermissionHandler::class)->handle($this->command(
                permission: 'documents.approve',
                changes: PermissionChanges::description('Approve documents'),
            ));

            $this->fail('An invalid default guard should be rejected.');
        } catch (InvalidGuard $exception) {
            $this->assertSame(
                'permission definition',
                $exception->contextType,
            );
            $this->assertUnchangedState();
        }
    }

    public function test_missing_permission_is_rejected(): void
    {
        try {
            app(UpdatePermissionHandler::class)->handle($this->command(
                permission: PermissionReference::id(4242),
                changes: PermissionChanges::description('Approve documents'),
            ));

            $this->fail('A missing permission should be rejected.');
        } catch (PermissionNotFound $exception) {
            $this->assertNull($exception->guard);
            $this->assertSame('ID', $exception->referenceType);
            $this->assertUnchangedState();
        }
    }

    public function test_deactivated_permission_is_rejected(): void
    {
        $this->permission->delete();

        try {
            app(UpdatePermissionHandler::class)->handle($this->command(
                changes: PermissionChanges::description('Approve documents'),
            ));

            $this->fail('A deactivated permission should be rejected.');
        } catch (PermissionNotFound $exception) {
            $this->assertSame('web', $exception->guard);
            $this->assertSame(
                'Approve submitted documents',
                Permission::withTrashed()->sole()->getAttribute('description'),
            );
            $this->assertDatabaseCount('acl_access_audits', 0);
        }
    }

    public function test_malformed_explicit_identifier_is_rejected_safely(): void
    {
        try {
            app(UpdatePermissionHandler::class)->handle($this->command(
                permission: PermissionReference::id('not-an-integer'),
                changes: PermissionChanges::description('Approve documents'),
            ));

            $this->fail('A malformed identifier should be rejected.');
        } catch (PermissionNotFound $exception) {
            $this->assertNull($exception->guard);
            $this->assertStringNotContainsString(
                'not-an-integer',
                $exception->getMessage(),
            );
            $this->assertUnchangedState();
        }
    }

    public function test_handler_requires_strict_auditing(): void
    {
        foreach (['gatetrail.audit.enabled', 'gatetrail.audit.strict'] as $key) {
            config()->set('gatetrail.audit.enabled', true);
            config()->set('gatetrail.audit.strict', true);
            config()->set($key, false);

            try {
                app(UpdatePermissionHandler::class)->handle($this->command(
                    changes: PermissionChanges::description(
                        'Approve documents',
                    ),
                ));

                $this->fail('Strict auditing should be required.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString(
                    'requires strict auditing',
                    $exception->getMessage(),
                );
                $this->assertUnchangedState();
            }
        }
    }

    public function test_audit_failure_restores_the_original_description(): void
    {
        config()->set(
            'gatetrail.models.access_audit',
            MissingPermissionUpdateAccessAudit::class,
        );

        try {
            app(UpdatePermissionHandler::class)->handle($this->command(
                changes: PermissionChanges::description('Approve documents'),
            ));

            $this->fail('Audit persistence should fail.');
        } catch (AuditWriteFailed) {
            $this->assertUnchangedState();
        }
    }

    public function test_caller_owned_rollback_restores_the_original_description(): void
    {
        try {
            $this->app['db']->connection('testing')->transaction(
                function (): void {
                    $result = app(UpdatePermissionHandler::class)->handle(
                        $this->command(
                            changes: PermissionChanges::description(
                                'Approve documents',
                            ),
                        ),
                    );

                    $this->assertSame(
                        MutationOutcome::Updated,
                        $result->outcome,
                    );
                    $this->assertSame(
                        'Approve documents',
                        $this->permission->fresh()
                            ?->getAttribute('description'),
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

    public function test_configured_permission_subclass_remains_supported(): void
    {
        config()->set(
            'gatetrail.models.permission',
            UpdateHandlerCustomPermission::class,
        );

        $result = app(UpdatePermissionHandler::class)->handle($this->command(
            changes: PermissionChanges::description('Approve documents'),
        ));

        $this->assertInstanceOf(
            UpdateHandlerCustomPermission::class,
            $result->resource,
        );
        $this->assertSame(MutationOutcome::Updated, $result->outcome);
        $this->assertNotNull($result->auditId);
    }

    public function test_updated_permission_remains_usable_by_assignment_grants(): void
    {
        app(UpdatePermissionHandler::class)->handle($this->command(
            changes: PermissionChanges::description('Approve documents'),
        ));
        $subject = TestSubject::query()->create(['name' => 'Reviewer']);
        $role = Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'web',
            'description' => null,
        ]);
        $assignments = app(AssignmentManager::class);

        $direct = $assignments->grantDirectPermission(
            subject: $subject,
            permission: $this->permission,
            actor: SystemActor::named('definition-suite'),
            reason: 'Grant approval capability',
        );
        $viaRole = $assignments->grantPermissionToRole(
            role: $role,
            permission: 'documents.approve',
            actor: SystemActor::named('definition-suite'),
            reason: 'Grant approval capability',
        );

        $this->assertSame(MutationOutcome::Created, $direct->outcome);
        $this->assertSame(MutationOutcome::Created, $viaRole->outcome);
        $this->assertSame(
            $this->permission->getKey(),
            $direct->resource->getAttribute('permission_id'),
        );
        $this->assertSame(
            $this->permission->getKey(),
            $viaRole->resource->getAttribute('permission_id'),
        );
    }

    private function assertUnchangedState(): void
    {
        $persisted = $this->permission->fresh();

        $this->assertSame(
            'Approve submitted documents',
            $persisted?->getAttribute('description'),
        );
        $this->assertSame(
            '2026-07-28 09:00:00',
            $persisted?->getAttribute('updated_at')
                ?->format('Y-m-d H:i:s'),
        );
        $this->assertDatabaseCount('acl_access_audits', 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $snapshot): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * Builds a changes object carrying a value the public factory rejects, so
     * the handler's own validation stays observable rather than shadowed by
     * the value object's guard.
     */
    private function rawChanges(string $description): PermissionChanges
    {
        $changes = unserialize(sprintf(
            'O:%d:"%s":1:{s:11:"description";s:%d:"%s";}',
            strlen(PermissionChanges::class),
            PermissionChanges::class,
            strlen($description),
            $description,
        ));

        $this->assertInstanceOf(PermissionChanges::class, $changes);
        $this->assertSame($description, $changes->description);

        return $changes;
    }

    private function audit(int|string|null $id): object
    {
        $audit = $this->app['db']
            ->table('acl_access_audits')
            ->where('id', $id)
            ->first();

        $this->assertIsObject($audit);

        return $audit;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function command(
        PermissionReference|Model|string|int|null $permission = null,
        ?PermissionChanges $changes = null,
        Model|SystemActor|null $actor = null,
        ?string $reason = null,
        array $metadata = [],
    ): UpdatePermissionCommand {
        return UpdatePermissionCommand::capture(
            permission: $permission ?? (int) $this->permission->getKey(),
            changes: $changes ?? PermissionChanges::description(null),
            actor: $actor ?? SystemActor::named('definition-suite'),
            reason: $reason,
            metadata: $metadata,
            clock: new FrozenClock(
                new DateTimeImmutable('2026-07-29 12:00:00 UTC'),
            ),
        );
    }
}

final class MissingPermissionUpdateAccessAudit extends AccessAudit
{
    public function getTable(): string
    {
        return 'missing_permission_update_access_audits';
    }
}

final class UpdateHandlerCustomPermission extends Permission {}

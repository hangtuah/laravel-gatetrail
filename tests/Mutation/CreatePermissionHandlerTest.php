<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use DateTimeImmutable;
use HangTuah\GateTrail\Audit\SensitiveMetadataRedactor;
use HangTuah\GateTrail\Contracts\AssignmentManager;
use HangTuah\GateTrail\Exceptions\AuditWriteFailed;
use HangTuah\GateTrail\Exceptions\DuplicatePermission;
use HangTuah\GateTrail\Exceptions\InvalidActor;
use HangTuah\GateTrail\Exceptions\InvalidAuditMetadata;
use HangTuah\GateTrail\Exceptions\ReasonRequired;
use HangTuah\GateTrail\Models\AccessAudit;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\Mutations\Commands\CreatePermissionCommand;
use HangTuah\GateTrail\Mutations\Handlers\CreatePermissionHandler;
use HangTuah\GateTrail\Mutations\MutationReasonValidator;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Fixtures\ForcedRollback;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

final class CreatePermissionHandlerTest extends TestCase
{
    private const AFTER_SNAPSHOT = '{"id":"1","name":"documents.approve",'
        .'"guard_name":"web","description":"Approve submitted documents",'
        .'"deleted_at":null}';

    private const CONTEXT = '{"schema_version":1,"guard_name":"web",'
        .'"permission_name":"documents.approve",'
        .'"metadata":{"ticket":"SEC-42"}}';

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);
    }

    public function test_handler_creates_permission_with_canonical_audit_evidence(): void
    {
        $result = app(CreatePermissionHandler::class)->handle($this->command(
            reason: 'Introduce approval capability',
            description: 'Approve submitted documents',
            metadata: ['ticket' => 'SEC-42'],
        ));

        $this->assertSame(MutationOutcome::Created, $result->outcome);
        $this->assertTrue($result->changed());
        $this->assertNotNull($result->auditId);
        $this->assertSame(
            'documents.approve',
            $result->resource->getAttribute('name'),
        );
        $this->assertSame(
            'web',
            $result->resource->getAttribute('guard_name'),
        );
        $this->assertSame(
            'Approve submitted documents',
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

        $audit = $this->audit($result->auditId);

        $this->assertSame('permission.created', $audit->event);
        $this->assertSame('permission', $audit->target_type);
        $this->assertSame(
            (string) $result->resource->getKey(),
            (string) $audit->target_id,
        );
        $this->assertNull($audit->subject_type);
        $this->assertNull($audit->subject_id);
        $this->assertSame('gatetrail.system', $audit->actor_type);
        $this->assertSame('definition-suite', $audit->actor_id);
        $this->assertSame(
            'Introduce approval capability',
            $audit->reason,
        );
        $this->assertNull($audit->before);
        $this->assertSame(self::AFTER_SNAPSHOT, $audit->after);
        $this->assertSame(self::CONTEXT, $audit->context);
    }

    public function test_name_and_guard_are_trimmed_without_case_rewriting(): void
    {
        $result = app(CreatePermissionHandler::class)->handle(
            CreatePermissionCommand::capture(
                name: '  Documents.Approve  ',
                guard: '  Web  ',
                actor: SystemActor::named('definition-suite'),
                reason: null,
                description: null,
                metadata: [],
                clock: $this->clock(),
            ),
        );

        $this->assertSame(
            'Documents.Approve',
            $result->resource->getAttribute('name'),
        );
        $this->assertSame('Web', $result->resource->getAttribute('guard_name'));
        $this->assertDatabaseHas('acl_permissions', [
            'name' => 'Documents.Approve',
            'guard_name' => 'Web',
        ]);
    }

    public function test_numeric_looking_name_remains_a_name(): void
    {
        $result = app(CreatePermissionHandler::class)->handle(
            CreatePermissionCommand::capture(
                name: '123',
                guard: 'web',
                actor: SystemActor::named('definition-suite'),
                reason: null,
                description: null,
                metadata: [],
                clock: $this->clock(),
            ),
        );

        $this->assertSame('123', $result->resource->getAttribute('name'));
    }

    public function test_description_supports_null_string_and_empty_values(): void
    {
        $handler = app(CreatePermissionHandler::class);
        $cases = [
            'documents.approve' => null,
            'documents.reject' => 'Reject submitted documents',
            'documents.archive' => '',
        ];

        foreach ($cases as $name => $description) {
            $result = $handler->handle(
                CreatePermissionCommand::capture(
                    name: $name,
                    guard: 'web',
                    actor: SystemActor::named('definition-suite'),
                    reason: null,
                    description: $description,
                    metadata: [],
                    clock: $this->clock(),
                ),
            );

            $this->assertSame(
                $description,
                $result->resource->getAttribute('description'),
                "Permission [$name] should keep its description verbatim.",
            );
            $this->assertSame(
                $description,
                Permission::query()
                    ->whereKey($result->resource->getKey())
                    ->sole()
                    ->getAttribute('description'),
            );
        }
    }

    public function test_untrimmed_description_is_preserved_verbatim(): void
    {
        $result = app(CreatePermissionHandler::class)->handle(
            $this->command(description: '  Approve.  '),
        );

        $this->assertSame(
            '  Approve.  ',
            $result->resource->getAttribute('description'),
        );
    }

    public function test_human_actor_and_null_reason_are_recorded(): void
    {
        $actor = TestSubject::query()->create(['name' => 'Admin']);

        $result = app(CreatePermissionHandler::class)->handle(
            $this->command(actor: $actor),
        );
        $audit = $this->audit($result->auditId);

        $this->assertSame(MutationOutcome::Created, $result->outcome);
        $this->assertSame($actor->getMorphClass(), $audit->actor_type);
        $this->assertSame((string) $actor->getKey(), $audit->actor_id);
        $this->assertNull($audit->reason);
    }

    public function test_blank_reason_normalizes_to_null_when_not_required(): void
    {
        $result = app(CreatePermissionHandler::class)->handle(
            $this->command(reason: '   '),
        );

        $this->assertNull($this->audit($result->auditId)->reason);
    }

    public function test_required_definition_reason_rejects_blank_before_transaction(): void
    {
        config()->set('gatetrail.definitions.require_reason', true);
        app()->forgetInstance(MutationReasonValidator::class);

        foreach ([null, '   '] as $reason) {
            try {
                app(CreatePermissionHandler::class)->handle(
                    $this->command(reason: $reason),
                );

                $this->fail('A missing definition reason should be rejected.');
            } catch (ReasonRequired) {
                $this->assertDatabaseCount('acl_permissions', 0);
                $this->assertDatabaseCount('acl_access_audits', 0);
            }
        }
    }

    public function test_sensitive_reason_is_redacted_in_the_audit(): void
    {
        config()->set('gatetrail.sensitive_fields', ['reason']);

        $result = app(CreatePermissionHandler::class)->handle(
            $this->command(reason: 'Confidential justification'),
        );

        $this->assertSame(
            SensitiveMetadataRedactor::MARKER,
            $this->audit($result->auditId)->reason,
        );
    }

    public function test_invalid_definition_inputs_are_rejected_before_the_transaction(): void
    {
        $connection = $this->app['db']->connection('testing');
        $statements = [];
        $connection->beforeExecuting(
            static function (string $query) use (&$statements): void {
                $statements[] = $query;
            },
        );
        $cases = [
            'blank name' => ['name' => '   '],
            'oversized name' => ['name' => str_repeat('a', 192)],
            'malformed name' => ['name' => "documents.\xB1\x31"],
            'blank guard' => ['guard' => ' '],
            'oversized guard' => ['guard' => str_repeat('g', 192)],
            'malformed guard' => ['guard' => "we\xB1\x31b"],
            'malformed description' => ['description' => "\xB1\x31"],
        ];

        $rejected = 0;

        foreach ($cases as $label => $case) {
            try {
                app(CreatePermissionHandler::class)->handle(
                    CreatePermissionCommand::capture(
                        name: $case['name'] ?? 'documents.approve',
                        guard: $case['guard'] ?? 'web',
                        actor: SystemActor::named('definition-suite'),
                        reason: null,
                        description: $case['description'] ?? null,
                        metadata: [],
                        clock: $this->clock(),
                    ),
                );

                $this->fail("A $label should be rejected.");
            } catch (InvalidArgumentException) {
                $rejected++;
            }
        }

        // Snapshot before asserting, because the assertions below query the
        // permission table themselves.
        $executed = $statements;

        $this->assertSame(count($cases), $rejected);
        $this->assertDatabaseCount('acl_permissions', 0);
        $this->assertDatabaseCount('acl_access_audits', 0);

        // No statement ever touched the permission table, so the collision
        // pre-check — the first statement inside the transaction — never ran.
        foreach ($executed as $statement) {
            $this->assertStringNotContainsString(
                'acl_permissions',
                $statement,
            );
        }
    }

    public function test_invalid_metadata_is_rejected_before_transaction(): void
    {
        try {
            app(CreatePermissionHandler::class)->handle($this->command(
                metadata: ['file' => fopen('php://memory', 'r')],
            ));

            $this->fail('Invalid metadata should be rejected.');
        } catch (InvalidAuditMetadata) {
            $this->assertDatabaseCount('acl_permissions', 0);
            $this->assertDatabaseCount('acl_access_audits', 0);
        }
    }

    public function test_invalid_actor_is_rejected_before_transaction(): void
    {
        try {
            app(CreatePermissionHandler::class)->handle(
                $this->command(actor: new TestSubject),
            );

            $this->fail('An actor without a key should be rejected.');
        } catch (InvalidActor) {
            $this->assertDatabaseCount('acl_permissions', 0);
            $this->assertDatabaseCount('acl_access_audits', 0);
        }
    }

    public function test_duplicate_active_permission_is_rejected(): void
    {
        $handler = app(CreatePermissionHandler::class);
        $handler->handle($this->command());

        try {
            $handler->handle($this->command());

            $this->fail('A duplicate active permission should be rejected.');
        } catch (DuplicatePermission $exception) {
            $this->assertSafeContext($exception);
            $this->assertDatabaseCount('acl_permissions', 1);
            $this->assertDatabaseCount('acl_access_audits', 1);
        }
    }

    public function test_duplicate_deactivated_permission_is_rejected_without_restore(): void
    {
        $handler = app(CreatePermissionHandler::class);
        $created = $handler->handle($this->command());
        $permission = Permission::query()
            ->whereKey($created->resource->getKey())
            ->sole();
        $permission->delete();

        try {
            $handler->handle($this->command());

            $this->fail('A deactivated namespace should be rejected.');
        } catch (DuplicatePermission $exception) {
            $this->assertSafeContext($exception);

            $stored = Permission::query()
                ->withTrashed()
                ->whereKey($permission->getKey())
                ->sole();

            $this->assertNotNull($stored->getAttribute('deleted_at'));
            $this->assertSame($permission->getKey(), $stored->getKey());
            $this->assertSame(1, Permission::withTrashed()->count());
            $this->assertDatabaseCount('acl_access_audits', 1);
        }
    }

    public function test_a_different_guard_keeps_the_same_name_available(): void
    {
        $handler = app(CreatePermissionHandler::class);
        $handler->handle($this->command());

        $result = $handler->handle(
            CreatePermissionCommand::capture(
                name: 'documents.approve',
                guard: 'api',
                actor: SystemActor::named('definition-suite'),
                reason: null,
                description: null,
                metadata: [],
                clock: $this->clock(),
            ),
        );

        $this->assertSame(MutationOutcome::Created, $result->outcome);
        $this->assertSame(2, Permission::query()->count());
    }

    public function test_namespace_unique_violation_translates_to_duplicate_permission(): void
    {
        // The colliding row appears after the locked pre-check, so only the
        // database unique constraint can still reject the insert.
        Permission::creating(function (): void {
            $this->app['db']->table('acl_permissions')->insert([
                'name' => 'documents.approve',
                'guard_name' => 'web',
                'description' => null,
                'created_at' => '2026-07-29 12:00:00',
                'updated_at' => '2026-07-29 12:00:00',
            ]);
        });

        try {
            app(CreatePermissionHandler::class)->handle($this->command());

            $this->fail('A concurrent namespace race should be rejected.');
        } catch (DuplicatePermission $exception) {
            $this->assertSafeContext($exception);
            $this->assertDatabaseCount('acl_permissions', 0);
            $this->assertDatabaseCount('acl_access_audits', 0);
        }
    }

    public function test_unrelated_unique_violation_remains_untranslated(): void
    {
        // A primary-key collision is still a unique violation, but it is not
        // the permission namespace constraint, so it must not be translated.
        Permission::creating(function (Model $permission): void {
            $this->app['db']->table('acl_permissions')->insert([
                'id' => 4242,
                'name' => 'documents.reject',
                'guard_name' => 'web',
                'description' => null,
                'created_at' => '2026-07-29 12:00:00',
                'updated_at' => '2026-07-29 12:00:00',
            ]);
            $permission->forceFill(['id' => 4242]);
        });

        try {
            app(CreatePermissionHandler::class)->handle($this->command());

            $this->fail('A primary-key collision should surface untranslated.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString(
                'UNIQUE constraint failed',
                $exception->getMessage(),
            );
            $this->assertDatabaseCount('acl_permissions', 0);
            $this->assertDatabaseCount('acl_access_audits', 0);
        }
    }

    public function test_missing_table_failure_remains_untranslated(): void
    {
        config()->set(
            'gatetrail.models.permission',
            MissingTablePermission::class,
        );

        $this->expectException(QueryException::class);

        app(CreatePermissionHandler::class)->handle($this->command());
    }

    public function test_audit_failure_rolls_back_permission_creation(): void
    {
        config()->set(
            'gatetrail.models.access_audit',
            MissingPermissionCreateAccessAudit::class,
        );

        try {
            app(CreatePermissionHandler::class)->handle($this->command());

            $this->fail('Audit persistence should fail.');
        } catch (AuditWriteFailed) {
            $this->assertDatabaseCount('acl_permissions', 0);
            $this->assertDatabaseCount('acl_access_audits', 0);
        }
    }

    public function test_caller_owned_rollback_removes_permission_and_audit(): void
    {
        $connection = $this->app['db']->connection('testing');

        try {
            $connection->transaction(function (): void {
                $result = app(CreatePermissionHandler::class)
                    ->handle($this->command());

                $this->assertSame(
                    MutationOutcome::Created,
                    $result->outcome,
                );
                $this->assertDatabaseCount('acl_permissions', 1);
                $this->assertDatabaseCount('acl_access_audits', 1);

                throw new ForcedRollback('Roll back outer transaction.');
            });

            $this->fail('The outer transaction should roll back.');
        } catch (ForcedRollback) {
            $this->assertDatabaseCount('acl_permissions', 0);
            $this->assertDatabaseCount('acl_access_audits', 0);
        }
    }

    public function test_disabled_auditing_persists_without_an_audit(): void
    {
        config()->set('gatetrail.audit.enabled', false);

        $result = app(CreatePermissionHandler::class)->handle($this->command());

        $this->assertSame(MutationOutcome::Created, $result->outcome);
        $this->assertNull($result->auditId);
        $this->assertDatabaseCount('acl_access_audits', 0);
    }

    public function test_configured_permission_subclass_remains_supported(): void
    {
        config()->set(
            'gatetrail.models.permission',
            HandlerCustomPermission::class,
        );

        $result = app(CreatePermissionHandler::class)->handle(
            $this->command(description: 'Approve submitted documents'),
        );

        $this->assertInstanceOf(
            HandlerCustomPermission::class,
            $result->resource,
        );
        $this->assertSame(MutationOutcome::Created, $result->outcome);
        $this->assertSame(
            self::AFTER_SNAPSHOT,
            $this->audit($result->auditId)->after,
        );
    }

    public function test_created_permission_works_with_existing_assignment_grants(): void
    {
        $permission = app(CreatePermissionHandler::class)
            ->handle($this->command())
            ->resource;
        $subject = TestSubject::query()->create(['name' => 'Reviewer']);
        $role = Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'web',
            'description' => null,
        ]);
        $assignments = app(AssignmentManager::class);

        $direct = $assignments->grantDirectPermission(
            subject: $subject,
            permission: $permission,
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
            $permission->getKey(),
            $direct->resource->getAttribute('permission_id'),
        );
        $this->assertSame(
            $permission->getKey(),
            $viaRole->resource->getAttribute('permission_id'),
        );
    }

    private function assertSafeContext(DuplicatePermission $exception): void
    {
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/D',
            $exception->namespaceHash,
        );
        $this->assertSame(
            hash('sha256', (string) json_encode(['web', 'documents.approve'])),
            $exception->namespaceHash,
        );
        $this->assertStringNotContainsString(
            'documents.approve',
            $exception->getMessage(),
        );
        $this->assertStringNotContainsString(
            'select',
            strtolower($exception->getMessage()),
        );
        $this->assertNull($exception->getPrevious());
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

    private function clock(): FrozenClock
    {
        return new FrozenClock(
            new DateTimeImmutable('2026-07-29 12:00:00 UTC'),
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function command(
        Model|SystemActor|null $actor = null,
        ?string $reason = null,
        ?string $description = null,
        array $metadata = [],
    ): CreatePermissionCommand {
        return CreatePermissionCommand::capture(
            name: 'documents.approve',
            guard: 'web',
            actor: $actor ?? SystemActor::named('definition-suite'),
            reason: $reason,
            description: $description,
            metadata: $metadata,
            clock: $this->clock(),
        );
    }
}

final class MissingPermissionCreateAccessAudit extends AccessAudit
{
    public function getTable(): string
    {
        return 'missing_permission_create_access_audits';
    }
}

final class MissingTablePermission extends Permission
{
    public function getTable(): string
    {
        return 'missing_permissions';
    }
}

final class HandlerCustomPermission extends Permission {}

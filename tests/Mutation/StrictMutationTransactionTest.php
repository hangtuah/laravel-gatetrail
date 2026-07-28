<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use Carbon\CarbonImmutable;
use HangTuah\GateTrail\Audit\AccessAuditWriter;
use HangTuah\GateTrail\Audit\PendingAuditRecord;
use HangTuah\GateTrail\Exceptions\AuditWriteFailed;
use HangTuah\GateTrail\Models\AccessAudit;
use HangTuah\GateTrail\Mutations\ActorIdentity;
use HangTuah\GateTrail\Mutations\StrictMutationTransaction;
use HangTuah\GateTrail\Support\ModelRegistry;
use HangTuah\GateTrail\Tests\TestCase;
use RuntimeException;

final class StrictMutationTransactionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);
    }

    public function test_strict_transaction_commits_mutation_and_audit_together(): void
    {
        $result = app(StrictMutationTransaction::class)->run(
            function (): string {
                $this->insertRole('reviewer');
                app(AccessAuditWriter::class)->append($this->record());

                return 'committed';
            },
        );

        $this->assertSame('committed', $result);
        $this->assertDatabaseHas('acl_roles', ['name' => 'reviewer']);
        $this->assertDatabaseHas('acl_access_audits', [
            'event' => 'role.created',
        ]);
    }

    public function test_audit_failure_rolls_back_the_mutation(): void
    {
        config()->set(
            'gatetrail.models.access_audit',
            MissingTableAccessAudit::class,
        );
        app()->forgetInstance(ModelRegistry::class);
        app()->forgetInstance(AccessAuditWriter::class);
        app()->forgetInstance(StrictMutationTransaction::class);

        try {
            app(StrictMutationTransaction::class)->run(
                function (): void {
                    $this->insertRole('reviewer');
                    app(AccessAuditWriter::class)->append(
                        $this->record(),
                    );
                },
            );

            $this->fail('The strict audit failure should be thrown.');
        } catch (AuditWriteFailed) {
            $this->assertDatabaseMissing('acl_roles', [
                'name' => 'reviewer',
            ]);
            $this->assertDatabaseCount('acl_access_audits', 0);
        }
    }

    public function test_caller_owned_outer_rollback_removes_both_writes(): void
    {
        $connection = $this->app['db']->connection('testing');

        try {
            $connection->transaction(function (): void {
                app(StrictMutationTransaction::class)->run(
                    function (): void {
                        $this->insertRole('reviewer');
                        app(AccessAuditWriter::class)->append(
                            $this->record(),
                        );
                    },
                );

                throw new RuntimeException('Roll back outer transaction.');
            });
        } catch (RuntimeException) {
            $this->assertDatabaseMissing('acl_roles', [
                'name' => 'reviewer',
            ]);
            $this->assertDatabaseCount('acl_access_audits', 0);
        }
    }

    private function insertRole(string $name): void
    {
        $this->app['db']->table('acl_roles')->insert([
            'name' => $name,
            'guard_name' => 'web',
            'description' => null,
            'created_at' => '2026-07-28 12:00:00',
            'updated_at' => '2026-07-28 12:00:00',
            'deleted_at' => null,
        ]);
    }

    private function record(
        string $event = 'role.created',
    ): PendingAuditRecord {
        return new PendingAuditRecord(
            event: $event,
            actor: new ActorIdentity('gatetrail.system', 'test-suite'),
            subjectType: null,
            subjectId: null,
            targetType: 'role',
            targetId: 1,
            before: null,
            after: '{"id":"1","name":"reviewer","guard_name":"web"}',
            reason: 'Create reviewer role',
            context: '{"schema_version":1,"guard_name":"web","metadata":{}}',
            ipAddress: null,
            userAgent: null,
            requestId: null,
            createdAt: CarbonImmutable::parse(
                '2026-07-28 12:00:00',
                'UTC',
            )->toDateTimeImmutable(),
        );
    }
}

final class MissingTableAccessAudit extends AccessAudit
{
    public function getTable(): string
    {
        return 'missing_access_audits';
    }
}

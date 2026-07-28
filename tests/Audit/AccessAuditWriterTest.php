<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Audit;

use Carbon\CarbonImmutable;
use HangTuah\GateTrail\Audit\AccessAuditWriter;
use HangTuah\GateTrail\Audit\PendingAuditRecord;
use HangTuah\GateTrail\Exceptions\AuditWriteFailed;
use HangTuah\GateTrail\Models\AccessAudit;
use HangTuah\GateTrail\Mutations\ActorIdentity;
use HangTuah\GateTrail\Support\ModelRegistry;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\QueryException;
use LogicException;

final class AccessAuditWriterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);
    }

    public function test_writer_preserves_canonical_json_without_double_encoding(): void
    {
        $record = $this->record();

        $identifier = $this->app['db']
            ->connection('testing')
            ->transaction(
                fn (): int|string => app(AccessAuditWriter::class)
                    ->append($record),
            );
        $stored = $this->app['db']
            ->table('acl_access_audits')
            ->where('id', $identifier)
            ->first();

        $this->assertNotNull($stored);
        $this->assertSame($record->after, $stored->after);
        $this->assertSame($record->context, $stored->context);
        $this->assertSame('gatetrail.system', $stored->actor_type);
        $this->assertSame('test-suite', $stored->actor_id);
    }

    public function test_writer_requires_an_existing_transaction(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('inside a database transaction');

        app(AccessAuditWriter::class)->append($this->record());
    }

    public function test_database_failure_is_wrapped_without_database_message(): void
    {
        config()->set(
            'gatetrail.models.access_audit',
            MissingTableAccessAudit::class,
        );
        app()->forgetInstance(ModelRegistry::class);
        app()->forgetInstance(AccessAuditWriter::class);
        $record = $this->record();

        try {
            $this->app['db']
                ->connection('testing')
                ->transaction(
                    fn (): int|string => app(AccessAuditWriter::class)
                        ->append($record),
                );

            $this->fail('The invalid audit event should not be persisted.');
        } catch (AuditWriteFailed $exception) {
            $this->assertSame(
                'GateTrail could not persist audit event [role.assigned].',
                $exception->getMessage(),
            );
            $this->assertInstanceOf(
                QueryException::class,
                $exception->getPrevious(),
            );
            $this->assertStringNotContainsString(
                'acl_access_audits',
                $exception->getMessage(),
            );
        }
    }

    private function record(
        string $event = 'role.assigned',
    ): PendingAuditRecord {
        return new PendingAuditRecord(
            event: $event,
            actor: new ActorIdentity('gatetrail.system', 'test-suite'),
            subjectType: 'test-subject',
            subjectId: 42,
            targetType: 'role_assignment',
            targetId: 7,
            before: null,
            after: '{"id":"7","starts_at":null,"ratio":1.0}',
            reason: 'Assigned for review',
            context: '{"schema_version":1,"guard_name":"web","metadata":{}}',
            ipAddress: null,
            userAgent: null,
            requestId: 'request-42',
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

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Models;

use HangTuah\GateTrail\Models\AccessAudit;
use HangTuah\GateTrail\Tests\TestCase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

final class AccessAuditAppendOnlyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);
    }

    /**
     * @return iterable<string, array{callable(AccessAudit): mixed}>
     */
    public static function modelMutationProvider(): iterable
    {
        yield 'save' => [static function (AccessAudit $audit): void {
            $audit->event = 'role.updated';
            $audit->save();
        }];

        yield 'save quietly' => [static function (AccessAudit $audit): void {
            $audit->event = 'role.updated';
            $audit->saveQuietly();
        }];

        yield 'update' => [static fn (AccessAudit $audit): bool => $audit->update([
            'event' => 'role.updated',
        ])];

        yield 'update quietly' => [static fn (AccessAudit $audit): bool => $audit->updateQuietly([
            'event' => 'role.updated',
        ])];

        yield 'delete' => [static fn (AccessAudit $audit): ?bool => $audit->delete()];

        yield 'delete quietly' => [static fn (AccessAudit $audit): bool => $audit->deleteQuietly()];

        yield 'force delete' => [static fn (AccessAudit $audit): ?bool => $audit->forceDelete()];

        yield 'increment' => [static fn (AccessAudit $audit): mixed => $audit->increment('id')];

        yield 'increment quietly' => [static fn (AccessAudit $audit): mixed => $audit->incrementQuietly('id')];

        yield 'decrement' => [static fn (AccessAudit $audit): mixed => $audit->decrement('id')];

        yield 'decrement quietly' => [static fn (AccessAudit $audit): mixed => $audit->decrementQuietly('id')];

        yield 'increment each' => [static fn (AccessAudit $audit): mixed => $audit->incrementEach(['id' => 1])];

        yield 'decrement each' => [static fn (AccessAudit $audit): mixed => $audit->decrementEach(['id' => 1])];

        yield 'increment each quietly' => [static fn (AccessAudit $audit): mixed => $audit->incrementEachQuietly(['id' => 1])];

        yield 'decrement each quietly' => [static fn (AccessAudit $audit): mixed => $audit->decrementEachQuietly(['id' => 1])];
    }

    #[DataProvider('modelMutationProvider')]
    public function test_persisted_audit_cannot_be_changed_or_deleted_through_the_model(
        callable $mutation,
    ): void {
        $identifier = $this->insertAudit();
        $audit = AccessAudit::query()->findOrFail($identifier);

        try {
            $mutation($audit);

            $this->fail('A persisted audit mutation should be rejected.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'GateTrail access audit records are append-only and cannot be changed or deleted.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseHas('acl_access_audits', [
            'id' => $identifier,
            'event' => 'role.created',
        ]);
        $this->assertDatabaseCount('acl_access_audits', 1);
    }

    private function insertAudit(): int
    {
        return (int) $this->app['db']
            ->table('acl_access_audits')
            ->insertGetId([
                'event' => 'role.created',
                'actor_type' => 'gatetrail.system',
                'actor_id' => 'append-only-test',
                'target_type' => 'role',
                'target_id' => '1',
                'after' => '{"id":"1"}',
                'reason' => 'Regression coverage',
                'context' => '{"schema_version":1,"guard_name":"web","metadata":{}}',
                'created_at' => '2026-10-10 00:00:00',
            ]);
    }
}

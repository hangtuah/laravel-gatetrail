<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Integration\MySql;

use DateTimeImmutable;
use HangTuah\GateTrail\Access\EffectiveAccessMemo;
use HangTuah\GateTrail\Access\EffectiveAccessResolver;
use HangTuah\GateTrail\Contracts\PermissionResolver;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Diagnostics\GateTrailStatementProbe;
use HangTuah\GateTrail\Diagnostics\StatementMeasurement;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use HangTuah\GateTrail\Tests\Integration\Support\AcceptanceSubject;
use HangTuah\GateTrail\Tests\Integration\Support\ResolverAcceptanceFixture;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Psr\Clock\ClockInterface;
use ReflectionClass;

/**
 * The 900-identifier chunk boundary, crossed with real rows on a live engine.
 *
 * ADR 0012 section 12.2 asks for "role-ID chunking where practical — at least
 * one run crossing the 900-identifier bound, on whichever live engine can host
 * it economically". MySQL is that engine: the four lanes are already
 * provisioned, and 901 roles is a few batched inserts rather than a new
 * service. Oracle is deliberately left out of this one, because a second live
 * container to re-prove an engine-independent constant would buy nothing.
 *
 * The constant is the point. Chunking exists so the statement count tracks
 * **chunks**, never roles: 901 roles cost four statements, not 903. Below the
 * bound the count does not move at all.
 *
 * | Effective roles | Query-3 statements | Cold total |
 * |---|---|---|
 * | 900 | 1 | 3 |
 * | 901 | 2 | 4 |
 *
 * Counts are measured on the live connection through
 * {@see GateTrailStatementProbe}, so they are what MySQL executed rather than
 * what the resolver believed it executed.
 */
final class ResolverChunkBoundaryTest extends MySqlIntegrationTestCase
{
    private const CHUNK = 900;

    private const SUBJECT_ID = 1;

    private const SHARED_PERMISSION_ID = 990001;

    private const TAIL_PERMISSION_ID = 990002;

    /**
     * Identifiers start high enough that they cannot collide with anything the
     * other integration classes seed.
     */
    private const FIRST_ROLE_ID = 900001;

    private AcceptanceSubject $subject;

    /**
     * 901 roles: one above the bound, which is the only place the budget can
     * change and the only place it may change by exactly one.
     */
    public function test_nine_hundred_and_one_roles_cost_two_query_three_statements(): void
    {
        $probe = $this->probe();

        $this->forgetMemo();

        $measurement = $probe->measure(function (): void {
            $this->resolver()->resolve($this->subject);
        });

        $this->assertSelectOnly($measurement, 4);

        $this->forgetMemo();

        $this->assertSame(
            self::CHUNK + 1,
            $this->resolver()->resolve($this->subject)->roles()->count(),
            'All 901 roles are effective, so both chunks were populated.',
        );
    }

    /**
     * One assignment revoked leaves exactly 900 — the bound itself — and the
     * budget drops back to three. The revocation is a direct fixture write, not
     * a managed mutation, so **no `MutationCommitted` is dispatched** and the
     * memo is cleared here deliberately rather than by an event.
     */
    public function test_exactly_nine_hundred_roles_cost_one_query_three_statement(): void
    {
        $this->revokeOneAssignment();
        $this->forgetMemo();

        $probe = $this->probe();

        $measurement = $probe->measure(function (): void {
            $this->resolver()->resolve($this->subject);
        });

        $this->assertSelectOnly($measurement, 3);

        $this->forgetMemo();

        $this->assertSame(
            self::CHUNK,
            $this->resolver()->resolve($this->subject)->roles()->count(),
            'Exactly the bound, in one chunk.',
        );
    }

    /**
     * The N+1 claim, stated as a number: 901 roles produce four statements, not
     * 903 and not 901.
     */
    public function test_chunking_never_becomes_one_statement_per_role(): void
    {
        $probe = $this->probe();

        $this->forgetMemo();

        $measurement = $probe->measure(function (): void {
            $this->resolver()->resolve($this->subject);
        });

        $this->assertLessThan(
            10,
            $measurement->total,
            'A per-role query would have produced hundreds of statements.',
        );
        $this->assertSame(4, $measurement->total);
    }

    /**
     * Permissions stay correct and deduplicated across the chunk boundary: 901
     * roles all grant the same permission, and one role in the tail grants a
     * second.
     */
    public function test_permissions_remain_correct_and_deduplicated_across_chunks(): void
    {
        $this->forgetMemo();

        $names = $this->app->make(PermissionResolver::class)
            ->effectivePermissions($this->subject)
            ->map(static fn ($permission): string => $permission->name)
            ->all();

        $this->assertSame(
            ['chunk.shared', 'chunk.tail'],
            $names,
            'One entry per permission, however many chunks produced it.',
        );
    }

    /**
     * The bound is a fixed internal constant, identical on every engine. A
     * per-engine chunk size would make the budget itself engine-dependent.
     */
    public function test_the_chunk_size_is_the_same_fixed_constant_here(): void
    {
        $this->assertSame(
            self::CHUNK,
            (new ReflectionClass(EffectiveAccessResolver::class))
                ->getConstant('CANDIDATE_ROLE_CHUNK'),
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => self::CONNECTION,
        ])->assertExitCode(0);

        $this->app->instance(
            ClockInterface::class,
            new FrozenClock(ResolverAcceptanceFixture::evaluatedAt()),
        );

        $this->installSubjectTable();
        $this->seedChunkFixture();

        $subject = AcceptanceSubject::query()->find(self::SUBJECT_ID);

        $this->assertInstanceOf(AcceptanceSubject::class, $subject);

        $this->subject = $subject;
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) {
            $this->schema()->dropIfExists(AcceptanceSubject::TABLE);
        }

        parent::tearDown();
    }

    private function installSubjectTable(): void
    {
        $this->schema()->dropIfExists(AcceptanceSubject::TABLE);
        $this->schema()->create(
            AcceptanceSubject::TABLE,
            static function (Blueprint $table): void {
                $table->unsignedBigInteger('id');
                $table->string('name', 100);
                $table->primary('id');
            },
        );

        $this->connection()->table(AcceptanceSubject::TABLE)->insert([
            'id' => self::SUBJECT_ID,
            'name' => 'Chunk Subject',
        ]);
    }

    /**
     * 901 roles, 901 assignments and 902 grants, inserted in batches.
     *
     * Batched because a per-row save would dominate the lane's runtime while
     * testing nothing the batch does not.
     */
    private function seedChunkFixture(): void
    {
        $context = $this->app->make(SchemaContext::class);
        $assignedAt = ResolverAcceptanceFixture::evaluatedAt()->modify('-1 hour');
        $morphClass = (new AcceptanceSubject)->getMorphClass();

        $this->connection()->table($context->table('permissions'))->insert([
            $this->definition(self::SHARED_PERMISSION_ID, 'chunk.shared', $assignedAt),
            $this->definition(self::TAIL_PERMISSION_ID, 'chunk.tail', $assignedAt),
        ]);

        $roles = [];
        $assignments = [];
        $grants = [];

        for ($index = 0; $index <= self::CHUNK; $index++) {
            $roleId = self::FIRST_ROLE_ID + $index;

            $roles[] = $this->definition(
                $roleId,
                sprintf('chunk-role-%04d', $index),
                $assignedAt,
            );
            $assignments[] = [
                'subject_type' => $morphClass,
                'subject_id' => self::SUBJECT_ID,
                'role_id' => $roleId,
                ...$this->period($assignedAt),
            ];
            $grants[] = [
                'role_id' => $roleId,
                'permission_id' => self::SHARED_PERMISSION_ID,
                ...$this->period($assignedAt),
            ];
        }

        // One role also grants a second permission, so a chunk that was
        // dropped entirely would change the answer and not merely the count.
        $grants[] = [
            'role_id' => self::FIRST_ROLE_ID + self::CHUNK,
            'permission_id' => self::TAIL_PERMISSION_ID,
            ...$this->period($assignedAt),
        ];

        $this->insertBatched($context->table('roles'), $roles);
        $this->insertBatched($context->table('role_assignments'), $assignments);
        $this->insertBatched($context->table('role_permissions'), $grants);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function insertBatched(string $table, array $rows): void
    {
        foreach (array_chunk($rows, 250) as $batch) {
            $this->connection()->table($table)->insert($batch);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(
        int $id,
        string $name,
        DateTimeImmutable $at,
    ): array {
        return [
            'id' => $id,
            'name' => $name,
            'guard_name' => ResolverAcceptanceFixture::GUARD,
            'description' => null,
            'created_at' => $at,
            'updated_at' => $at,
            'deleted_at' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function period(DateTimeImmutable $at): array
    {
        return [
            'assigned_at' => $at,
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'acceptance',
            'assignment_reason' => null,
            'starts_at' => null,
            'expires_at' => null,
            'revoked_at' => null,
            'revoked_by_type' => null,
            'revoked_by_id' => null,
            'revocation_reason' => null,
            'created_at' => $at,
            'updated_at' => $at,
        ];
    }

    private function revokeOneAssignment(): void
    {
        $context = $this->app->make(SchemaContext::class);

        $this->connection()
            ->table($context->table('role_assignments'))
            ->where('role_id', self::FIRST_ROLE_ID)
            ->update([
                'revoked_at' => ResolverAcceptanceFixture::evaluatedAt()
                    ->modify('-1 minute'),
                'revoked_by_type' => 'gatetrail.system',
                'revoked_by_id' => 'acceptance',
                'revocation_reason' => 'Chunk boundary fixture',
            ]);
    }

    private function assertSelectOnly(
        StatementMeasurement $measurement,
        int $expected,
    ): void {
        $this->assertSame($expected, $measurement->total);
        $this->assertSame($expected, $measurement->select);
        $this->assertSame(0, $measurement->insert);
        $this->assertSame(0, $measurement->update);
        $this->assertSame(0, $measurement->delete);
        $this->assertSame(0, $measurement->schema);
        $this->assertSame(0, $measurement->other);
    }

    private function resolver(): EffectiveAccessResolver
    {
        return $this->app->make(EffectiveAccessResolver::class);
    }

    private function probe(): GateTrailStatementProbe
    {
        return new GateTrailStatementProbe(
            $this->app->make(DatabaseManager::class),
            $this->app->make(SchemaContext::class),
        );
    }

    /**
     * The fixture writes rows directly, so nothing dispatches
     * `MutationCommitted` and nothing flushes the memo on its own.
     */
    private function forgetMemo(): void
    {
        $this->app->make(EffectiveAccessMemo::class)->clear();
    }
}

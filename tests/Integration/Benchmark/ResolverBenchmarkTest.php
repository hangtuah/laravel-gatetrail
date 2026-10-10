<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Integration\Benchmark;

use DateTimeImmutable;
use DateTimeZone;
use HangTuah\GateTrail\Access\EffectiveAccessMemo;
use HangTuah\GateTrail\Access\EffectiveAccessResolver;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Diagnostics\GateTrailStatementProbe;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\Integration\MySql\MySqlIntegrationTestCase;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use RuntimeException;

final class ResolverBenchmarkTest extends MySqlIntegrationTestCase
{
    private const COLD_SAMPLES = 30;

    private const WARM_BATCHES = 30;

    private const WARM_OPERATIONS_PER_BATCH = 100;

    private const COLD_P95_THRESHOLD_MS = 25.0;

    private const WARM_P95_THRESHOLD_MS = 1.0;

    private TestSubject $subject;

    /**
     * @return array<string, array{string, int, int, bool}>
     */
    public static function workloads(): array
    {
        return [
            'representative' => ['representative', 10, 100, true],
            'heavy' => ['heavy', 50, 500, false],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => self::CONNECTION,
        ])->assertExitCode(0);

        $this->app->instance(
            ClockInterface::class,
            new FrozenClock(new DateTimeImmutable(
                '2026-10-10 12:00:00',
                new DateTimeZone('UTC'),
            )),
        );

        $this->schema()->create(
            'test_subjects',
            static function (Blueprint $table): void {
                $table->unsignedBigInteger('id');
                $table->string('name', 100);
                $table->primary('id');
            },
        );

        $this->connection()->table('test_subjects')->insert([
            'id' => 1,
            'name' => 'Benchmark subject',
        ]);

        $subject = TestSubject::query()->find(1);

        if (! $subject instanceof TestSubject) {
            throw new RuntimeException('Unable to load the benchmark subject.');
        }

        $this->subject = $subject;
    }

    protected function tearDown(): void
    {
        if (isset($this->app)) {
            $this->schema()->dropIfExists('test_subjects');
        }

        parent::tearDown();
    }

    #[DataProvider('workloads')]
    public function test_live_mysql_resolver_latency(
        string $workload,
        int $roleCount,
        int $permissionCount,
        bool $decisionWorkload,
    ): void {
        $this->seedBenchmarkFixture($roleCount, $permissionCount);

        $resolver = $this->app->make(EffectiveAccessResolver::class);
        $memo = $this->app->make(EffectiveAccessMemo::class);
        $probe = new GateTrailStatementProbe(
            $this->app->make(DatabaseManager::class),
            $this->app->make(SchemaContext::class),
        );
        $coldMilliseconds = [];

        for ($sample = 0; $sample < self::COLD_SAMPLES; $sample++) {
            $memo->clear();
            $startedAt = hrtime(true);
            $measurement = $probe->measure(
                fn () => $resolver->resolve($this->subject),
            );
            $coldMilliseconds[] = $this->millisecondsSince($startedAt);

            $this->assertSame(3, $measurement->total);
            $this->assertSame(3, $measurement->select);
        }

        $memo->clear();
        $snapshot = $resolver->resolve($this->subject);

        $this->assertSame($roleCount, $snapshot->roles()->count());
        $this->assertSame(
            $permissionCount,
            $snapshot->permissions()->count(),
        );

        $warmMilliseconds = [];
        $warmMeasurement = $probe->measure(function () use (
            $resolver,
            &$warmMilliseconds,
        ): void {
            for ($batch = 0; $batch < self::WARM_BATCHES; $batch++) {
                $startedAt = hrtime(true);

                for (
                    $operation = 0;
                    $operation < self::WARM_OPERATIONS_PER_BATCH;
                    $operation++
                ) {
                    $resolver->resolve($this->subject);
                }

                $warmMilliseconds[] = $this->millisecondsSince($startedAt)
                    / self::WARM_OPERATIONS_PER_BATCH;
            }
        });

        $coldP50 = $this->percentile($coldMilliseconds, 50);
        $coldP95 = $this->percentile($coldMilliseconds, 95);
        $warmP50 = $this->percentile($warmMilliseconds, 50);
        $warmP95 = $this->percentile($warmMilliseconds, 95);
        $result = [
            'workload' => $workload,
            'database' => 'mysql',
            'database_version' => $this->databaseVersion(),
            'php_version' => PHP_VERSION,
            'laravel_version' => $this->app->version(),
            'roles' => $roleCount,
            'permissions' => $permissionCount,
            'cold_samples' => self::COLD_SAMPLES,
            'warm_batches' => self::WARM_BATCHES,
            'warm_operations_per_batch' => self::WARM_OPERATIONS_PER_BATCH,
            'cold_selects_per_sample' => 3,
            'warm_statements' => $warmMeasurement->total,
            'cold_p50_ms' => round($coldP50, 3),
            'cold_p95_ms' => round($coldP95, 3),
            'warm_p50_ms' => round($warmP50, 3),
            'warm_p95_ms' => round($warmP95, 3),
            'threshold_applies' => $decisionWorkload,
            'threshold_passed' => ! $decisionWorkload || (
                $coldP95 <= self::COLD_P95_THRESHOLD_MS
                && $warmP95 <= self::WARM_P95_THRESHOLD_MS
                && $warmMeasurement->total === 0
            ),
        ];

        fwrite(
            STDOUT,
            json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
                .PHP_EOL,
        );

        $this->assertSame(0, $warmMeasurement->total);

        if ($decisionWorkload) {
            $this->assertLessThanOrEqual(
                self::COLD_P95_THRESHOLD_MS,
                $coldP95,
                'Representative cold p95 exceeded the registered threshold.',
            );
            $this->assertLessThanOrEqual(
                self::WARM_P95_THRESHOLD_MS,
                $warmP95,
                'Representative warm p95 exceeded the registered threshold.',
            );
        }
    }

    private function seedBenchmarkFixture(
        int $roleCount,
        int $permissionCount,
    ): void {
        $context = $this->app->make(SchemaContext::class);
        $roles = [];
        $permissions = [];
        $assignments = [];
        $grants = [];
        $timestamp = '2026-10-10 11:00:00';

        for ($index = 0; $index < $roleCount; $index++) {
            $roleId = 1000 + $index;
            $roles[] = [
                'id' => $roleId,
                'name' => sprintf('benchmark-role-%03d', $index),
                'guard_name' => 'web',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
            $assignments[] = [
                'subject_type' => $this->subject->getMorphClass(),
                'subject_id' => $this->subject->getKey(),
                'role_id' => $roleId,
                'assigned_at' => $timestamp,
                'assigned_by_type' => 'gatetrail.system',
                'assigned_by_id' => 'benchmark',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        for ($index = 0; $index < $permissionCount; $index++) {
            $permissionId = 2000 + $index;
            $permissions[] = [
                'id' => $permissionId,
                'name' => sprintf('benchmark.permission.%04d', $index),
                'guard_name' => 'web',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
            $grants[] = [
                'role_id' => 1000 + ($index % $roleCount),
                'permission_id' => $permissionId,
                'assigned_at' => $timestamp,
                'assigned_by_type' => 'gatetrail.system',
                'assigned_by_id' => 'benchmark',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        $this->insertBatches($context->table('roles'), $roles);
        $this->insertBatches($context->table('permissions'), $permissions);
        $this->insertBatches(
            $context->table('role_assignments'),
            $assignments,
        );
        $this->insertBatches($context->table('role_permissions'), $grants);
    }

    /**
     * @param  list<array<string, int|string>>  $rows
     */
    private function insertBatches(string $table, array $rows): void
    {
        foreach (array_chunk($rows, 250) as $batch) {
            $this->connection()->table($table)->insert($batch);
        }
    }

    private function millisecondsSince(int $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1_000_000;
    }

    /**
     * @param  list<float>  $values
     */
    private function percentile(array $values, int $percentile): float
    {
        sort($values, SORT_NUMERIC);
        $index = (int) ceil(($percentile / 100) * count($values)) - 1;

        return $values[max(0, $index)];
    }

    private function databaseVersion(): string
    {
        $row = $this->connection()->selectOne('SELECT VERSION() AS version');

        return (string) ((array) $row)['version'];
    }
}

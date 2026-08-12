<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Integration\MySql;

use HangTuah\GateTrail\Assignments\AssignmentScope;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\Mutations\MutationName;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use Illuminate\Database\Schema\Blueprint;
use RuntimeException;

/**
 * Real parallel writers, one assignment scope, one MySQL server.
 *
 * Every other concurrency test in this package simulates a lost race inside a
 * single process. This one does not: it forks separate PHP processes, each with
 * its own MySQL connection, releases them from a database barrier at the same
 * moment, and lets the server decide who loses. What the workers hit is genuine
 * primary-key contention on `acl_assignment_locks` and genuine row contention on
 * the assignment table.
 *
 * The invariant under test is ADR 0001's: however the race resolves, the scope
 * ends with exactly one active assignment, and no consumer ever sees a raw
 * duplicate-key, deadlock or serialization failure for a condition ADR 0011
 * classifies as retryable.
 */
final class ParallelCoordinationTest extends MySqlIntegrationTestCase
{
    private const WORKERS = 4;

    private const BARRIER_SECONDS = 30.0;

    private const PROCESS_TIMEOUT_SECONDS = 120.0;

    /**
     * @var list<array{resource, array<int, resource>, string}>
     */
    private array $processes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => self::CONNECTION,
        ])->assertExitCode(0);

        $this->schema()->dropIfExists('test_subjects');
        $this->schema()->create('test_subjects', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        $this->schema()->dropIfExists('gatetrail_barrier');
        $this->schema()->create('gatetrail_barrier', static function (Blueprint $table): void {
            $table->unsignedInteger('worker')->primary();
            $table->unsignedTinyInteger('released')->default(0);
        });
    }

    protected function tearDown(): void
    {
        // Children must never outlive the test, even when it fails.
        $this->terminateWorkers();

        if (isset($this->app)) {
            $this->schema()->dropIfExists('gatetrail_barrier');
            $this->schema()->dropIfExists('test_subjects');
        }

        parent::tearDown();
    }

    public function test_parallel_writers_on_one_scope_produce_one_assignment(): void
    {
        $subject = TestSubject::query()->create(['name' => 'Reviewer']);
        $role = Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'web',
            'description' => null,
        ]);

        $results = $this->runWorkers(self::WORKERS, attempts: 10);

        $created = 0;
        $unchanged = 0;

        foreach ($results as $result) {
            $this->assertSame(
                'completed',
                $result['status'] ?? null,
                sprintf(
                    'Worker %s did not complete: %s',
                    $result['worker'] ?? '?',
                    json_encode($result, JSON_THROW_ON_ERROR),
                ),
            );

            $outcome = $result['outcome'] ?? null;

            $this->assertContains(
                $outcome,
                ['created', 'unchanged'],
                'ADR 0011 permits only a creation or an idempotent unchanged '
                    .'result here; a classified retryable failure must never '
                    .'surface raw.',
            );

            $outcome === 'created' ? $created++ : $unchanged++;
        }

        // Exactly one writer creates; every other writer replays the winner's
        // assignment and reports it as unchanged.
        $this->assertSame(1, $created);
        $this->assertSame(self::WORKERS - 1, $unchanged);

        $this->assertSame(
            1,
            (int) $this->connection()->table('acl_role_assignments')
                ->whereNull('revoked_at')
                ->count(),
            'No duplicate active equivalent assignment may exist.',
        );

        // Coordination stayed consistent: one row, carrying the canonical key.
        $scope = AssignmentScope::forRole(
            $subject->getMorphClass(),
            (int) $subject->getKey(),
            (int) $role->getKey(),
        );

        $this->assertSame(
            1,
            (int) $this->connection()->table('acl_assignment_locks')->count(),
        );
        $this->assertSame(
            1,
            (int) $this->connection()->table('acl_assignment_locks')
                ->where('assignment_kind', $scope->kind()->value)
                ->where('scope_hash', $scope->hash())
                ->where('scope_key', $scope->key())
                ->count(),
        );

        // Only the committed creation is audited. Unchanged replays write no
        // audit row, so exactly one exists.
        $audits = $this->connection()->table('acl_access_audits')->get();

        $this->assertCount(1, $audits);
        $this->assertSame(
            MutationName::ROLE_ASSIGNED,
            $audits->first()?->event,
        );
    }

    public function test_a_single_attempt_ceiling_fails_closed_rather_than_raw(): void
    {
        $subject = TestSubject::query()->create(['name' => 'Reviewer']);
        Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'web',
            'description' => null,
        ]);

        // With one permitted attempt, a loser cannot retry. It must still fail
        // through GateTrail's typed contract rather than leaking the driver's
        // duplicate-key or deadlock exception.
        $results = $this->runWorkers(self::WORKERS, attempts: 1);

        $created = 0;

        foreach ($results as $result) {
            $status = $result['status'] ?? null;

            $this->assertContains(
                $status,
                ['completed', 'exhausted'],
                sprintf(
                    'Worker %s produced an unacceptable result: %s',
                    $result['worker'] ?? '?',
                    json_encode($result, JSON_THROW_ON_ERROR),
                ),
            );

            if ($status === 'exhausted') {
                $this->assertSame(
                    MutationName::ROLE_ASSIGNED,
                    $result['operation'] ?? null,
                );
                $this->assertSame(1, $result['attempts'] ?? null);
                $this->assertFalse($result['caller_owned'] ?? null);
                $this->assertContains(
                    $result['category'] ?? null,
                    ['coordination_race', 'deadlock', 'serialization_failure'],
                );
                $this->assertSame('mysql', $result['driver'] ?? null);
                $this->assertFalse(
                    $result['has_previous'] ?? null,
                    'The terminal exception must have an empty previous chain.',
                );

                continue;
            }

            if (($result['outcome'] ?? null) === 'created') {
                $created++;
            }
        }

        $this->assertLessThanOrEqual(1, $created);
        $this->assertSame(
            $created,
            (int) $this->connection()->table('acl_role_assignments')
                ->whereNull('revoked_at')
                ->count(),
            'A failed attempt must leave no assignment behind.',
        );
        $this->assertSame(
            $created,
            (int) $this->connection()->table('acl_access_audits')->count(),
            'A failed attempt must leave no audit row behind.',
        );
    }

    /**
     * Starts the workers, releases the barrier once every one has arrived, and
     * collects their JSON results.
     *
     * @return list<array<string, mixed>>
     */
    private function runWorkers(int $count, int $attempts): array
    {
        $subject = TestSubject::query()->firstOrFail();
        $role = Role::query()->firstOrFail();

        for ($worker = 1; $worker <= $count; $worker++) {
            $this->startWorker([
                'worker' => $worker,
                'attempts' => $attempts,
                'subject' => (int) $subject->getKey(),
                'role' => (string) $role->getAttribute('name'),
                'barrier_seconds' => self::BARRIER_SECONDS,
                'connection' => $this->workerConnectionConfig(),
            ]);
        }

        $this->releaseBarrier($count);

        return $this->collectResults();
    }

    /**
     * The connection definition each worker opens for itself.
     *
     * @return array<string, mixed>
     */
    private function workerConnectionConfig(): array
    {
        return [
            'driver' => 'mysql',
            'host' => (string) $this->connection()->getConfig('host'),
            'port' => (string) $this->connection()->getConfig('port'),
            'database' => $this->connection()->getDatabaseName(),
            'username' => (string) $this->connection()->getConfig('username'),
            'password' => (string) $this->connection()->getConfig('password'),
            'unix_socket' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function startWorker(array $options): void
    {
        $command = [
            PHP_BINARY,
            dirname(__DIR__, 3)
                .'/tests/Integration/MySql/workers/coordinated-mutation-worker.php',
            json_encode($options, JSON_THROW_ON_ERROR),
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            dirname(__DIR__, 3),
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start a GateTrail worker.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $this->processes[] = [
            $process,
            [1 => $pipes[1], 2 => $pipes[2]],
            (string) $options['worker'],
        ];
    }

    /**
     * Waits until every worker has registered, then releases them together.
     */
    private function releaseBarrier(int $count): void
    {
        $deadline = microtime(true) + self::BARRIER_SECONDS;

        while (microtime(true) < $deadline) {
            $arrived = (int) $this->connection()
                ->table('gatetrail_barrier')
                ->count();

            if ($arrived >= $count) {
                $this->connection()->table('gatetrail_barrier')
                    ->update(['released' => 1]);

                return;
            }

            usleep(2000);
        }

        throw new RuntimeException(
            sprintf(
                'Only %d of %d GateTrail workers reached the barrier in time.',
                (int) $this->connection()->table('gatetrail_barrier')->count(),
                $count,
            ),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectResults(): array
    {
        $deadline = microtime(true) + self::PROCESS_TIMEOUT_SECONDS;
        $output = [];
        $errors = [];
        $running = count($this->processes);

        foreach ($this->processes as $index => [$process, $pipes, $label]) {
            $output[$index] = '';
            $errors[$index] = '';
        }

        while ($running > 0 && microtime(true) < $deadline) {
            $running = 0;

            foreach ($this->processes as $index => [$process, $pipes, $label]) {
                $output[$index] .= (string) stream_get_contents($pipes[1]);
                $errors[$index] .= (string) stream_get_contents($pipes[2]);

                $status = proc_get_status($process);

                if ($status['running'] === true) {
                    $running++;
                }
            }

            if ($running > 0) {
                usleep(5000);
            }
        }

        if ($running > 0) {
            $this->terminateWorkers();

            throw new RuntimeException(
                'GateTrail workers exceeded the process timeout.',
            );
        }

        $results = [];

        foreach ($this->processes as $index => [$process, $pipes, $label]) {
            // Drain anything buffered between the last poll and exit.
            $output[$index] .= (string) stream_get_contents($pipes[1]);
            $errors[$index] .= (string) stream_get_contents($pipes[2]);

            $line = trim($output[$index]);

            if ($line === '') {
                throw new RuntimeException(
                    sprintf(
                        "GateTrail worker %s produced no result.\nstderr: %s",
                        $label,
                        trim($errors[$index]) === ''
                            ? '(empty)'
                            : trim($errors[$index]),
                    ),
                );
            }

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $results[] = $decoded;
        }

        $this->terminateWorkers();

        return $results;
    }

    private function terminateWorkers(): void
    {
        foreach ($this->processes as [$process, $pipes]) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            if (! is_resource($process)) {
                continue;
            }

            $status = proc_get_status($process);

            if ($status['running'] === true) {
                proc_terminate($process, 9);
            }

            proc_close($process);
        }

        $this->processes = [];
    }
}

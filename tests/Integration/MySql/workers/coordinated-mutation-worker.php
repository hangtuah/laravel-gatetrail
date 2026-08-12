<?php

declare(strict_types=1);

/**
 * One competing writer, in its own OS process and its own MySQL connection.
 *
 * The parent test spawns several of these against one assignment scope. Each
 * boots a real Laravel application with the real GateTrail service provider,
 * waits on a database barrier so every worker starts within milliseconds of the
 * others, then calls one public `AssignmentManager` method.
 *
 * The result is written to stdout as a single JSON object. Anything else on
 * stdout or stderr is a bootstrap failure and the parent reports it verbatim.
 *
 * Nothing here is mocked: no fake classifier, no injected exception, no
 * simulated race. The contention is real MySQL row and index contention between
 * real processes.
 */

use HangTuah\GateTrail\Contracts\AssignmentManager;
use HangTuah\GateTrail\Exceptions\ConcurrencyRetryExhausted;
use HangTuah\GateTrail\GateTrailServiceProvider;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\Foundation\Application as Testbench;

$root = dirname(__DIR__, 4);

require $root.'/vendor/autoload.php';

/**
 * Emits the single JSON result line and stops.
 *
 * @param  array<string, mixed>  $result
 */
$emit = static function (array $result): never {
    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));
    fwrite(STDOUT, PHP_EOL);

    exit(0);
};

try {
    /** @var array<string, mixed> $options */
    $options = json_decode(
        (string) ($argv[1] ?? ''),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $worker = (int) $options['worker'];
    $attempts = (int) $options['attempts'];
    $subjectId = (int) $options['subject'];
    $role = (string) $options['role'];
    $deadline = microtime(true) + (float) $options['barrier_seconds'];

    /** @var Application $app */
    $app = Testbench::create(basePath: null);

    // Register first so the package's own defaults are merged, then override.
    // `mergeConfigFrom` is a shallow merge, so pre-setting a nested GateTrail
    // key would drop its siblings.
    $app->register(GateTrailServiceProvider::class);

    // The parent owns the connection definition, so the worker carries no
    // driver assumptions of its own.
    /** @var array<string, mixed> $connectionConfig */
    $connectionConfig = $options['connection'];

    $app['config']->set(
        'database.connections.gatetrail_worker',
        $connectionConfig,
    );
    $app['config']->set('database.default', 'gatetrail_worker');
    $app['config']->set('gatetrail.database.connection', 'gatetrail_worker');
    $app['config']->set(
        'gatetrail.assignments.concurrency_attempts',
        $attempts,
    );

    $connection = $app['db']->connection('gatetrail_worker');

    // Register at the barrier, then wait for the parent to release it. The
    // wait is bounded polling against a hard deadline, never an open-ended or
    // arbitrarily long sleep.
    $connection->table('gatetrail_barrier')->insert([
        'worker' => $worker,
        'released' => 0,
    ]);

    $released = false;

    while (microtime(true) < $deadline) {
        $pending = (int) $connection->table('gatetrail_barrier')
            ->where('released', 0)
            ->count();

        if ($pending === 0) {
            $released = true;

            break;
        }

        usleep(2000);
    }

    if (! $released) {
        $emit([
            'worker' => $worker,
            'status' => 'barrier_timeout',
        ]);
    }

    // The worker connection is this process's default, so the fixture model
    // resolves against it without any per-query connection juggling.
    $subject = TestSubject::query()->findOrFail($subjectId);

    try {
        $result = $app->make(AssignmentManager::class)->assignRole(
            $subject,
            $role,
            SystemActor::named('worker-'.$worker),
            'Assigned by worker '.$worker,
        );

        $emit([
            'worker' => $worker,
            'status' => 'completed',
            'outcome' => $result->outcome->value,
            'audit_id' => $result->auditId,
        ]);
    } catch (ConcurrencyRetryExhausted $exhausted) {
        $emit([
            'worker' => $worker,
            'status' => 'exhausted',
            'operation' => $exhausted->operation,
            'attempts' => $exhausted->attempts,
            'caller_owned' => $exhausted->callerOwned,
            'category' => $exhausted->category,
            'driver' => $exhausted->driver,
            'sql_state' => $exhausted->sqlState,
            'vendor_code' => $exhausted->vendorCode,
            'has_previous' => $exhausted->getPrevious() !== null,
        ]);
    } catch (Throwable $failure) {
        $emit([
            'worker' => $worker,
            'status' => 'failed',
            'exception' => $failure::class,
            'message' => $failure->getMessage(),
        ]);
    }
} catch (Throwable $bootstrap) {
    fwrite(
        STDERR,
        sprintf(
            "worker bootstrap failed: %s: %s\n%s\n",
            $bootstrap::class,
            $bootstrap->getMessage(),
            $bootstrap->getTraceAsString(),
        ),
    );

    exit(1);
}

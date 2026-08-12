<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Concurrency;

use FilesystemIterator;
use HangTuah\GateTrail\Assignments\AssignmentLockStore;
use HangTuah\GateTrail\Concurrency\ClassifiedConcurrencyFailure;
use HangTuah\GateTrail\Concurrency\CoordinationInsertRace;
use HangTuah\GateTrail\Concurrency\CoordinationRaceClassifier;
use HangTuah\GateTrail\Concurrency\TransientFailureClassifier;
use HangTuah\GateTrail\Mutations\AuditedMutationTransaction;
use HangTuah\GateTrail\Mutations\StrictMutationTransaction;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\QueryException;
use PDOException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * Increment 2 adds classification and an inert marker. It must not activate any
 * retry behaviour, and this file is the evidence.
 *
 * The sequencing matters: `CoordinationInsertRace` is internal control flow with
 * no catcher yet, so a production path that threw one today would let it escape
 * to a consumer as an unhandled internal exception. The throw site and the retry
 * orchestrator must therefore land atomically in Increment 3.
 */
final class RetryRemainsUnactivatedTest extends TestCase
{
    public function test_no_production_path_creates_the_marker(): void
    {
        $this->assertSame(
            ['Concurrency/CoordinationInsertRace.php'],
            $this->sourceFilesMentioning('CoordinationInsertRace'),
            'Only its own definition may mention the marker in code.',
        );
    }

    public function test_no_production_path_throws_the_marker(): void
    {
        foreach ($this->sourceCode() as $relative => $code) {
            $this->assertStringNotContainsString(
                'throw new CoordinationInsertRace',
                $code,
                sprintf('[%s] must not throw the marker.', $relative),
            );
            $this->assertStringNotContainsString(
                'CoordinationInsertRace::confirmed',
                $code,
                sprintf('[%s] must not create the marker.', $relative),
            );
        }
    }

    public function test_no_run_coordinated_entry_point_exists(): void
    {
        $this->assertFalse(
            method_exists(AuditedMutationTransaction::class, 'runCoordinated'),
        );

        $public = [];

        foreach (
            (new ReflectionClass(AuditedMutationTransaction::class))
                ->getMethods() as $method
        ) {
            if ($method->isPublic()) {
                $public[] = $method->getName();
            }
        }

        sort($public);

        $this->assertSame(['__construct', 'run'], $public);
        $this->assertSame([], $this->sourceFilesMentioning('runCoordinated'));
    }

    public function test_no_mutation_path_consumes_any_retry_machinery(): void
    {
        $mutationPaths = ['Mutations/', 'Managers/', 'Assignments/'];
        $retryMachinery = [
            'RetryPolicy',
            'ConcurrencyRetryExhausted',
            'CoordinationInsertRace',
            'CoordinationRaceClassifier',
            'TransientFailureClassifier',
            'ClassifiedConcurrencyFailure',
            'runCoordinated',
        ];

        foreach ($this->sourceCode() as $relative => $code) {
            foreach ($mutationPaths as $path) {
                if (! str_starts_with($relative, $path)) {
                    continue;
                }

                foreach ($retryMachinery as $symbol) {
                    $this->assertStringNotContainsString(
                        $symbol,
                        $code,
                        sprintf(
                            '[%s] must not consume retry machinery yet (%s).',
                            $relative,
                            $symbol,
                        ),
                    );
                }
            }
        }
    }

    public function test_the_classifiers_are_consumed_by_nothing(): void
    {
        foreach ([
            'TransientFailureClassifier' => 'Concurrency/TransientFailureClassifier.php',
            'CoordinationRaceClassifier' => 'Concurrency/CoordinationRaceClassifier.php',
        ] as $classifier => $definition) {
            $this->assertSame(
                [$definition, 'GateTrailServiceProvider.php'],
                $this->sourceFilesMentioning($classifier),
                sprintf(
                    'Only its definition and its registration may mention [%s].',
                    $classifier,
                ),
            );
        }
    }

    public function test_the_assignment_lock_store_is_not_wired(): void
    {
        $source = $this->sourceCode()['Assignments/AssignmentLockStore.php'];

        foreach ([
            'Concurrency',
            'CoordinationInsertRace',
            'CoordinationRaceClassifier',
            'TransientFailureClassifier',
            'ClassifiedConcurrencyFailure',
            'catch',
            'QueryException',
        ] as $symbol) {
            $this->assertStringNotContainsString(
                $symbol,
                $source,
                sprintf('AssignmentLockStore must not reference [%s].', $symbol),
            );
        }

        $reflection = new ReflectionClass(AssignmentLockStore::class);
        $public = [];

        foreach ($reflection->getMethods() as $method) {
            if ($method->isPublic()) {
                $public[] = $method->getName();
            }
        }

        sort($public);

        $this->assertSame(['__construct', 'acquire'], $public);
        $this->assertSame(
            ['database', 'schema'],
            array_map(
                static fn (object $parameter): string => $parameter->getName(),
                $reflection->getConstructor()?->getParameters() ?? [],
            ),
        );
    }

    public function test_the_strict_transaction_still_makes_one_attempt(): void
    {
        $attempts = 0;
        // Laravel's own retry matches this phrase, so a change to a higher
        // attempt count would show up here as repeated invocations.
        $deadlock = new QueryException(
            'testing',
            'update "acl_assignment_locks" set "updated_at" = ?',
            ['2026-08-12 00:00:00'],
            new PDOException(
                'Deadlock found when trying to get lock; try restarting transaction',
            ),
        );

        try {
            app(StrictMutationTransaction::class)->run(
                function () use (&$attempts, $deadlock): void {
                    $attempts++;

                    throw $deadlock;
                },
            );

            $this->fail('The deadlock should propagate unchanged.');
        } catch (QueryException $thrown) {
            $this->assertSame($deadlock, $thrown);
        }

        $this->assertSame(1, $attempts);
    }

    public function test_every_handler_still_uses_the_plain_run_path(): void
    {
        $checked = 0;

        foreach ($this->sourceCode() as $relative => $code) {
            if (! str_starts_with($relative, 'Mutations/Handlers/')) {
                continue;
            }

            if (! str_contains($code, 'AuditedMutationTransaction')) {
                continue;
            }

            $checked++;

            $this->assertStringContainsString(
                '->run(',
                $code,
                sprintf('[%s] should still use run().', $relative),
            );
            $this->assertStringNotContainsString(
                '->runCoordinated(',
                $code,
                sprintf('[%s] must not opt into retry.', $relative),
            );
        }

        // All fourteen mutations, six coordinated and eight definition.
        $this->assertSame(14, $checked);
    }

    public function test_the_classifiers_are_registered_as_singletons(): void
    {
        foreach ([
            TransientFailureClassifier::class,
            CoordinationRaceClassifier::class,
        ] as $classifier) {
            $this->assertTrue($this->app->bound($classifier));
            $this->assertInstanceOf($classifier, app($classifier));
            $this->assertSame(app($classifier), app($classifier));
        }
    }

    public function test_values_and_control_flow_are_never_container_services(): void
    {
        foreach ([
            ClassifiedConcurrencyFailure::class,
            CoordinationInsertRace::class,
        ] as $type) {
            $this->assertFalse(
                $this->app->bound($type),
                sprintf('[%s] must not be a container service.', $type),
            );
        }

        $provider = $this->sourceCode()['GateTrailServiceProvider.php'];

        $this->assertStringNotContainsString(
            'ClassifiedConcurrencyFailure::class',
            $provider,
        );
        $this->assertStringNotContainsString(
            'CoordinationInsertRace::class',
            $provider,
        );
        $this->assertStringNotContainsString(
            'ConcurrencyRetryExhausted::class',
            $provider,
        );
    }

    /**
     * @return list<string>
     */
    private function sourceFilesMentioning(string $symbol): array
    {
        $matched = [];

        foreach ($this->sourceCode() as $relative => $code) {
            if (str_contains($code, $symbol)) {
                $matched[] = $relative;
            }
        }

        sort($matched);

        return $matched;
    }

    /**
     * Every shipped PHP source file with its comments removed, keyed by its
     * path relative to `src/`.
     *
     * Comments are stripped so that a docblock explaining why something is not
     * wired yet cannot be mistaken for the wiring itself. What remains is
     * executable code, which is what "no production path does this" means.
     *
     * @return array<string, string>
     */
    private function sourceCode(): array
    {
        $root = realpath(__DIR__.'/../../src');

        $this->assertNotFalse($root);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        $files = [];

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $path = (string) $file->getRealPath();
            $relative = str_replace(
                $root.DIRECTORY_SEPARATOR,
                '',
                $path,
            );
            $files[str_replace(DIRECTORY_SEPARATOR, '/', $relative)]
                = $this->withoutComments((string) file_get_contents($path));
        }

        ksort($files);

        return $files;
    }

    private function withoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $code .= $token[1];

                continue;
            }

            $code .= $token;
        }

        return $code;
    }
}

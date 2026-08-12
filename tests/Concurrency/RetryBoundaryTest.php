<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Concurrency;

use FilesystemIterator;
use HangTuah\GateTrail\Concurrency\ClassifiedConcurrencyFailure;
use HangTuah\GateTrail\Concurrency\CoordinationInsertRace;
use HangTuah\GateTrail\Concurrency\CoordinationPlan;
use HangTuah\GateTrail\Concurrency\CoordinationRaceClassifier;
use HangTuah\GateTrail\Concurrency\RetryOrchestrator;
use HangTuah\GateTrail\Concurrency\RetryPolicy;
use HangTuah\GateTrail\Concurrency\TransientFailureClassifier;
use HangTuah\GateTrail\Mutations\AuditedMutationTransaction;
use HangTuah\GateTrail\Mutations\MutationName;
use HangTuah\GateTrail\Mutations\StrictMutationTransaction;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\QueryException;
use PDOException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * Where retry is allowed to exist, and where it must not.
 *
 * Increment 2 proved retry was inert. Increment 3 activates it, so this file
 * proves the opposite set of invariants: the marker has exactly one throw site
 * and exactly one catch site, exactly six handlers opt in, the other eight
 * cannot, and Laravel's own retry stays switched off.
 */
final class RetryBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);
    }

    public function test_the_marker_has_exactly_one_throw_site(): void
    {
        $this->assertSame(
            [
                'Assignments/AssignmentLockStore.php',
                'Concurrency/CoordinationInsertRace.php',
                'Concurrency/RetryOrchestrator.php',
            ],
            $this->sourceFilesMentioning('CoordinationInsertRace'),
            'Only its definition, its one throw site and its one catch site.',
        );

        $creations = [];

        foreach ($this->sourceCode() as $relative => $code) {
            if (str_contains($code, 'CoordinationInsertRace::confirmed')) {
                $creations[] = $relative;
            }
        }

        $this->assertSame(
            ['Assignments/AssignmentLockStore.php'],
            $creations,
            'Only the coordination insert may create the marker.',
        );
    }

    public function test_the_marker_has_exactly_one_catch_site(): void
    {
        $catches = [];

        foreach ($this->sourceCode() as $relative => $code) {
            if (str_contains($code, 'catch (CoordinationInsertRace')) {
                $catches[] = $relative;
            }
        }

        $this->assertSame(
            ['Concurrency/RetryOrchestrator.php'],
            $catches,
            'Only the orchestrator may consume the marker.',
        );
    }

    public function test_the_orchestrator_never_catches_throwable(): void
    {
        $source = $this->sourceCode()['Concurrency/RetryOrchestrator.php'];

        $this->assertStringNotContainsString('catch (Throwable', $source);
        $this->assertStringNotContainsString('catch (\Throwable', $source);
        $this->assertStringNotContainsString('catch (Exception', $source);

        // Exactly the two retryable types, plus the narrow unsupported-driver
        // guard around classification.
        $this->assertSame(1, substr_count($source, 'catch (CoordinationInsertRace'));
        $this->assertSame(1, substr_count($source, 'catch (QueryException'));
        $this->assertSame(
            1,
            substr_count($source, 'catch (UnsupportedDatabaseDriver'),
        );
    }

    public function test_exactly_the_six_assignment_handlers_opt_in(): void
    {
        $coordinated = [];
        $plain = [];

        foreach ($this->sourceCode() as $relative => $code) {
            if (! str_starts_with($relative, 'Mutations/Handlers/')) {
                continue;
            }

            if (str_contains($code, '->runCoordinated(')) {
                $coordinated[] = basename($relative, '.php');

                $this->assertStringNotContainsString(
                    '->run(',
                    $code,
                    sprintf('[%s] must use only one entry point.', $relative),
                );

                continue;
            }

            $plain[] = basename($relative, '.php');
            $this->assertStringContainsString('->run(', $code, $relative);
        }

        sort($coordinated);
        sort($plain);

        $this->assertSame([
            'AssignRoleHandler',
            'GrantDirectPermissionHandler',
            'GrantPermissionToRoleHandler',
            'RevokeDirectPermissionHandler',
            'RevokePermissionFromRoleHandler',
            'RevokeRoleHandler',
        ], $coordinated);

        $this->assertSame([
            'CreatePermissionHandler',
            'CreateRoleHandler',
            'DeactivatePermissionHandler',
            'DeactivateRoleHandler',
            'RestorePermissionHandler',
            'RestoreRoleHandler',
            'UpdatePermissionHandler',
            'UpdateRoleHandler',
        ], $plain);
    }

    public function test_each_handler_passes_the_constant_it_audits_with(): void
    {
        $expected = [
            'AssignRoleHandler' => 'ROLE_ASSIGNED',
            'RevokeRoleHandler' => 'ROLE_REVOKED',
            'GrantDirectPermissionHandler' => 'PERMISSION_GRANTED_TO_SUBJECT',
            'RevokeDirectPermissionHandler' => 'PERMISSION_REVOKED_FROM_SUBJECT',
            'GrantPermissionToRoleHandler' => 'PERMISSION_GRANTED_TO_ROLE',
            'RevokePermissionFromRoleHandler' => 'PERMISSION_REVOKED_FROM_ROLE',
        ];

        foreach ($expected as $handler => $constant) {
            $code = $this->sourceCode()['Mutations/Handlers/'.$handler.'.php'];

            $this->assertStringContainsString(
                'runCoordinated(',
                $code,
                $handler,
            );
            // The retry operation and the audit event are the same constant,
            // so exhaustion, the audit row and MutationCommitted can never
            // describe different mutations.
            $this->assertSame(
                2,
                substr_count($code, 'MutationName::'.$constant),
                sprintf(
                    '[%s] should name %s exactly twice: the coordinated call '
                        .'and its audit event.',
                    $handler,
                    $constant,
                ),
            );

            $names = [];
            preg_match_all('/MutationName::([A-Z_]+)/', $code, $names);

            $this->assertSame(
                [$constant],
                array_values(array_unique($names[1])),
                sprintf('[%s] must use one vocabulary constant.', $handler),
            );
        }
    }

    public function test_the_strict_transaction_still_uses_one_laravel_attempt(): void
    {
        $attempts = 0;
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

        // Laravel's own message-matching retry would have run this three times
        // had the attempt count ever been raised. GateTrail owns all counting.
        $this->assertSame(1, $attempts);

        $source = $this->sourceCode()['Mutations/StrictMutationTransaction.php'];

        $this->assertStringContainsString('transaction(', $source);
        $this->assertMatchesRegularExpression(
            '/\$operation\(\),\s*1,/',
            $source,
            'StrictMutationTransaction must still pass attempts: 1.',
        );
    }

    public function test_the_two_entry_points_are_the_public_surface(): void
    {
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

        $this->assertSame(
            ['__construct', 'run', 'runCoordinated'],
            $public,
        );
    }

    public function test_no_backoff_exists_anywhere_in_the_concurrency_layer(): void
    {
        foreach ($this->sourceCode() as $relative => $code) {
            if (! str_starts_with($relative, 'Concurrency/')) {
                continue;
            }

            foreach ([
                'sleep(',
                'usleep(',
                'time_nanosleep',
                'Sleep::',
                'jitter',
                'backoff',
            ] as $delay) {
                $this->assertStringNotContainsString(
                    $delay,
                    $code,
                    sprintf('[%s] must introduce no delay.', $relative),
                );
            }
        }
    }

    public function test_retry_writes_no_operational_log(): void
    {
        foreach ($this->sourceCode() as $relative => $code) {
            if (
                ! str_starts_with($relative, 'Concurrency/')
                && $relative !== 'Assignments/AssignmentLockStore.php'
            ) {
                continue;
            }

            foreach ([
                'LoggerInterface',
                'Log::',
                'logger(',
                'report(',
            ] as $logging) {
                $this->assertStringNotContainsString(
                    $logging,
                    $code,
                    sprintf('[%s] must not log.', $relative),
                );
            }
        }
    }

    public function test_the_registered_services_are_stateless_singletons(): void
    {
        $stateless = [
            RetryPolicy::class => ['config'],
            TransientFailureClassifier::class => ['adapters'],
            CoordinationRaceClassifier::class => [
                'schema',
                'manifest',
                'adapters',
            ],
            RetryOrchestrator::class => ['transaction', 'classifier'],
        ];

        foreach ($stateless as $service => $properties) {
            $this->assertTrue($this->app->bound($service), $service);
            $this->assertSame(app($service), app($service), $service);

            $declared = [];

            foreach (
                (new ReflectionClass($service))->getProperties() as $property
            ) {
                $declared[] = $property->getName();
            }

            $this->assertSame($properties, $declared, $service);
        }
    }

    public function test_values_and_control_flow_are_never_container_services(): void
    {
        foreach ([
            ClassifiedConcurrencyFailure::class,
            CoordinationInsertRace::class,
            CoordinationPlan::class,
        ] as $type) {
            $this->assertFalse(
                $this->app->bound($type),
                sprintf('[%s] must not be a container service.', $type),
            );
        }

        $provider = $this->sourceCode()['GateTrailServiceProvider.php'];

        foreach ([
            'ClassifiedConcurrencyFailure::class',
            'CoordinationInsertRace::class',
            'CoordinationPlan::class',
            'ConcurrencyRetryExhausted::class',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $provider);
        }
    }

    public function test_the_vocabulary_still_has_exactly_six_coordinated_names(): void
    {
        $this->assertCount(6, MutationName::assignments());
        $this->assertCount(14, MutationName::all());

        foreach (MutationName::assignments() as $name) {
            $this->assertTrue(MutationName::isAssignment($name));
        }
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
     * Every shipped PHP source file with its comments removed.
     *
     * Comments are stripped so a docblock describing a boundary cannot be
     * mistaken for code that crosses it.
     *
     * @return array<string, string>
     */
    private function sourceCode(): array
    {
        static $cache = null;

        if (is_array($cache)) {
            return $cache;
        }

        $root = realpath(__DIR__.'/../../src');

        $this->assertNotFalse($root);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        $files = [];

        foreach ($iterator as $file) {
            if (
                ! $file instanceof SplFileInfo
                || $file->getExtension() !== 'php'
            ) {
                continue;
            }

            $path = (string) $file->getRealPath();
            $relative = str_replace($root.DIRECTORY_SEPARATOR, '', $path);
            $files[str_replace(DIRECTORY_SEPARATOR, '/', $relative)]
                = $this->withoutComments((string) file_get_contents($path));
        }

        ksort($files);

        return $cache = $files;
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

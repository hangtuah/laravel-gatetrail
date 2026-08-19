<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Diagnostics;

use HangTuah\GateTrail\Access\DefaultPermissionResolver;
use HangTuah\GateTrail\Access\DefaultRoleResolver;
use HangTuah\GateTrail\Access\EffectiveAccessResolver;
use HangTuah\GateTrail\Access\EffectiveAccessSnapshot;
use HangTuah\GateTrail\Contracts\PermissionResolver;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Diagnostics\GateTrailStatementProbe;
use HangTuah\GateTrail\Diagnostics\StatementMeasurement;
use HangTuah\GateTrail\GateTrailServiceProvider;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionNamedType;

/**
 * The instrumentation must not exist at runtime.
 *
 * ADR 0012 section 8 makes the probe test-and-diagnostic only. That is a claim
 * about production behaviour, so it is asserted the way a production claim has
 * to be: the provider registers nothing, no resolver depends on it, a real
 * authorization check adds no listener to the dispatcher, and no configuration
 * key can switch it on. A diagnostic that is merely *usually* off is a
 * diagnostic that will one day ship enabled.
 *
 * The complementary claim — that `EffectiveAccessSnapshot` still reports no
 * count of its own — is asserted here too, because the two decisions are one
 * decision: measure the connection, never the algorithm's opinion of itself.
 */
final class DiagnosticRuntimeAbsenceTest extends TestCase
{
    /**
     * @return array<string, array{class-string}>
     */
    public static function resolverClasses(): array
    {
        return [
            'internal resolver' => [EffectiveAccessResolver::class],
            'permission adapter' => [DefaultPermissionResolver::class],
            'role adapter' => [DefaultRoleResolver::class],
        ];
    }

    // ----------------------------------------------------- Container absence

    public function test_the_service_provider_binds_nothing_diagnostic(): void
    {
        $this->assertFalse(
            $this->app->bound(GateTrailStatementProbe::class),
            'The probe must have no container binding.',
        );
        $this->assertFalse($this->app->bound(StatementMeasurement::class));
        $this->assertFalse(
            $this->app->resolved(GateTrailStatementProbe::class),
            'Booting the package must not construct one.',
        );
    }

    public function test_the_service_provider_source_never_mentions_diagnostics(): void
    {
        $source = $this->sourceOf(GateTrailServiceProvider::class);

        foreach (['Diagnostics', 'StatementProbe', 'StatementMeasurement'] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $source,
                'GateTrailServiceProvider must not reference the probe at all.',
            );
        }
    }

    /**
     * The strongest form of the claim: nothing outside `src/Diagnostics`
     * names the namespace or either class, so no production path can reach it.
     *
     * Matched on the qualified namespace and the two class names rather than
     * the bare word, because `Concurrency\\DriverDiagnostics` is an unrelated
     * component that has carried that word since ADR 0011.
     */
    public function test_no_production_class_outside_diagnostics_references_it(): void
    {
        $referencing = [];

        foreach ($this->packageSourceFiles() as $path) {
            if (str_contains($path, '/src/Diagnostics/')) {
                continue;
            }

            $source = (string) file_get_contents($path);

            foreach ([
                'HangTuah\\GateTrail\\Diagnostics',
                'GateTrailStatementProbe',
                'StatementMeasurement',
            ] as $needle) {
                if (str_contains($source, $needle)) {
                    $referencing[] = $path;

                    break;
                }
            }
        }

        $this->assertSame(
            [],
            $referencing,
            'Only src/Diagnostics may know the instrumentation exists.',
        );
    }

    // ------------------------------------------------------ Resolver absence

    #[DataProvider('resolverClasses')]
    public function test_no_resolver_depends_on_the_probe(string $class): void
    {
        $constructor = (new ReflectionClass($class))->getConstructor();

        $this->assertNotNull($constructor);

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (! $type instanceof ReflectionNamedType) {
                continue;
            }

            $this->assertStringNotContainsString(
                'Diagnostics',
                $type->getName(),
                $class.' must not be constructed with instrumentation.',
            );
        }

        $this->assertStringNotContainsString(
            'Diagnostics',
            $this->sourceOf($class),
        );
    }

    // ------------------------------------------------------ Snapshot absence

    public function test_the_snapshot_still_reports_no_count_of_its_own(): void
    {
        $class = new ReflectionClass(EffectiveAccessSnapshot::class);

        foreach ($class->getMethods() as $method) {
            $this->assertDoesNotMatchRegularExpression(
                '/count|statement|quer|measure|probe/i',
                $method->getName(),
                'A self-reported counter is exactly what section 8 rejects.',
            );
        }

        foreach ($class->getProperties() as $property) {
            $this->assertDoesNotMatchRegularExpression(
                '/count|statement|quer|measure|probe/i',
                $property->getName(),
            );
        }

        $this->assertFalse($class->hasMethod('queryCount'));
    }

    public function test_the_resolver_keeps_no_counter_of_its_own(): void
    {
        foreach (
            (new ReflectionClass(EffectiveAccessResolver::class))->getProperties() as $property
        ) {
            $this->assertDoesNotMatchRegularExpression(
                '/count|measure|probe/i',
                $property->getName(),
            );
        }
    }

    // ------------------------------------------------------ Runtime absence

    /**
     * The behavioural half: a real check through the public contract adds no
     * listener to the dispatcher.
     */
    public function test_a_normal_authorization_check_installs_no_listener(): void
    {
        $this->artisan('migrate', ['--database' => 'testing'])->assertExitCode(0);

        Role::query()->create(['name' => 'reviewer', 'guard_name' => 'web']);
        Permission::query()->create([
            'name' => 'documents.approve',
            'guard_name' => 'web',
        ]);
        $subject = TestSubject::query()->create(['name' => 'Subject']);

        $before = $this->queryListenerCount();

        $this->app->make(PermissionResolver::class)
            ->allows($subject, 'documents.approve');
        $this->app->make(PermissionResolver::class)
            ->effectivePermissions($subject);

        $this->assertSame(
            $before,
            $this->queryListenerCount(),
            'Authorizing must not register a diagnostic listener.',
        );
        $this->assertFalse(
            $this->app->resolved(GateTrailStatementProbe::class),
        );
    }

    /**
     * And the probe only appears when something explicitly builds one.
     */
    public function test_the_probe_appears_only_on_explicit_construction(): void
    {
        $before = $this->queryListenerCount();

        new GateTrailStatementProbe(
            $this->app->make(DatabaseManager::class),
            $this->app->make(SchemaContext::class),
        );

        $this->assertSame(
            $before + 1,
            $this->queryListenerCount(),
            'Explicit construction is the only thing that installs it.',
        );
    }

    // ---------------------------------------------------- Configuration absence

    public function test_no_configuration_key_can_enable_it(): void
    {
        $config = config('gatetrail');

        $this->assertIsArray($config);

        $flattened = json_encode($config, JSON_THROW_ON_ERROR);

        foreach (['probe', 'diagnostic', 'instrument', 'measure', 'query_count'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase(
                $needle,
                $flattened,
                'The instrumentation must not be reachable through config.',
            );
        }
    }

    public function test_the_published_config_file_declares_nothing_diagnostic(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/config/gatetrail.php',
        );

        foreach (['Diagnostics', 'probe', 'instrument'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $source);
        }
    }

    // ------------------------------------------------------------- Helpers

    private function queryListenerCount(): int
    {
        return count($this->app['events']->getListeners(QueryExecuted::class));
    }

    /**
     * @param  class-string  $class
     */
    private function sourceOf(string $class): string
    {
        $path = (new ReflectionClass($class))->getFileName();

        $this->assertIsString($path);

        return (string) file_get_contents($path);
    }

    /**
     * @return list<string>
     */
    private function packageSourceFiles(): array
    {
        $directory = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src'),
        );

        $files = [];

        foreach ($directory as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}

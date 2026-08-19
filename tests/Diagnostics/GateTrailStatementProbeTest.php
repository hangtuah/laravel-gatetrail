<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Diagnostics;

use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Diagnostics\GateTrailStatementProbe;
use HangTuah\GateTrail\Diagnostics\StatementMeasurement;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

/**
 * The probe's own behaviour: what it counts, what it refuses, and what it
 * refuses to remember.
 *
 * Resolver budgets live in `ResolverQueryBudgetTest`; connection isolation
 * lives in `ProbeConnectionIsolationTest`. This class pins the mechanism those
 * two depend on, because a budget assertion is only evidence if the thing
 * producing the number is itself trustworthy — it must reset, it must survive a
 * failure, and it must not quietly become a place SQL and bindings accumulate.
 */
final class GateTrailStatementProbeTest extends TestCase
{
    /**
     * A value distinctive enough that finding it anywhere is unambiguous.
     */
    private const CANARY = 'gatetrail-canary-8f2c-do-not-retain';

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--database' => 'testing'])->assertExitCode(0);
    }

    // ----------------------------------------------------------- Structure

    public function test_it_is_final_and_internal(): void
    {
        $class = new ReflectionClass(GateTrailStatementProbe::class);

        $this->assertTrue($class->isFinal());
        $this->assertStringContainsString(
            '@internal',
            (string) $class->getDocComment(),
        );
    }

    /**
     * Its whole public surface is one method, so there is no accessor through
     * which a retained fragment could ever be read back out.
     */
    public function test_its_public_surface_is_construction_and_measure(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(GateTrailStatementProbe::class))
                ->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        sort($methods);

        $this->assertSame(['__construct', 'measure'], $methods);
    }

    public function test_it_observes_the_connection_schema_context_selects(): void
    {
        $this->assertNull(
            $this->app->make(SchemaContext::class)->connectionName(),
            'The default fixture leaves the connection unconfigured.',
        );

        $observed = (new ReflectionProperty(
            GateTrailStatementProbe::class,
            'connection',
        ))->getValue($this->probe());

        $this->assertSame(
            $this->app->make(DatabaseManager::class)->connection(
                $this->app->make(SchemaContext::class)->connectionName(),
            ),
            $observed,
            'The probe measures the connection the resolver reads.',
        );
    }

    // ------------------------------------------------------------ Counting

    public function test_it_counts_selects(): void
    {
        $probe = $this->probe();

        $measurement = $probe->measure(function (): void {
            DB::connection('testing')->table('acl_roles')->get();
            DB::connection('testing')->table('acl_permissions')->get();
        });

        $this->assertSame(2, $measurement->total);
        $this->assertSame(2, $measurement->select);
        $this->assertSame(0, $measurement->insert);
        $this->assertSame(0, $measurement->other);
    }

    public function test_it_classifies_each_fixed_category(): void
    {
        $probe = $this->probe();
        $connection = DB::connection('testing');

        $measurement = $probe->measure(function () use ($connection): void {
            $connection->table('acl_roles')->insert([
                'name' => 'probe-role',
                'guard_name' => 'web',
                'created_at' => '2026-08-19 09:00:00',
                'updated_at' => '2026-08-19 09:00:00',
            ]);
            $connection->table('acl_roles')->where('name', 'probe-role')
                ->update(['guard_name' => 'api']);
            $connection->table('acl_roles')->where('name', 'probe-role')->delete();
            $connection->statement('create table probe_scratch (id integer)');
            $connection->statement('drop table probe_scratch');
            $connection->table('acl_roles')->get();
        });

        $this->assertSame(1, $measurement->insert);
        $this->assertSame(1, $measurement->update);
        $this->assertSame(1, $measurement->delete);
        $this->assertSame(2, $measurement->schema);
        $this->assertSame(1, $measurement->select);
        $this->assertSame(0, $measurement->other);
        $this->assertSame(6, $measurement->total);
    }

    /**
     * The classifier fails towards `other` rather than guessing, so an
     * unexpected shape breaks a "3 select" budget instead of hiding in it.
     */
    public function test_an_unrecognized_statement_is_other_rather_than_a_guess(): void
    {
        $measurement = $this->probe()->measure(function (): void {
            DB::connection('testing')->statement('pragma foreign_keys');
        });

        $this->assertSame(1, $measurement->total);
        $this->assertSame(1, $measurement->other);
        $this->assertSame(0, $measurement->select);
    }

    public function test_a_window_with_no_statement_measures_zero(): void
    {
        $measurement = $this->probe()->measure(static function (): void {
            // Deliberately empty.
        });

        $this->assertSame(0, $measurement->total);
        $this->assertEquals(StatementMeasurement::none(), $measurement);
    }

    // ------------------------------------------------- Reuse and isolation

    public function test_one_probe_supports_repeated_independent_measurements(): void
    {
        $probe = $this->probe();

        $first = $probe->measure(static function (): void {
            DB::connection('testing')->table('acl_roles')->get();
        });
        $second = $probe->measure(static function (): void {
            DB::connection('testing')->table('acl_roles')->get();
            DB::connection('testing')->table('acl_permissions')->get();
        });
        $third = $probe->measure(static function (): void {
            // Deliberately empty.
        });

        $this->assertSame(1, $first->total);
        $this->assertSame(2, $second->total, 'Each window starts from zero.');
        $this->assertSame(0, $third->total);
    }

    public function test_statements_outside_a_measurement_are_ignored(): void
    {
        $probe = $this->probe();

        DB::connection('testing')->table('acl_roles')->get();

        $measurement = $probe->measure(static function (): void {
            DB::connection('testing')->table('acl_roles')->get();
        });

        DB::connection('testing')->table('acl_roles')->get();

        $this->assertSame(
            1,
            $measurement->total,
            'The listener is inert whenever no window is open.',
        );
    }

    /**
     * The listener cannot be detached, so it must not multiply. One probe is
     * one listener no matter how many windows it serves.
     */
    public function test_repeated_measurement_registers_no_further_listener(): void
    {
        $before = $this->queryListenerCount();

        $probe = $this->probe();
        $afterConstruction = $this->queryListenerCount();

        for ($index = 0; $index < 5; $index++) {
            $probe->measure(static function (): void {
                DB::connection('testing')->table('acl_roles')->get();
            });
        }

        $this->assertSame(
            $before + 1,
            $afterConstruction,
            'Construction registers exactly one listener.',
        );
        $this->assertSame(
            $afterConstruction,
            $this->queryListenerCount(),
            'Measuring registers none.',
        );
    }

    // ------------------------------------------------ Failure and nesting

    public function test_a_closure_exception_propagates_unchanged(): void
    {
        $thrown = new RuntimeException('measured work failed');

        try {
            $this->probe()->measure(static function () use ($thrown): void {
                throw $thrown;
            });

            $this->fail('The exception must propagate.');
        } catch (RuntimeException $caught) {
            $this->assertSame(
                $thrown,
                $caught,
                'The very same object, neither wrapped nor replaced.',
            );
        }
    }

    public function test_a_database_exception_propagates_unchanged_and_is_not_retained(): void
    {
        $bare = $this->messageOf(
            static fn () => DB::connection('testing')->select('select * from missing_table'),
        );

        $probe = $this->probe();

        $measured = $this->messageOf(
            static fn () => $probe->measure(static function (): void {
                DB::connection('testing')->select('select * from missing_table');
            }),
        );

        $this->assertSame(
            $bare,
            $measured,
            'Measuring changes nothing about how a query failure surfaces.',
        );

        // And the failure left nothing behind on the probe.
        $this->assertSame(
            self::EMPTY_TALLY_SHAPE,
            array_map(
                static fn (int $count): int => $count,
                $this->tallyOf($probe),
            ),
        );
    }

    public function test_the_probe_is_reusable_after_a_failed_window(): void
    {
        $probe = $this->probe();

        try {
            $probe->measure(static function (): void {
                DB::connection('testing')->table('acl_roles')->get();

                throw new RuntimeException('halfway');
            });
        } catch (RuntimeException) {
            // deliberate
        }

        $measurement = $probe->measure(static function (): void {
            DB::connection('testing')->table('acl_permissions')->get();
        });

        $this->assertSame(
            1,
            $measurement->total,
            'The next window starts from zero, not from the failed one.',
        );
        $this->assertSame(1, $measurement->select);
    }

    public function test_a_nested_measurement_is_rejected_with_a_fixed_message(): void
    {
        $probe = $this->probe();
        $inner = null;

        $outer = $probe->measure(function () use ($probe, &$inner): void {
            DB::connection('testing')->table('acl_roles')->get();

            try {
                $probe->measure(static function (): void {
                    DB::connection('testing')->table('acl_permissions')->get();
                });
            } catch (LogicException $caught) {
                $inner = $caught;
            }

            DB::connection('testing')->table('acl_permissions')->get();
        });

        $this->assertInstanceOf(LogicException::class, $inner);
        $this->assertSame(
            'A GateTrail statement measurement is already active on this probe.',
            $inner->getMessage(),
        );
        $this->assertSame(
            2,
            $outer->total,
            'The rejected nesting left the outer window intact.',
        );
    }

    public function test_the_probe_is_still_usable_after_a_rejected_nesting(): void
    {
        $probe = $this->probe();

        $probe->measure(function () use ($probe): void {
            try {
                $probe->measure(static fn () => null);
            } catch (LogicException) {
                // deliberate
            }
        });

        $measurement = $probe->measure(static function (): void {
            DB::connection('testing')->table('acl_roles')->get();
        });

        $this->assertSame(1, $measurement->total);
    }

    /**
     * Two probes are two independent windows over the same connection, which
     * is what lets a diagnostic nest one measurement inside another without
     * the invariant error.
     */
    public function test_two_probes_measure_independently(): void
    {
        $outer = $this->probe();
        $innerProbe = $this->probe();
        $inner = null;

        $outerMeasurement = $outer->measure(function () use ($innerProbe, &$inner): void {
            DB::connection('testing')->table('acl_roles')->get();

            $inner = $innerProbe->measure(static function (): void {
                DB::connection('testing')->table('acl_permissions')->get();
            });
        });

        $this->assertInstanceOf(StatementMeasurement::class, $inner);
        $this->assertSame(1, $inner->total);
        $this->assertSame(2, $outerMeasurement->total);
    }

    // ------------------------------------------------------------- Privacy

    /**
     * Nothing on the probe can hold text. The connection reference is the one
     * object it keeps, and it keeps it to compare by identity, not to read.
     */
    public function test_the_probe_declares_no_property_that_could_hold_sql(): void
    {
        $properties = (new ReflectionClass(GateTrailStatementProbe::class))
            ->getProperties();

        $this->assertNotSame([], $properties);

        foreach ($properties as $property) {
            $type = $property->getType();

            $this->assertInstanceOf(\ReflectionNamedType::class, $type);
            $this->assertContains(
                $type->getName(),
                ['bool', 'array', Connection::class],
                $property->getName().' must not be able to hold SQL, bindings '
                    .'or a QueryExecuted.',
            );
        }
    }

    public function test_a_sensitive_binding_and_literal_survive_nowhere(): void
    {
        $probe = $this->probe();

        $measurement = $probe->measure(static function (): void {
            DB::connection('testing')->table('acl_roles')
                ->where('name', self::CANARY)
                ->get();
            DB::connection('testing')->select(
                "select '".self::CANARY."' as canary",
            );
        });

        $this->assertSame(2, $measurement->total);
        $this->assertSame(2, $measurement->select);

        foreach ([
            print_r($measurement, true),
            var_export($measurement, true),
            serialize($measurement),
            json_encode(get_object_vars($measurement), JSON_THROW_ON_ERROR),
        ] as $representation) {
            $this->assertStringNotContainsString(self::CANARY, $representation);
        }

        foreach ($this->ownStateOf($probe) as $name => $value) {
            $this->assertStringNotContainsString(
                self::CANARY,
                print_r($value, true),
                $name.' retained the sensitive value.',
            );
        }
    }

    public function test_measuring_leaves_no_statement_text_on_the_connection(): void
    {
        $connection = DB::connection('testing');
        $probe = $this->probe();

        $probe->measure(static function (): void {
            DB::connection('testing')->table('acl_roles')
                ->where('name', self::CANARY)
                ->get();
        });

        $this->assertFalse(
            $connection->logging(),
            'The probe must not switch the framework query log on.',
        );
        $this->assertSame(
            [],
            $connection->getQueryLog(),
            'No statement text survives the callback.',
        );
    }

    public function test_no_measurement_or_probe_state_names_a_definition(): void
    {
        $probe = $this->probe();

        $measurement = $probe->measure(static function (): void {
            DB::connection('testing')->table('acl_roles')
                ->where('guard_name', 'web')
                ->get();
        });

        $dump = print_r($measurement, true).print_r($this->ownStateOf($probe), true);

        foreach ([
            'acl_roles', 'guard_name', 'web', 'select ', 'documents.approve',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $dump);
        }
    }

    // ------------------------------------------------------------- Helpers

    /**
     * @var array{select: int, insert: int, update: int, delete: int, schema: int, other: int}
     */
    private const EMPTY_TALLY_SHAPE = [
        'select' => 0,
        'insert' => 0,
        'update' => 0,
        'delete' => 0,
        'schema' => 0,
        'other' => 0,
    ];

    private function probe(): GateTrailStatementProbe
    {
        return new GateTrailStatementProbe(
            $this->app->make(DatabaseManager::class),
            $this->app->make(SchemaContext::class),
            $this->app->make('events'),
        );
    }

    /**
     * @return array<string, int>
     */
    private function tallyOf(GateTrailStatementProbe $probe): array
    {
        /** @var array<string, int> $tally */
        $tally = (new ReflectionProperty($probe, 'tally'))->getValue($probe);

        return $tally;
    }

    /**
     * The probe's own state, excluding the connection it only compares against.
     *
     * @return array<string, mixed>
     */
    private function ownStateOf(GateTrailStatementProbe $probe): array
    {
        $state = [];

        foreach ((new ReflectionClass($probe))->getProperties() as $property) {
            if ($property->getName() === 'connection') {
                continue;
            }

            $state[$property->getName()] = $property->getValue($probe);
        }

        return $state;
    }

    private function queryListenerCount(): int
    {
        return count($this->app['events']->getListeners(QueryExecuted::class));
    }

    private function messageOf(callable $work): string
    {
        try {
            $work();
        } catch (QueryException $exception) {
            return $exception->getMessage();
        }

        $this->fail('The query was expected to fail.');
    }
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Diagnostics;

use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Diagnostics\GateTrailStatementProbe;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Schema;
use PDO;
use ReflectionProperty;

/**
 * Which connection the probe is actually watching.
 *
 * `QueryExecuted` is dispatched through the application's shared event
 * dispatcher, so a listener registered "on a connection" still sees every
 * connection's statements. A probe that filtered by nothing — or by a
 * connection *name*, which two configurations can share — would inflate every
 * budget with whatever else the request happened to run, and would report the
 * wrong number entirely for a package installed on its own connection.
 *
 * So the same two-connection topology `MemoTransactionConnectionTest` uses is
 * set up here: GateTrail on its own SQLite connection, the application default
 * deliberately unrelated. A probe built from `SchemaContext` must then count
 * the first and ignore the second, including inside one closure that touches
 * both.
 */
final class ProbeConnectionIsolationTest extends TestCase
{
    public const APPLICATION_CONNECTION = 'app_default';

    public const GATETRAIL_CONNECTION = 'gatetrail_store';

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', self::APPLICATION_CONNECTION);

        foreach ([self::APPLICATION_CONNECTION, self::GATETRAIL_CONNECTION] as $name) {
            $app['config']->set('database.connections.'.$name, [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);
        }

        $app['config']->set(
            'gatetrail.database.connection',
            self::GATETRAIL_CONNECTION,
        );
    }

    protected function defineDatabaseMigrations(): void
    {
        // The unrelated connection gets one unrelated table, so a query can be
        // executed through it without touching anything GateTrail owns.
        Schema::connection(self::APPLICATION_CONNECTION)->create(
            'application_notes',
            static function (Blueprint $table): void {
                $table->id();
                $table->string('body');
            },
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => self::GATETRAIL_CONNECTION,
        ])->assertExitCode(0);
    }

    public function test_it_resolves_the_configured_gatetrail_connection(): void
    {
        $this->assertSame(
            self::APPLICATION_CONNECTION,
            config('database.default'),
        );
        $this->assertSame(
            self::GATETRAIL_CONNECTION,
            $this->app->make(SchemaContext::class)->connectionName(),
        );
        $this->assertNotSame(
            $this->application(),
            $this->gateTrail(),
            'The two connections must be distinct instances.',
        );

        $observed = (new ReflectionProperty(
            GateTrailStatementProbe::class,
            'connection',
        ))->getValue($this->probe());

        $this->assertSame(
            $this->gateTrail(),
            $observed,
            'The probe watches the configured GateTrail connection.',
        );
        $this->assertNotSame($this->application(), $observed);
    }

    public function test_gatetrail_statements_are_counted(): void
    {
        $measurement = $this->probe()->measure(static function (): void {
            Role::query()->get();
            Role::query()->get();
        });

        $this->assertSame(2, $measurement->total);
        $this->assertSame(2, $measurement->select);
    }

    public function test_a_statement_on_the_application_default_is_ignored(): void
    {
        $measurement = $this->probe()->measure(function (): void {
            $this->application()->table('application_notes')->get();
            $this->application()->table('application_notes')->insert([
                'body' => 'unrelated',
            ]);
        });

        $this->assertSame(
            0,
            $measurement->total,
            'Another connection is not this package to measure.',
        );
        $this->assertSame(0, $measurement->select);
        $this->assertSame(0, $measurement->insert);
    }

    /**
     * The case a name-based filter would still pass and an unfiltered listener
     * would fail: both connections active inside one window.
     */
    public function test_a_mixed_window_reports_only_the_gatetrail_statements(): void
    {
        $measurement = $this->probe()->measure(function (): void {
            $this->application()->table('application_notes')->get();
            Role::query()->get();
            $this->application()->table('application_notes')->get();
            $this->gateTrail()->table('acl_permissions')->get();
            $this->application()->table('application_notes')->insert([
                'body' => 'unrelated',
            ]);
        });

        $this->assertSame(
            2,
            $measurement->total,
            'Three unrelated statements ran in the same window and none counted.',
        );
        $this->assertSame(2, $measurement->select);
        $this->assertSame(0, $measurement->insert);
    }

    /**
     * Identity, not name — the distinction made observable.
     *
     * This impostor is a genuinely separate `Connection` object over a separate
     * in-memory database that **reports the GateTrail connection's name**,
     * because a connection's name is nothing more than a config value. It
     * dispatches through the same application dispatcher, so the probe's
     * listener really does see its statements.
     *
     * A `connectionName` comparison would count every one of them. Comparing
     * the resolved instance counts none.
     */
    public function test_filtering_is_by_connection_identity_not_by_name(): void
    {
        $probe = $this->probe();

        $observed = (new ReflectionProperty(
            GateTrailStatementProbe::class,
            'connection',
        ))->getValue($probe);

        $this->assertInstanceOf(Connection::class, $observed);
        $this->assertSame(self::GATETRAIL_CONNECTION, $observed->getName());

        $impostor = new SQLiteConnection(
            new PDO('sqlite::memory:'),
            ':memory:',
            '',
            ['name' => self::GATETRAIL_CONNECTION, 'driver' => 'sqlite'],
        );
        $impostor->setEventDispatcher($this->app['events']);

        $this->assertSame(
            $observed->getName(),
            $impostor->getName(),
            'The impostor is indistinguishable by name.',
        );
        $this->assertNotSame($observed, $impostor);

        $measurement = $probe->measure(static function () use ($impostor): void {
            $impostor->statement('create table impostor (id integer)');
            $impostor->select('select * from impostor');
        });

        $this->assertSame(
            0,
            $measurement->total,
            'Two statements ran under the GateTrail connection *name* and '
                .'neither counted, because neither ran through the instance.',
        );

        $counted = $probe->measure(static function (): void {
            Role::query()->get();
        });

        $this->assertSame(
            1,
            $counted->total,
            'The real instance still counts, so the filter is discriminating '
                .'rather than simply broken.',
        );
    }

    // ------------------------------------------------- Dispatcher ownership

    /**
     * The regression this class exists for after the Increment 9 correction:
     * a GateTrail connection with a **dispatcher of its own**.
     *
     * Laravel hands every connection it builds the application dispatcher, so
     * in the ordinary configuration "listen on the app dispatcher" and "listen
     * on the connection" are the same act — and a probe registered on the wrong
     * one passes every test. They come apart the moment a consumer extends the
     * manager, replaces a connection or calls `setEventDispatcher()`: the
     * connection then fires `QueryExecuted` somewhere the application
     * dispatcher never sees.
     *
     * The failure mode is the dangerous kind. A probe listening on the
     * application dispatcher does not error — it reports a confident **zero**,
     * which is also the correct answer for a warm memo hit. Every query budget
     * derived from it would be false evidence.
     *
     * So the probe registers through `Connection::listen()`, and this pins it.
     */
    public function test_it_listens_on_a_connection_carrying_its_own_dispatcher(): void
    {
        $gateTrail = $this->gateTrail();
        $application = $this->app['events'];

        // Setup context only: out of the box the connection uses the
        // application dispatcher, which is exactly why the defect is invisible
        // everywhere else.
        $this->assertSame(
            $application,
            $gateTrail->getEventDispatcher(),
            'The default wiring is what hides the bug.',
        );

        $isolated = new Dispatcher($this->app);

        $this->assertNotSame(
            $application,
            $isolated,
            'The connection dispatcher must be a genuinely different object.',
        );

        // Installed *before* the probe is constructed, so the probe has to find
        // it rather than having been handed it.
        $gateTrail->setEventDispatcher($isolated);

        try {
            $this->assertSame($isolated, $gateTrail->getEventDispatcher());
            $this->assertNotSame(
                $isolated,
                $this->application()->getEventDispatcher(),
                'The unrelated connection keeps the application dispatcher.',
            );

            $probe = new GateTrailStatementProbe(
                $this->app->make(DatabaseManager::class),
                $this->app->make(SchemaContext::class),
            );

            $measurement = $probe->measure(function (): void {
                Role::query()->get();
                $this->application()->table('application_notes')->get();
            });

            $this->assertSame(
                1,
                $measurement->total,
                'The GateTrail statement dispatched through the isolated '
                    .'dispatcher and was still counted.',
            );
            $this->assertSame(
                1,
                $measurement->select,
                'And it landed in the correct fixed category.',
            );
            $this->assertSame(0, $measurement->insert);
            $this->assertSame(0, $measurement->schema);
            $this->assertSame(
                0,
                $measurement->other,
                'The statement dispatched on the application dispatcher is '
                    .'ignored, as it is on any other connection.',
            );
        } finally {
            // Restored unconditionally: a leaked dispatcher would silence
            // query events for every later test sharing this connection.
            $gateTrail->setEventDispatcher($application);
        }

        $this->assertSame($application, $this->gateTrail()->getEventDispatcher());
    }

    /**
     * Registering through the connection is not itself a filter. The isolated
     * dispatcher is shared here with an impostor `Connection`, so the probe's
     * listener genuinely fires for it — and object identity is the only thing
     * that rejects it.
     */
    public function test_identity_filtering_is_still_required_on_a_shared_dispatcher(): void
    {
        $gateTrail = $this->gateTrail();
        $application = $this->app['events'];
        $isolated = new Dispatcher($this->app);

        $gateTrail->setEventDispatcher($isolated);

        try {
            $probe = new GateTrailStatementProbe(
                $this->app->make(DatabaseManager::class),
                $this->app->make(SchemaContext::class),
            );

            $impostor = new SQLiteConnection(
                new PDO('sqlite::memory:'),
                ':memory:',
                '',
                ['name' => self::GATETRAIL_CONNECTION, 'driver' => 'sqlite'],
            );

            // The same dispatcher the probe registered on, so the callback is
            // really invoked for these statements.
            $impostor->setEventDispatcher($isolated);

            $measurement = $probe->measure(static function () use ($impostor): void {
                $impostor->statement('create table impostor (id integer)');
                $impostor->select('select * from impostor');
            });

            $this->assertSame(
                0,
                $measurement->total,
                'Right dispatcher, wrong Connection: identity rejects it.',
            );

            $counted = $probe->measure(static function (): void {
                Role::query()->get();
            });

            $this->assertSame(1, $counted->total);
        } finally {
            $gateTrail->setEventDispatcher($application);
        }
    }

    private function probe(): GateTrailStatementProbe
    {
        return new GateTrailStatementProbe(
            $this->app->make(DatabaseManager::class),
            $this->app->make(SchemaContext::class),
        );
    }

    private function application(): Connection
    {
        /** @var Connection */
        return $this->app['db']->connection(self::APPLICATION_CONNECTION);
    }

    private function gateTrail(): Connection
    {
        /** @var Connection */
        return $this->app['db']->connection(self::GATETRAIL_CONNECTION);
    }
}

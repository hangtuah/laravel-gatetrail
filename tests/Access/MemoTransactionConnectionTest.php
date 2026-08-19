<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Access;

use DateTimeImmutable;
use DateTimeZone;
use HangTuah\GateTrail\Access\EffectiveAccessMemo;
use HangTuah\GateTrail\Access\EffectiveAccessResolver;
use HangTuah\GateTrail\Access\EffectiveAccessSnapshot;
use HangTuah\GateTrail\Concerns\HasGateTrail;
use HangTuah\GateTrail\Contracts\PermissionResolver;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Events\MutationCommitted;
use HangTuah\GateTrail\Models\AccessAudit;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\PermissionAssignment;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\Models\RoleAssignment;
use HangTuah\GateTrail\Models\RolePermission;
use HangTuah\GateTrail\Tests\Fixtures\MutableClock;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Psr\Clock\ClockInterface;
use ReflectionProperty;

/**
 * Whose transaction level decides the memo bypass.
 *
 * Every other memoization test runs GateTrail on the application's default
 * connection, where `connection()` and `connection(SchemaContext::…)` return
 * the same object — so they cannot tell the two apart, and a regression to the
 * application default would pass all of them.
 *
 * This class separates them. GateTrail is installed on its own SQLite
 * connection while the application default stays deliberately unrelated and
 * empty, so the two `Connection` objects are genuinely distinct instances over
 * distinct in-memory databases. The bypass decision in
 * {@see EffectiveAccessResolver} then has an observable owner:
 *
 * | Transaction open on | Expected |
 * |---|---|
 * | Application default only | Memo is **used** — zero statements |
 * | Configured GateTrail connection only | Memo is **bypassed** — fresh build |
 *
 * A resolver reading the application default would invert both rows: it would
 * throw away a valid memo because some unrelated connection is mid-transaction,
 * and it would serve a committed memo to a caller that is inside a GateTrail
 * transaction reading its own uncommitted writes — the exact staleness ADR 0012
 * section 9.4 forbids.
 *
 * Statement counts are attributed per connection, so "zero additional
 * statements" is a claim about the GateTrail connection specifically, and every
 * case also asserts that nothing leaked onto the application default.
 *
 * @see docs/decisions/0012-effective-access-resolution.md section 9.4
 */
final class MemoTransactionConnectionTest extends TestCase
{
    /**
     * The application's default connection. Unrelated to GateTrail on purpose:
     * it carries no GateTrail table and no migration repository.
     */
    public const APPLICATION_CONNECTION = 'app_default';

    /**
     * The connection `gatetrail.database.connection` points at. Every GateTrail
     * table, the migration repository and the test-subject table live here.
     */
    public const GATETRAIL_CONNECTION = 'gatetrail_store';

    private DateTimeImmutable $time;

    private MutableClock $clock;

    private ForeignConnectionSubject $subject;

    private Role $role;

    private Permission $permission;

    /**
     * Two SQLite connections, established before the container resolves
     * `SchemaContext`, any model, the resolver or a migration — all of which
     * read `gatetrail.database.connection` at first resolution.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', self::APPLICATION_CONNECTION);

        $app['config']->set('database.connections.'.self::APPLICATION_CONNECTION, [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('database.connections.'.self::GATETRAIL_CONNECTION, [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set(
            'gatetrail.database.connection',
            self::GATETRAIL_CONNECTION,
        );
    }

    /**
     * The subject table belongs to the GateTrail connection here, so that a
     * check cannot touch the application default even incidentally.
     */
    protected function defineDatabaseMigrations(): void
    {
        Schema::connection(self::GATETRAIL_CONNECTION)->create(
            'test_subjects',
            static function (Blueprint $table): void {
                $table->id();
                $table->string('name');
            },
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        // `--database` places the migration repository on the GateTrail
        // connection too; the GateTrail migrations resolve their own
        // connection from SchemaContext regardless.
        $this->artisan('migrate', [
            '--database' => self::GATETRAIL_CONNECTION,
        ])->assertExitCode(0);

        $this->time = new DateTimeImmutable(
            '2026-08-19 09:30:00',
            new DateTimeZone('UTC'),
        );
        $this->clock = new MutableClock($this->time);
        $this->app->instance(ClockInterface::class, $this->clock);

        $this->role = Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'web',
        ]);
        $this->permission = Permission::query()->create([
            'name' => 'documents.approve',
            'guard_name' => 'web',
        ]);
        $this->subject = ForeignConnectionSubject::query()->create([
            'name' => 'Subject',
        ]);

        $this->assignRole();
    }

    // ------------------------------------------------------ Wiring evidence

    public function test_the_two_connections_are_separate_and_correctly_pointed(): void
    {
        $this->assertSame(
            self::APPLICATION_CONNECTION,
            config('database.default'),
            'The application default must be the unrelated connection.',
        );
        $this->assertSame(
            self::GATETRAIL_CONNECTION,
            config('gatetrail.database.connection'),
        );
        $this->assertSame(
            self::GATETRAIL_CONNECTION,
            $this->app->make(SchemaContext::class)->connectionName(),
        );

        $application = $this->application();
        $gateTrail = $this->gateTrail();

        $this->assertInstanceOf(Connection::class, $application);
        $this->assertInstanceOf(Connection::class, $gateTrail);
        $this->assertNotSame(
            $application,
            $gateTrail,
            'The two connections must not be aliases for one Connection.',
        );
        $this->assertNotSame(
            $application->getName(),
            $gateTrail->getName(),
        );
    }

    public function test_every_gatetrail_model_reports_the_configured_connection(): void
    {
        $models = [
            Role::class,
            Permission::class,
            RoleAssignment::class,
            PermissionAssignment::class,
            RolePermission::class,
            AccessAudit::class,
        ];

        foreach ($models as $class) {
            $model = new $class;

            $this->assertSame(
                self::GATETRAIL_CONNECTION,
                $model->getConnectionName(),
                $class.' must resolve the configured GateTrail connection.',
            );
            $this->assertSame(
                $this->gateTrail(),
                $model->getConnection(),
                $class.' must query the GateTrail Connection instance.',
            );
        }

        $this->assertSame(
            self::GATETRAIL_CONNECTION,
            $this->subject->getConnectionName(),
        );
    }

    /**
     * The application default is unrelated in the strongest available sense:
     * no GateTrail table was ever created on it.
     */
    public function test_the_application_default_carries_no_gatetrail_table(): void
    {
        foreach (['acl_roles', 'acl_permissions', 'acl_role_assignments', 'acl_role_permissions'] as $table) {
            $this->assertFalse(
                Schema::connection(self::APPLICATION_CONNECTION)->hasTable($table),
                $table.' must not exist on the application default.',
            );
            $this->assertTrue(
                Schema::connection(self::GATETRAIL_CONNECTION)->hasTable($table),
                $table.' must exist on the GateTrail connection.',
            );
        }
    }

    // ------------------------------------------- Case 1: unrelated default

    /**
     * An open transaction on the application's default connection is none of
     * GateTrail's business. The memo stays in force.
     */
    public function test_an_unrelated_application_transaction_does_not_disable_the_memo(): void
    {
        $cold = $this->captureStatements(
            fn () => $this->permissions()->allows($this->subject, 'documents.approve'),
        );

        $this->assertCount(3, $cold['gatetrail'], 'A cold build is three statements.');
        $this->assertSame([], $cold['application']);
        $this->assertSame(1, $this->memoSize());

        $before = $this->memoEntries();
        $instantBefore = $this->frozenSecond();

        $this->application()->beginTransaction();

        try {
            $this->assertSame(1, $this->application()->transactionLevel());
            $this->assertSame(
                0,
                $this->gateTrail()->transactionLevel(),
                'The GateTrail connection is untouched by the caller.',
            );

            $warm = $this->captureStatements(
                fn () => $this->permissions()->allows($this->subject, 'documents.approve'),
            );

            $this->assertSame(
                [],
                $warm['gatetrail'],
                'A memo hit executes no statement on the GateTrail connection.',
            );
            $this->assertSame(
                [],
                $warm['application'],
                'Nothing may leak onto the application default.',
            );

            $this->assertSame(1, $this->memoSize());
            $this->assertSame(
                $before,
                $this->memoEntries(),
                'No snapshot was built, stored, replaced or evicted.',
            );
            $this->assertSame($instantBefore, $this->frozenSecond());

            $this->assertSame(
                1,
                $this->application()->transactionLevel(),
                'The application transaction is still open.',
            );
            $this->assertSame(0, $this->gateTrail()->transactionLevel());
        } finally {
            $this->application()->rollBack();
        }

        $this->assertSame(0, $this->application()->transactionLevel());
        $this->assertSame(0, $this->gateTrail()->transactionLevel());
        $this->assertSame(
            $before,
            $this->memoEntries(),
            'The rollback of an unrelated transaction changes nothing here.',
        );
    }

    // ------------------------------------ Case 2: configured GateTrail lane

    /**
     * The converse, with the application default idle: a transaction on the
     * GateTrail connection bypasses the memo, and the committed entry it
     * bypassed is still there afterwards.
     *
     * The uncommitted grant inside the transaction makes the bypass
     * behavioural rather than merely countable — the committed memo says
     * denied and the transaction-visible build says allowed, so a memo hit
     * could not be mistaken for a fresh build.
     */
    public function test_a_gatetrail_transaction_bypasses_the_memo_while_the_default_is_idle(): void
    {
        $events = $this->recordCommittedMutations();

        $this->assertFalse(
            $this->permissions()->allows($this->subject, 'documents.approve'),
            'Committed state grants the role but not the permission.',
        );
        $this->assertSame(1, $this->memoSize());

        $before = $this->memoEntries();
        $memoized = $this->onlyEntry($before);
        $instantBefore = $this->frozenSecond();

        $this->gateTrail()->beginTransaction();

        try {
            $this->assertSame(1, $this->gateTrail()->transactionLevel());
            $this->assertSame(
                0,
                $this->application()->transactionLevel(),
                'The application default stays outside a transaction.',
            );

            // A direct fixture write, not a managed mutation: it goes through
            // no handler, opens no audited transaction and therefore emits no
            // MutationCommitted. It exists only to give the transaction some
            // uncommitted state of its own to see.
            $this->grantToRole();

            $inside = $this->captureStatements(
                fn () => $this->permissions()->allows($this->subject, 'documents.approve'),
            );

            $this->assertTrue(
                $this->permissions()->allows($this->subject, 'documents.approve'),
                'The transaction observes its own uncommitted grant.',
            );
            $this->assertCount(
                3,
                $inside['gatetrail'],
                'A bypass rebuilds from transaction-visible state.',
            );
            $this->assertSame([], $inside['application']);

            $fresh = $this->app->make(EffectiveAccessResolver::class)
                ->resolve($this->subject);

            $this->assertNotSame(
                $memoized,
                $fresh,
                'The bypass returns a new snapshot, never the memoized one.',
            );
            $this->assertEquals(
                $memoized->evaluatedAt(),
                $fresh->evaluatedAt(),
                'Both were evaluated in the same UTC second, so the rebuild '
                    .'is the transaction bypass and not a second boundary.',
            );
            $this->assertTrue($fresh->hasPermissionNamed('documents.approve'));
            $this->assertFalse($memoized->hasPermissionNamed('documents.approve'));

            $this->assertSame(1, $this->memoSize());
            $this->assertSame(
                $before,
                $this->memoEntries(),
                'The transaction-local snapshot is not stored, and the '
                    .'pre-transaction entry is not promoted, replaced or evicted.',
            );
            $this->assertSame($instantBefore, $this->frozenSecond());
            $this->assertSame(1, $this->gateTrail()->transactionLevel());
            $this->assertSame(0, $this->application()->transactionLevel());
        } finally {
            $this->gateTrail()->rollBack();
        }

        $this->assertSame(0, $this->gateTrail()->transactionLevel());
        $this->assertSame(0, $this->application()->transactionLevel());
        $this->assertSame(
            [],
            $events(),
            'A rollback emits no MutationCommitted, so nothing flushed the memo.',
        );
        $this->assertSame(
            $before,
            $this->memoEntries(),
            'The committed entry survived the whole transaction.',
        );

        $warm = $this->captureStatements(
            fn () => $this->assertFalse(
                $this->permissions()->allows($this->subject, 'documents.approve'),
                'The rolled-back grant is gone and the committed memo answers.',
            ),
        );

        $this->assertSame(
            [],
            $warm['gatetrail'],
            'The preserved entry is still warm: zero statements.',
        );
        $this->assertSame([], $warm['application']);
        $this->assertSame($instantBefore, $this->frozenSecond());
    }

    // ------------------------------------------------------------- Helpers

    private function permissions(): PermissionResolver
    {
        return $this->app->make(PermissionResolver::class);
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

    private function frozenSecond(): string
    {
        return $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
    }

    /**
     * Statements split by the connection that executed them, so a query count
     * is always a claim about one connection.
     *
     * @return array{gatetrail: list<string>, application: list<string>}
     */
    private function captureStatements(callable $work): array
    {
        $statements = ['gatetrail' => [], 'application' => []];

        $this->app['db']->listen(
            function (QueryExecuted $query) use (&$statements): void {
                $lane = $query->connectionName === self::GATETRAIL_CONNECTION
                    ? 'gatetrail'
                    : 'application';

                $statements[$lane][] = $query->sql;
            },
        );

        $work();

        // Laravel has no public listener removal. The array is returned by
        // value, so a listener left over from an earlier call can only append
        // to its own already-copied array.
        return $statements;
    }

    /**
     * @return callable(): list<MutationCommitted>
     */
    private function recordCommittedMutations(): callable
    {
        $events = [];

        Event::listen(
            MutationCommitted::class,
            function (MutationCommitted $event) use (&$events): void {
                $events[] = $event;
            },
        );

        return static fn (): array => $events;
    }

    /**
     * @return array<string, EffectiveAccessSnapshot>
     */
    private function memoEntries(): array
    {
        $memo = $this->app->make(EffectiveAccessMemo::class);

        /** @var array<string, EffectiveAccessSnapshot> $entries */
        $entries = (new ReflectionProperty($memo, 'entries'))->getValue($memo);

        return $entries;
    }

    private function memoSize(): int
    {
        return count($this->memoEntries());
    }

    /**
     * @param  array<string, EffectiveAccessSnapshot>  $entries
     */
    private function onlyEntry(array $entries): EffectiveAccessSnapshot
    {
        $this->assertCount(1, $entries);

        return reset($entries);
    }

    private function assignRole(): void
    {
        $this->gateTrail()->table('acl_role_assignments')->insert([
            'subject_type' => $this->subject->getMorphClass(),
            'subject_id' => $this->subject->getKey(),
            'role_id' => $this->role->getKey(),
            'assigned_at' => '2026-08-19 08:00:00',
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'test-suite',
            'created_at' => '2026-08-19 08:00:00',
            'updated_at' => '2026-08-19 08:00:00',
        ]);
    }

    private function grantToRole(): void
    {
        $this->gateTrail()->table('acl_role_permissions')->insert([
            'role_id' => $this->role->getKey(),
            'permission_id' => $this->permission->getKey(),
            'assigned_at' => '2026-08-19 09:00:00',
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'test-suite',
            'created_at' => '2026-08-19 09:00:00',
            'updated_at' => '2026-08-19 09:00:00',
        ]);
    }
}

/**
 * A subject that lives on the GateTrail connection rather than the
 * application default, so no part of a check can reach the unrelated
 * connection even incidentally.
 */
final class ForeignConnectionSubject extends Model
{
    use HasGateTrail;

    public $timestamps = false;

    protected $connection = MemoTransactionConnectionTest::GATETRAIL_CONNECTION;

    protected $table = 'test_subjects';

    protected $guarded = [];

    protected string $gateTrailGuardName = 'web';
}

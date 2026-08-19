<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Access;

use HangTuah\GateTrail\Contracts\PermissionResolver;
use HangTuah\GateTrail\Contracts\RoleResolver;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use HangTuah\GateTrail\Tests\Integration\Concerns\AssertsResolverAcceptance;
use HangTuah\GateTrail\Tests\Integration\Support\ResolverAcceptanceFixture;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Psr\Clock\ClockInterface;

/**
 * The SQLite half of ADR 0012's acceptance matrix, and the reference answer the
 * live engines are compared against.
 *
 * This is the *same* fixture and the *same* assertions that
 * `tests/Integration/MySql/ResolverAcceptanceTest.php` and
 * `tests/Integration/Oracle/ResolverAcceptanceTest.php` run. It is not a
 * duplicate of the default suite — that suite remains the authoritative
 * behavioural matrix, and nothing here restates it. What this adds is the
 * canonical result: one dataset, one expected ordered collection, run on the
 * engine every other engine has to agree with.
 *
 * If this test and a live-engine test ever disagree, exactly one of two things
 * is true — the algorithm is engine-dependent, or the expectation drifted — and
 * because both sides read the expectation from
 * {@see ResolverAcceptanceFixture}, only the first is possible.
 *
 * @see docs/decisions/0012-effective-access-resolution.md section 12.2
 */
final class ResolverAcceptanceParityTest extends TestCase
{
    use AssertsResolverAcceptance;

    private ResolverAcceptanceFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--database' => 'testing'])->assertExitCode(0);

        $this->app->instance(
            ClockInterface::class,
            new FrozenClock(ResolverAcceptanceFixture::evaluatedAt()),
        );

        $this->fixture = new ResolverAcceptanceFixture(
            $this->connection(),
            $this->app->make(SchemaContext::class),
        );

        $this->fixture->installSubjectTable();
        $this->fixture->seed();
    }

    protected function tearDown(): void
    {
        if (isset($this->app, $this->fixture)) {
            $this->fixture->dropSubjectTable();
        }

        parent::tearDown();
    }

    public function test_the_shared_acceptance_matrix_holds_on_sqlite(): void
    {
        $this->assertSharedResolverAcceptance();
    }

    /**
     * Pinned separately from the shared assertions, because this is the value
     * the live engines are measured against rather than a behaviour of SQLite.
     * If the expectation is ever edited, this fails first and everywhere.
     */
    public function test_the_canonical_expectation_is_what_this_engine_produced(): void
    {
        $this->forgetAcceptanceMemo();

        $this->assertSame(
            [
                'documents.approve',
                'documents.publish',
                'documents.review',
                'lifecycle.at-start',
                'lifecycle.expires-next',
                'lifecycle.null-start',
            ],
            $this->permissionResolver()
                ->effectivePermissions($this->acceptanceSubject())
                ->map(static fn ($permission): string => $permission->name)
                ->all(),
        );

        $this->assertSame(
            ['auditor', 'editor', 'revoked-grant'],
            $this->roleResolver()
                ->effectiveRoles($this->acceptanceSubject())
                ->map(static fn ($role): string => $role->name)
                ->all(),
        );
    }

    /**
     * The fixture's own integrity: the rows that must be excluded have to
     * actually exist, or every exclusion assertion in the shared matrix would
     * pass vacuously on every engine.
     */
    public function test_the_excluded_rows_are_really_present_in_the_database(): void
    {
        $schema = $this->app->make(SchemaContext::class);

        $this->assertSame(
            10,
            $this->connection()->table($schema->table('roles'))->count(),
        );
        $this->assertSame(
            22,
            $this->connection()->table($schema->table('permissions'))->count(),
        );

        foreach ([
            'documents.archive' => 'deleted_at',
            'documents.case-guard' => 'guard_name',
            'documents.pad-guard' => 'guard_name',
        ] as $name => $column) {
            $this->assertNotNull(
                $this->connection()
                    ->table($schema->table('permissions'))
                    ->where('name', $name)
                    ->value($column),
                sprintf(
                    'The excluded permission [%s] must exist and carry a '
                        .'non-null [%s], or its exclusion proves nothing.',
                    $name,
                    $column,
                ),
            );
        }

        $this->assertSame(
            9,
            $this->connection()->table($schema->table('role_assignments'))->count(),
        );
        $this->assertSame(
            12,
            $this->connection()
                ->table($schema->table('permission_assignments'))
                ->count(),
        );
        $this->assertSame(
            11,
            $this->connection()->table($schema->table('role_permissions'))->count(),
        );
    }

    protected function permissionResolver(): PermissionResolver
    {
        return $this->app->make(PermissionResolver::class);
    }

    protected function roleResolver(): RoleResolver
    {
        return $this->app->make(RoleResolver::class);
    }

    private function connection(): Connection
    {
        /** @var Connection */
        return DB::connection('testing');
    }
}

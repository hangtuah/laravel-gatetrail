<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Integration\Oracle;

use HangTuah\GateTrail\Contracts\PermissionResolver;
use HangTuah\GateTrail\Contracts\RoleResolver;
use HangTuah\GateTrail\Database\Schema\Manifest\SchemaManifest;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use HangTuah\GateTrail\Tests\Integration\Concerns\AssertsResolverAcceptance;
use HangTuah\GateTrail\Tests\Integration\Support\ResolverAcceptanceFixture;
use Psr\Clock\ClockInterface;

/**
 * The live Oracle half of ADR 0012's acceptance matrix.
 *
 * Same {@see ResolverAcceptanceFixture}, same
 * {@see AssertsResolverAcceptance} assertions as SQLite and MySQL — so a pass
 * here is a parity claim, not an "it ran" claim. It executes against live
 * Oracle Free 23.26.1 through the existing cost-controlled harness in
 * `tools/oracle-integration`, in both dependency lanes (Laravel 12 / OCI8 12
 * and Laravel 13 / OCI8 13).
 *
 * What Oracle uniquely exercises, and why each one matters:
 *
 * - **Schema qualification.** `gatetrail.database.schema = GATETRAIL`, so every
 *   table the resolver names is `GATETRAIL.acl_*`. MySQL contributes the prefix
 *   evidence; Oracle contributes the schema evidence, and between them the
 *   portability claim of section 12.2 is covered without forcing a prefix that
 *   would push generated sequence and trigger names past Oracle's identifier
 *   bound.
 * - **Alias survival through OCI8.** Oracle upper-cases unquoted identifiers
 *   and the driver maps result columns itself. The three fixed aliases have to
 *   survive that round trip or the row mapper reads nothing — which would look
 *   like a correct empty answer.
 * - **Identifier normalization.** Oracle returns `NUMBER` values through OCI8
 *   in a form that is not necessarily a PHP `int`. The shared assertions
 *   require `int`, so a driver that hands back `'101'` or `101.0` fails here.
 * - **Real `DATE` columns.** Every lifecycle boundary is bound as a
 *   `DateTimeImmutable` and formatted by the connection's own grammar against
 *   Oracle's NLS session format — no string comparison shortcut, and the
 *   half-open boundaries are checked against the engine's own date type.
 *
 * The >900-role chunk case is deliberately **not** repeated here. Section 12.2
 * asks for it on whichever live engine can host it economically, and MySQL's
 * four already-provisioned lanes are that engine; the chunk bound is one fixed
 * constant on every engine, asserted as such.
 *
 * Schema is installed by the package's own migrations under the
 * `sequence_trigger` generated-key strategy. The fixture uses explicit
 * identifiers throughout, so resolver correctness cannot depend on which
 * generated-key strategy is configured — `PackageManagedIdentityTest` and
 * `PackageManagedSequenceTriggerTest` keep covering both strategies at the
 * schema level, which is where the difference actually lives.
 *
 * @see docs/decisions/0012-effective-access-resolution.md section 12
 */
final class ResolverAcceptanceTest extends OracleIntegrationTestCase
{
    use AssertsResolverAcceptance;

    private ResolverAcceptanceFixture $fixture;

    public function test_the_shared_acceptance_matrix_holds_on_live_oracle(): void
    {
        $this->assertSharedResolverAcceptance();
    }

    /**
     * Every resolver table is schema-qualified, and the live objects really do
     * exist under that schema.
     */
    public function test_every_resolver_table_is_schema_qualified(): void
    {
        $context = $this->app->make(SchemaContext::class);

        $this->assertSame('GATETRAIL', $context->schemaName());

        foreach ($this->app->make(SchemaManifest::class)->creationOrder() as $key) {
            $this->assertStringStartsWith(
                'GATETRAIL.',
                $context->table($key),
                'The resolver names this table through its configured schema.',
            );
            $this->assertTrue(
                $this->objectExists(
                    'user_tables',
                    'table_name',
                    $context->tableName($key),
                ),
            );
        }

        // And the schema-qualified names are what actually executed.
        $this->assertEffectiveCollectionsMatchExactly();
    }

    /**
     * The selected aliases survive OCI8's own result mapping, and the values
     * behind them normalize to the PHP types the snapshot promises.
     *
     * Asserted through the resolver rather than by reading raw rows, because
     * the alias only matters if the row mapper can find it.
     */
    public function test_aliases_and_identifier_types_survive_the_oci8_round_trip(): void
    {
        $this->forgetAcceptanceMemo();

        $permissions = $this->permissionResolver()
            ->effectivePermissions($this->acceptanceSubject());
        $roles = $this->roleResolver()
            ->effectiveRoles($this->acceptanceSubject());

        $this->assertNotEmpty($permissions);
        $this->assertNotEmpty($roles);

        foreach ([...$permissions->all(), ...$roles->all()] as $entry) {
            $this->assertIsInt(
                $entry->id,
                'An Oracle NUMBER must normalize to a PHP int.',
            );
            $this->assertNotSame('', $entry->name, 'The name alias resolved.');
            $this->assertSame(
                ResolverAcceptanceFixture::GUARD,
                $entry->guardName,
                'The guard alias resolved, byte-for-byte.',
            );
        }

        $this->assertSame(
            ResolverAcceptanceFixture::PERMISSION_APPROVE,
            $permissions->first()?->id,
            'The identifier alias carried the explicit fixture identifier.',
        );
    }

    /**
     * The half-open boundaries against Oracle's own date type, restated here
     * because "it works on a real `DATE` column" is the whole reason the live
     * lane exists.
     */
    public function test_lifecycle_boundaries_hold_against_oracle_date_columns(): void
    {
        $this->forgetAcceptanceMemo();

        foreach ([
            'lifecycle.at-start' => true,
            'lifecycle.expires-next' => true,
            'lifecycle.null-start' => true,
            'lifecycle.before-start' => false,
            'lifecycle.expires-now' => false,
            'lifecycle.expired' => false,
            'lifecycle.null-start-future' => false,
        ] as $name => $expected) {
            $this->assertSame(
                $expected,
                $this->permissionResolver()->allows(
                    $this->acceptanceSubject(),
                    $name,
                ),
                sprintf('[%s] against a live Oracle DATE column.', $name),
            );
        }
    }

    protected function provisioningMode(): string
    {
        return 'package';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => self::CONNECTION,
        ])->assertExitCode(0);

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

    protected function permissionResolver(): PermissionResolver
    {
        return $this->app->make(PermissionResolver::class);
    }

    protected function roleResolver(): RoleResolver
    {
        return $this->app->make(RoleResolver::class);
    }
}

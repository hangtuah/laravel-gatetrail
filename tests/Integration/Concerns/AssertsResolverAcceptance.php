<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Integration\Concerns;

use HangTuah\GateTrail\Access\EffectiveAccessMemo;
use HangTuah\GateTrail\Contracts\PermissionResolver;
use HangTuah\GateTrail\Contracts\RoleResolver;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Diagnostics\GateTrailStatementProbe;
use HangTuah\GateTrail\Diagnostics\StatementMeasurement;
use HangTuah\GateTrail\PermissionReference;
use HangTuah\GateTrail\RoleReference;
use HangTuah\GateTrail\Tests\Integration\Support\AcceptanceSubject;
use HangTuah\GateTrail\Tests\Integration\Support\ResolverAcceptanceFixture;
use Illuminate\Database\DatabaseManager;

/**
 * The assertions every engine in the acceptance matrix runs, written once.
 *
 * A test using this trait supplies only the environment: which connection, how
 * the schema got installed, which driver. The **authorization facts** come from
 * here and from {@see ResolverAcceptanceFixture}, so "MySQL agrees with SQLite"
 * is a claim about one expectation checked twice rather than two expectations
 * that happen to be written similarly.
 *
 * Nothing in here is engine-aware. There is no driver branch, no per-engine
 * tolerance and no relaxed comparison: an engine that disagrees fails, and the
 * failure message names the case.
 */
trait AssertsResolverAcceptance
{
    /**
     * The subject rows, loaded once.
     *
     * Cached deliberately rather than re-read: `find()` is itself a statement,
     * and a subject lookup inside a measured window would be counted as part
     * of the resolver's budget and turn a correct 3 into a 4.
     *
     * @var array<int, AcceptanceSubject>
     */
    private array $acceptanceSubjects = [];

    /**
     * The whole shared matrix, in the order a reader would want to debug it:
     * membership first, then parity, then exclusions, then the query budget.
     */
    protected function assertSharedResolverAcceptance(): void
    {
        $this->assertEffectiveCollectionsMatchExactly();
        $this->assertBooleanChecksAgreeWithCollections();
        $this->assertNameComparisonIsByteExact();
        $this->assertEveryExclusionHolds();
        $this->assertRolelessSubjectIsDenied();
        $this->assertColdAndWarmQueryBudget();
    }

    // ------------------------------------------------------ Membership

    /**
     * Identifier, name, guard, order and cardinality — all four, because three
     * of them can be right while the fourth is silently engine-specific.
     */
    protected function assertEffectiveCollectionsMatchExactly(): void
    {
        $this->forgetAcceptanceMemo();

        $permissions = $this->permissionResolver()
            ->effectivePermissions($this->acceptanceSubject())
            ->map(static fn ($permission): array => [
                'id' => $permission->id,
                'name' => $permission->name,
                'guardName' => $permission->guardName,
            ])
            ->all();

        $roles = $this->roleResolver()
            ->effectiveRoles($this->acceptanceSubject())
            ->map(static fn ($role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'guardName' => $role->guardName,
            ])
            ->all();

        $this->assertSame(
            ResolverAcceptanceFixture::expectedPermissions(),
            $permissions,
            'The effective permissions must match the shared expectation '
                .'exactly: same identifiers, same names, same guards, same '
                .'raw-byte order, and no duplicate.',
        );
        $this->assertSame(
            ResolverAcceptanceFixture::expectedRoles(),
            $roles,
            'The effective roles must match the shared expectation exactly, '
                .'including the role that grants no permission.',
        );

        // Deduplication, stated as its own claim: `documents.approve` is
        // reachable directly and through `editor`.
        $approvals = array_filter(
            $permissions,
            static fn (array $permission): bool => $permission['name'] === 'documents.approve',
        );

        $this->assertCount(
            1,
            $approvals,
            'A permission reachable directly and through a role appears once.',
        );

        // Identifiers arrive as PHP integers, whatever the driver returned —
        // Oracle hands back NUMBER values that could otherwise surface as
        // strings or floats.
        foreach ([...$permissions, ...$roles] as $entry) {
            $this->assertIsInt(
                $entry['id'],
                'Identifiers must normalize to int on every driver.',
            );
        }
    }

    // --------------------------------------------------------- Parity

    /**
     * `allows()` is true for exactly the permissions in
     * `effectivePermissions()`, and `has()` for exactly the roles in
     * `effectiveRoles()` — by name **and** by reference identifier, which is
     * also the assertion that a reference costs no extra definition lookup.
     */
    protected function assertBooleanChecksAgreeWithCollections(): void
    {
        $this->forgetAcceptanceMemo();

        foreach (ResolverAcceptanceFixture::expectedPermissions() as $permission) {
            $this->assertTrue(
                $this->permissionResolver()->allows(
                    $this->acceptanceSubject(),
                    $permission['name'],
                ),
                'allows() must agree with effectivePermissions() for '
                    .$permission['name'],
            );
            $this->assertTrue(
                $this->permissionResolver()->allows(
                    $this->acceptanceSubject(),
                    PermissionReference::id($permission['id']),
                ),
                'A reference must reach the same permission as its name.',
            );
        }

        foreach (ResolverAcceptanceFixture::expectedRoles() as $role) {
            $this->assertTrue(
                $this->roleResolver()->has(
                    $this->acceptanceSubject(),
                    $role['name'],
                ),
            );
            $this->assertTrue(
                $this->roleResolver()->has(
                    $this->acceptanceSubject(),
                    RoleReference::id($role['id']),
                ),
            );
        }
    }

    // ---------------------------------------------------- Byte exactness

    /**
     * The comparison happens in PHP over the snapshot, so it must stay
     * byte-exact even where the engine's own collation is case-insensitive and
     * pads trailing spaces. `documents.approve` **is** effective, which is what
     * makes every near-miss below a real test rather than a trivially absent
     * name.
     */
    protected function assertNameComparisonIsByteExact(): void
    {
        $this->forgetAcceptanceMemo();

        $this->assertTrue(
            $this->permissionResolver()->allows(
                $this->acceptanceSubject(),
                'documents.approve',
            ),
            'The exact name must match, or the near-misses prove nothing.',
        );

        foreach (ResolverAcceptanceFixture::byteExactMisses() as $miss) {
            $this->assertFalse(
                $this->permissionResolver()->allows(
                    $this->acceptanceSubject(),
                    $miss,
                ),
                sprintf(
                    'The name [%s] differs from documents.approve in bytes and '
                        .'must not match.',
                    $miss,
                ),
            );
        }

        $this->assertTrue(
            $this->roleResolver()->has($this->acceptanceSubject(), 'editor'),
        );

        foreach (['EDITOR', 'Editor', ' editor', 'editor '] as $miss) {
            $this->assertFalse(
                $this->roleResolver()->has($this->acceptanceSubject(), $miss),
                sprintf('The role name [%s] must not match [editor].', $miss),
            );
        }
    }

    // ------------------------------------------------------- Exclusions

    /**
     * Every documented denial, each with the reason it is denied, so a widened
     * engine fails with a message that says which rule it widened.
     */
    protected function assertEveryExclusionHolds(): void
    {
        $this->forgetAcceptanceMemo();

        foreach (ResolverAcceptanceFixture::deniedPermissions() as $name => $reason) {
            $this->assertFalse(
                $this->permissionResolver()->allows(
                    $this->acceptanceSubject(),
                    $name,
                ),
                sprintf('[%s] must be denied because %s.', $name, $reason),
            );
        }

        foreach (ResolverAcceptanceFixture::deniedRoles() as $name => $reason) {
            $this->assertFalse(
                $this->roleResolver()->has($this->acceptanceSubject(), $name),
                sprintf('[%s] must be denied because %s.', $name, $reason),
            );
        }

        // And none of them leaked into the collections either, which is the
        // same rule asserted from the other direction.
        $effective = $this->permissionResolver()
            ->effectivePermissions($this->acceptanceSubject())
            ->map(static fn ($permission): string => $permission->name)
            ->all();

        foreach (array_keys(ResolverAcceptanceFixture::deniedPermissions()) as $name) {
            $this->assertNotContains($name, $effective);
        }
    }

    protected function assertRolelessSubjectIsDenied(): void
    {
        $this->forgetAcceptanceMemo();

        $subject = $this->rolelessAcceptanceSubject();

        $this->assertSame(
            [],
            $this->roleResolver()->effectiveRoles($subject)->all(),
        );
        $this->assertSame(
            [],
            $this->permissionResolver()->effectivePermissions($subject)->all(),
        );
        $this->assertFalse(
            $this->permissionResolver()->allows($subject, 'documents.approve'),
            'Default deny: another subject\'s grants are not this one\'s.',
        );
    }

    // ----------------------------------------------------- Query budget

    /**
     * ADR 0012 section 6.3, measured on the live connection through
     * `GateTrailStatementProbe` rather than self-reported.
     */
    protected function assertColdAndWarmQueryBudget(): void
    {
        $probe = $this->acceptanceProbe();

        // Both subjects are loaded before any window opens, so the only
        // statements a window can observe are the resolver's own.
        $this->acceptanceSubject();
        $this->rolelessAcceptanceSubject();

        $this->forgetAcceptanceMemo();

        $cold = $probe->measure(function (): void {
            $this->permissionResolver()->allows(
                $this->acceptanceSubject(),
                'documents.approve',
            );
        });

        $warm = $probe->measure(function (): void {
            for ($index = 0; $index < 5; $index++) {
                $this->permissionResolver()->allows(
                    $this->acceptanceSubject(),
                    'documents.approve',
                );
                $this->roleResolver()->has($this->acceptanceSubject(), 'editor');
            }

            $this->permissionResolver()
                ->effectivePermissions($this->acceptanceSubject());
            $this->roleResolver()
                ->effectiveRoles($this->acceptanceSubject());
        });

        $this->assertSelectOnlyBudget($cold, 3, 'the common role path');
        $this->assertEquals(
            StatementMeasurement::none(),
            $warm,
            'Twelve further checks in the same evaluated second cost nothing.',
        );

        $this->forgetAcceptanceMemo();

        $roleless = $probe->measure(function (): void {
            $this->permissionResolver()->allows(
                $this->rolelessAcceptanceSubject(),
                'documents.approve',
            );
        });

        $this->assertSelectOnlyBudget($roleless, 2, 'a subject holding no role');
    }

    protected function assertSelectOnlyBudget(
        StatementMeasurement $measurement,
        int $expected,
        string $path,
    ): void {
        $this->assertSame(
            $expected,
            $measurement->total,
            sprintf('The cold budget for %s is %d statements.', $path, $expected),
        );
        $this->assertSame(
            $expected,
            $measurement->select,
            'Every resolver statement is a SELECT on a live engine too.',
        );
        $this->assertSame(0, $measurement->insert);
        $this->assertSame(0, $measurement->update);
        $this->assertSame(0, $measurement->delete);
        $this->assertSame(0, $measurement->schema);
        $this->assertSame(
            0,
            $measurement->other,
            'An unclassified statement means the driver issued something the '
                .'resolver did not ask for.',
        );
    }

    // ----------------------------------------------------------- Helpers

    protected function acceptanceSubject(): AcceptanceSubject
    {
        return $this->findAcceptanceSubject(
            ResolverAcceptanceFixture::SUBJECT_ID,
        );
    }

    protected function rolelessAcceptanceSubject(): AcceptanceSubject
    {
        return $this->findAcceptanceSubject(
            ResolverAcceptanceFixture::ROLELESS_SUBJECT_ID,
        );
    }

    protected function findAcceptanceSubject(int $id): AcceptanceSubject
    {
        if (! array_key_exists($id, $this->acceptanceSubjects)) {
            $subject = AcceptanceSubject::query()->find($id);

            $this->assertInstanceOf(
                AcceptanceSubject::class,
                $subject,
                'The acceptance subject must exist on the configured connection.',
            );

            $this->acceptanceSubjects[$id] = $subject;
        }

        return $this->acceptanceSubjects[$id];
    }

    protected function acceptanceProbe(): GateTrailStatementProbe
    {
        return new GateTrailStatementProbe(
            $this->app->make(DatabaseManager::class),
            $this->app->make(SchemaContext::class),
        );
    }

    /**
     * The fixture writes rows directly rather than through a manager, so no
     * `MutationCommitted` is dispatched and nothing flushes the memo on its
     * own. Each assertion group therefore clears it deliberately: a warm hit
     * from the previous group would make the next group's cold budget zero and
     * hide a real regression.
     */
    protected function forgetAcceptanceMemo(): void
    {
        $this->app->make(EffectiveAccessMemo::class)->clear();
    }

    abstract protected function permissionResolver(): PermissionResolver;

    abstract protected function roleResolver(): RoleResolver;
}

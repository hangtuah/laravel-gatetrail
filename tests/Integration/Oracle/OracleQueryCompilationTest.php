<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Integration\Oracle;

use DateTimeImmutable;
use HangTuah\GateTrail\Access\EffectiveAccessQueryBuilder;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Subjects\SubjectIdentity;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use PHPUnit\Framework\Attributes\DataProvider;
use Yajra\Oci8\Oci8Connection;
use Yajra\Oci8\Query\Grammars\OracleGrammar;

/**
 * Compiles the three snapshot queries under the **real** Oracle grammar.
 *
 * This is deliberately **compile-only**, and it is deliberately not a claim
 * about runtime. Compilation proves the emitted SQL is well-formed for the
 * grammar — that `EXISTS`, the correlated `whereColumn`, the bound `IN` list
 * and the schema-qualified names all survive Oracle's identifier wrapping and
 * its 30-character bound. It proves nothing about how the engine executes
 * them, and ADR 0012 section 12.1 keeps live Oracle resolver acceptance as a
 * separate, still-outstanding requirement.
 *
 * The grammar comes from `yajra/laravel-oci8` in the isolated runtime under
 * `tools/oracle-integration`, never from the root dependency graph, so the
 * package does not gain an Oracle dependency in order to test one. Nothing is
 * renamed or stubbed: this is the same grammar a real Oracle deployment uses.
 *
 * Unlike its sibling tests in this directory, it needs **no live endpoint**.
 * `Oci8Connection` is constructed with a PDO resolver that is never invoked,
 * because compiling a select never touches the connection.
 *
 * Run through the isolated runtime:
 *
 * ```text
 * ./vendor/bin/phpunit -c phpunit.oracle.xml \
 *     --filter OracleQueryCompilationTest
 * ```
 */
final class OracleQueryCompilationTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function queries(): array
    {
        return [
            'effective roles' => ['effectiveRolesQuery'],
            'direct permissions' => ['directPermissionsQuery'],
            'role-derived permissions' => ['roleDerivedPermissionsQuery'],
        ];
    }

    public function test_the_grammar_is_the_real_oracle_grammar(): void
    {
        $this->assertInstanceOf(OracleGrammar::class, $this->grammar());
        $this->assertSame(
            'Yajra\\Oci8\\Query\\Grammars\\OracleGrammar',
            $this->grammar()::class,
            'The evidence is worthless if another grammar is standing in.',
        );
    }

    #[DataProvider('queries')]
    public function test_each_snapshot_query_compiles(string $method): void
    {
        $sql = $this->compile($method);

        $this->assertStringStartsWith('select ', strtolower($sql));
        $this->assertStringContainsStringIgnoringCase('inner join', $sql);
        $this->assertStringContainsStringIgnoringCase('is null', $sql);
    }

    /**
     * Oracle wraps and upper-cases identifiers, so the assertions are
     * case-insensitive on purpose — the point is that the predicate survived,
     * not how the grammar spells it.
     */
    public function test_query_three_keeps_its_revalidation_layers(): void
    {
        $sql = $this->compile('roleDerivedPermissionsQuery');

        foreach ([
            'ACL_ROLE_PERMISSIONS',
            'ACL_ROLES',
            'ACL_PERMISSIONS',
            'ACL_ROLE_ASSIGNMENTS',
            'exists (',
            '"ACL_ROLES"."DELETED_AT" is null',
            '"ACL_PERMISSIONS"."DELETED_AT" is null',
            '"ACL_ROLE_PERMISSIONS"."ROLE_ID" in (',
            '"ACL_ROLE_ASSIGNMENTS"."ROLE_ID" = "ACL_ROLE_PERMISSIONS"."ROLE_ID"',
        ] as $expected) {
            $this->assertStringContainsStringIgnoringCase($expected, $sql);
        }

        // Both lifecycle scopes survive: the outer grant and the inner
        // assignment.
        $this->assertSame(
            2,
            substr_count(strtolower($sql), '"revoked_at" is null'),
        );
    }

    public function test_query_three_emits_no_unportable_construct(): void
    {
        $sql = $this->compile('roleDerivedPermissionsQuery');

        foreach ([
            'distinct', 'order by', 'union', 'over (', 'lateral',
            'with ', 'for update', 'rownum', 'fetch first',
        ] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $sql);
        }
    }

    /**
     * Oracle's identifier bound is what makes the alias lengths load-bearing
     * rather than cosmetic.
     */
    public function test_every_emitted_alias_fits_the_oracle_identifier_bound(): void
    {
        foreach ([
            EffectiveAccessQueryBuilder::ID_ALIAS,
            EffectiveAccessQueryBuilder::NAME_ALIAS,
            EffectiveAccessQueryBuilder::GUARD_ALIAS,
            EffectiveAccessQueryBuilder::ROLE_GUARD_ALIAS,
        ] as $alias) {
            $this->assertLessThanOrEqual(30, strlen($alias));
            $this->assertStringContainsStringIgnoringCase(
                $alias,
                $this->compile('roleDerivedPermissionsQuery'),
            );
        }
    }

    public function test_a_configured_prefix_and_schema_survive_compilation(): void
    {
        config()->set('gatetrail.database.prefix', 'x_');
        config()->set('gatetrail.database.schema', 'gt');

        foreach ([
            SchemaContext::class,
            EffectiveAccessQueryBuilder::class,
        ] as $abstract) {
            $this->app->forgetInstance($abstract);
        }

        $sql = $this->compile('roleDerivedPermissionsQuery');

        $this->assertStringContainsStringIgnoringCase(
            '"GT"."X_ACL_ROLE_PERMISSIONS"',
            $sql,
        );
        $this->assertStringContainsStringIgnoringCase(
            '"GT"."X_ACL_ROLE_ASSIGNMENTS"',
            $sql,
        );
    }

    /**
     * Compiling never opens a connection, which is what lets this test run
     * with no Oracle endpoint at all.
     */
    public function test_compilation_requires_no_live_connection(): void
    {
        $opened = false;

        $connection = new Oci8Connection(
            static function () use (&$opened): void {
                $opened = true;

                throw new \RuntimeException('A connection must never be opened.');
            },
            'db',
            '',
            [],
        );

        (new OracleGrammar($connection))->compileSelect(
            $this->build('roleDerivedPermissionsQuery')->toBase(),
        );

        $this->assertFalse($opened);
    }

    private function compile(string $method): string
    {
        return $this->grammar()->compileSelect($this->build($method)->toBase());
    }

    private function grammar(): OracleGrammar
    {
        return new OracleGrammar(
            new Oci8Connection(static fn () => null, 'db', '', []),
        );
    }

    private function build(string $method): Builder
    {
        $queries = $this->app->make(EffectiveAccessQueryBuilder::class);
        $subject = new SubjectIdentity('App\\Models\\User', 7);
        $evaluatedAt = new DateTimeImmutable('2026-08-17 12:00:00');

        if ($method === 'roleDerivedPermissionsQuery') {
            return $queries->roleDerivedPermissionsQuery(
                $subject,
                'web',
                $evaluatedAt,
                [3, 4],
            );
        }

        /** @var Builder $query */
        $query = $queries->{$method}($subject, 'web', $evaluatedAt);

        return $query;
    }
}

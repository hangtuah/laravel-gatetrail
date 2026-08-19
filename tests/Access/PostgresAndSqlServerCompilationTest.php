<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Access;

use DateTimeImmutable;
use HangTuah\GateTrail\Access\EffectiveAccessQueryBuilder;
use HangTuah\GateTrail\Access\EffectiveAccessResolver;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Subjects\SubjectIdentity;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Query\Grammars\SqlServerGrammar;
use Illuminate\Database\SqlServerConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

/**
 * The three snapshot shapes compiled under the **real** PostgreSQL and SQL
 * Server grammars that ship with `illuminate/database`.
 *
 * # This is compilation evidence, and only compilation evidence
 *
 * **No live PostgreSQL endpoint was executed. No live SQL Server endpoint was
 * executed.** Compiling proves the emitted SQL is well-formed for the grammar —
 * that the correlated `EXISTS`, the `whereColumn` correlation, the bound `IN`
 * list, the qualified predicates and the selected aliases all survive each
 * engine's identifier wrapping, and that no driver branch was needed to get
 * there. It proves nothing about how either engine plans, executes or
 * collates. ADR 0012 section 12.1 keeps both engines at
 * compilation-and-inspection level deliberately, and Increment 10 keeps live
 * MySQL and targeted live Oracle resolver acceptance outstanding.
 *
 * The behavioural evidence for these shapes remains SQLite, where the full
 * matrix runs against real rows.
 *
 * # Why compile rather than eyeball
 *
 * Section 6.4's portability rules are all invisible until something compiles
 * them: an unqualified `guard_name` is valid SQL that binds to the wrong table,
 * a `LIMIT` clause is silently rewritten to `TOP` or `FETCH`, and a raw
 * fragment written for one engine is a syntax error on another. Each rule is
 * therefore asserted against emitted text, per grammar, rather than trusted to
 * the builder's intent.
 *
 * @see docs/decisions/0012-effective-access-resolution.md sections 6.4 and 12.1
 */
final class PostgresAndSqlServerCompilationTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function grammars(): array
    {
        return [
            'postgresql' => ['pgsql'],
            'sqlserver' => ['sqlsrv'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function shapesPerGrammar(): array
    {
        $cases = [];

        foreach (array_keys(self::grammars()) as $engine) {
            foreach ([
                'effective roles' => 'effectiveRolesQuery',
                'direct permissions' => 'directPermissionsQuery',
                'role-derived permissions' => 'roleDerivedPermissionsQuery',
            ] as $label => $method) {
                $cases[$engine.' — '.$label] = [
                    self::grammars()[$engine][0],
                    $method,
                ];
            }
        }

        return $cases;
    }

    // -------------------------------------------------------- The grammars

    public function test_the_grammars_are_the_framework_grammars(): void
    {
        $this->assertInstanceOf(PostgresGrammar::class, $this->grammar('pgsql'));
        $this->assertInstanceOf(SqlServerGrammar::class, $this->grammar('sqlsrv'));

        $this->assertSame(
            'Illuminate\\Database\\Query\\Grammars\\PostgresGrammar',
            $this->grammar('pgsql')::class,
            'The evidence is worthless if a stand-in is compiling it.',
        );
        $this->assertSame(
            'Illuminate\\Database\\Query\\Grammars\\SqlServerGrammar',
            $this->grammar('sqlsrv')::class,
        );
    }

    /**
     * Compiling opens nothing, which is what makes this runnable with no
     * endpoint of either kind — and what makes the "no live database" claim
     * mechanical rather than a promise.
     */
    public function test_compilation_opens_no_connection(): void
    {
        $opened = false;

        $resolver = static function () use (&$opened): void {
            $opened = true;

            throw new \RuntimeException('A connection must never be opened.');
        };

        (new PostgresGrammar(new PostgresConnection($resolver, 'db', '', [])))
            ->compileSelect($this->build('roleDerivedPermissionsQuery')->toBase());
        (new SqlServerGrammar(new SqlServerConnection($resolver, 'db', '', [])))
            ->compileSelect($this->build('roleDerivedPermissionsQuery')->toBase());

        $this->assertFalse($opened);
    }

    // ------------------------------------------------- All three shapes

    #[DataProvider('shapesPerGrammar')]
    public function test_each_shape_compiles_to_a_joined_select(
        string $driver,
        string $method,
    ): void {
        $sql = $this->compile($driver, $method);

        $this->assertStringStartsWith('select ', strtolower($sql));
        $this->assertStringContainsString('inner join', strtolower($sql));
        $this->assertStringContainsString(' is null', strtolower($sql));
    }

    #[DataProvider('shapesPerGrammar')]
    public function test_each_shape_binds_its_instant_rather_than_formatting_it(
        string $driver,
        string $method,
    ): void {
        $sql = $this->compile($driver, $method);

        $this->assertStringNotContainsString(
            '2026-08-19',
            $sql,
            'A formatted literal would put date handling in the builder.',
        );
        $this->assertStringContainsString('?', $sql);
    }

    #[DataProvider('shapesPerGrammar')]
    public function test_each_shape_avoids_every_unportable_construct(
        string $driver,
        string $method,
    ): void {
        $sql = strtolower($this->compile($driver, $method));

        foreach ([
            // Section 6.4's explicit list.
            'distinct', 'union', ' over (', 'lateral', 'cross apply',
            'outer apply', 'order by', 'limit', 'offset', 'fetch next',
            'fetch first', 'top ', 'rownum',
            // Locking of any kind, per section 9.1.
            'for update', 'lock in share mode', 'updlock', 'holdlock',
            'rowlock', 'with (nolock)',
            // Engine-specific date and null handling.
            'extract(', 'date_add', 'dateadd', 'datediff', 'datepart',
            'nvl(', 'iif(', 'to_char(', 'getdate(', 'now(', 'sysdate',
            'convert(', 'cast(',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $sql, $forbidden);
        }

        $this->assertStringStartsNotWith(
            'with ',
            $sql,
            'No common table expression.',
        );
        $this->assertStringNotContainsString(' with ', $sql);
    }

    #[DataProvider('shapesPerGrammar')]
    public function test_no_shape_contains_a_raw_fragment(
        string $driver,
        string $method,
    ): void {
        // Driver-independent, but asserted per grammar so a raw fragment
        // introduced for one engine cannot hide behind the other.
        $this->assertSame(
            [],
            $this->rawFragmentsIn($this->build($method)->toBase()),
            'Every predicate must come from a Laravel builder method.',
        );
        $this->assertIsString($this->compile($driver, $method));
    }

    #[DataProvider('shapesPerGrammar')]
    public function test_no_shape_paginates_or_locks(
        string $driver,
        string $method,
    ): void {
        $base = $this->build($method)->toBase();

        $this->assertNull($base->limit);
        $this->assertNull($base->offset);
        $this->assertNull($base->lock);
        $this->assertFalse($base->distinct);
        $this->assertSame([], $base->orders ?? []);
        $this->assertSame([], $base->unions ?? []);
        $this->assertIsString($this->compile($driver, $method));
    }

    #[DataProvider('grammars')]
    public function test_the_selected_aliases_survive(string $driver): void
    {
        foreach ([
            'effectiveRolesQuery' => [
                EffectiveAccessQueryBuilder::ID_ALIAS,
                EffectiveAccessQueryBuilder::NAME_ALIAS,
                EffectiveAccessQueryBuilder::GUARD_ALIAS,
            ],
            'directPermissionsQuery' => [
                EffectiveAccessQueryBuilder::ID_ALIAS,
                EffectiveAccessQueryBuilder::NAME_ALIAS,
                EffectiveAccessQueryBuilder::GUARD_ALIAS,
            ],
            'roleDerivedPermissionsQuery' => [
                EffectiveAccessQueryBuilder::ID_ALIAS,
                EffectiveAccessQueryBuilder::NAME_ALIAS,
                EffectiveAccessQueryBuilder::GUARD_ALIAS,
                EffectiveAccessQueryBuilder::ROLE_GUARD_ALIAS,
            ],
        ] as $method => $aliases) {
            $sql = $this->compile($driver, $method);

            foreach ($aliases as $alias) {
                $this->assertStringContainsString(
                    ' as '.$this->wrap($driver, $alias),
                    $sql,
                    $alias.' must survive '.$driver.' wrapping.',
                );
            }
        }
    }

    // --------------------------------------- Queries 1 and 2: joined definitions

    #[DataProvider('grammars')]
    public function test_query_one_qualifies_its_join_and_its_definition_predicates(
        string $driver,
    ): void {
        $sql = $this->compile($driver, 'effectiveRolesQuery');

        $this->assertStringContainsString(
            'from '.$this->qualify($driver, 'acl_role_assignments'),
            $sql,
        );
        $this->assertStringContainsString(
            'inner join '.$this->qualify($driver, 'acl_roles').' on '
                .$this->qualify($driver, 'acl_roles', 'id').' = '
                .$this->qualify($driver, 'acl_role_assignments', 'role_id'),
            $sql,
            'The join uses the configured primary-key name.',
        );
        $this->assertStringContainsString(
            $this->qualify($driver, 'acl_roles', 'deleted_at').' is null',
            $sql,
            'Nothing on a joined table is inherited, so it is written.',
        );
        $this->assertStringContainsString(
            $this->qualify($driver, 'acl_roles', 'guard_name').' = ?',
            $sql,
        );
        $this->assertStringContainsString(
            $this->qualify($driver, 'acl_role_assignments', 'subject_type').' = ?',
            $sql,
        );
        $this->assertStringContainsString(
            $this->qualify($driver, 'acl_role_assignments', 'subject_id').' = ?',
            $sql,
        );
    }

    #[DataProvider('grammars')]
    public function test_query_two_qualifies_its_join_and_its_definition_predicates(
        string $driver,
    ): void {
        $sql = $this->compile($driver, 'directPermissionsQuery');

        $this->assertStringContainsString(
            'from '.$this->qualify($driver, 'acl_permission_assignments'),
            $sql,
        );
        $this->assertStringContainsString(
            'inner join '.$this->qualify($driver, 'acl_permissions').' on '
                .$this->qualify($driver, 'acl_permissions', 'id').' = '
                .$this->qualify($driver, 'acl_permission_assignments', 'permission_id'),
            $sql,
        );
        $this->assertStringContainsString(
            $this->qualify($driver, 'acl_permissions', 'deleted_at').' is null',
            $sql,
        );
        $this->assertStringContainsString(
            $this->qualify($driver, 'acl_permissions', 'guard_name').' = ?',
            $sql,
        );
    }

    /**
     * One lifecycle-bearing table in scope means the unqualified fragments
     * `scopeActiveAt()` emits can only bind to the assignment root.
     */
    #[DataProvider('shapesPerGrammar')]
    public function test_the_lifecycle_fragment_sits_on_the_assignment_root(
        string $driver,
        string $method,
    ): void {
        $sql = $this->compile($driver, $method);
        $expected = $method === 'roleDerivedPermissionsQuery' ? 2 : 1;

        $this->assertSame(
            $expected,
            substr_count($sql, $this->wrap($driver, 'revoked_at').' is null'),
        );
        $this->assertSame(
            $expected,
            substr_count($sql, $this->wrap($driver, 'assigned_at').' <= ?'),
        );
        $this->assertSame(
            $expected,
            substr_count($sql, $this->wrap($driver, 'expires_at').' is null'),
        );
    }

    // --------------------------------------------- Query 3: the revalidation

    #[DataProvider('grammars')]
    public function test_query_three_keeps_its_correlated_exists(string $driver): void
    {
        $sql = $this->compile($driver, 'roleDerivedPermissionsQuery');

        $this->assertStringContainsString(
            'exists (select * from '.$this->qualify($driver, 'acl_role_assignments'),
            $sql,
            'The subquery is a builder over the configured assignment model.',
        );
        $this->assertStringContainsString(
            $this->qualify($driver, 'acl_role_assignments', 'role_id').' = '
                .$this->qualify($driver, 'acl_role_permissions', 'role_id'),
            $sql,
            'Both sides of the correlation are qualified.',
        );
        $this->assertStringContainsString(
            $this->qualify($driver, 'acl_role_permissions', 'role_id').' in (?, ?)',
            $sql,
            'Candidates are bound parameters, never inlined identifiers.',
        );
    }

    /**
     * The outer scope must hold exactly one lifecycle-bearing table. Joining
     * `role_assignments` there would make every unqualified lifecycle column
     * ambiguous — and silently wrong on an engine that resolves rather than
     * errors.
     */
    #[DataProvider('grammars')]
    public function test_query_three_does_not_join_role_assignments_in_its_outer_query(
        string $driver,
    ): void {
        $sql = $this->compile($driver, 'roleDerivedPermissionsQuery');

        [$outer, $inner] = $this->splitAtExists($sql);

        $this->assertStringNotContainsString(
            'inner join '.$this->qualify($driver, 'acl_role_assignments'),
            $sql,
            'The assignment is revalidated by EXISTS, never joined.',
        );
        $this->assertStringNotContainsString(
            $this->qualify($driver, 'acl_role_assignments'),
            $outer,
            'The assignment table appears only inside the subquery.',
        );
        $this->assertStringContainsString(
            $this->qualify($driver, 'acl_role_assignments'),
            $inner,
        );

        $this->assertSame(
            1,
            substr_count($outer, $this->wrap($driver, 'revoked_at').' is null'),
            'One lifecycle predicate in the outer scope: the grant.',
        );
        $this->assertSame(
            1,
            substr_count($inner, $this->wrap($driver, 'revoked_at').' is null'),
            'One in the subquery: the assignment.',
        );
    }

    #[DataProvider('grammars')]
    public function test_query_three_revalidates_both_definitions(string $driver): void
    {
        $sql = $this->compile($driver, 'roleDerivedPermissionsQuery');

        foreach (['acl_roles', 'acl_permissions'] as $definition) {
            $this->assertStringContainsString(
                $this->qualify($driver, $definition, 'deleted_at').' is null',
                $sql,
            );
            $this->assertStringContainsString(
                $this->qualify($driver, $definition, 'guard_name').' = ?',
                $sql,
            );
        }

        $this->assertStringContainsString(
            $this->qualify($driver, 'acl_role_assignments', 'subject_type').' = ?',
            $sql,
        );
        $this->assertStringContainsString(
            $this->qualify($driver, 'acl_role_assignments', 'subject_id').' = ?',
            $sql,
        );
    }

    // -------------------------------------------- Configured names and prefixes

    #[DataProvider('grammars')]
    public function test_a_configured_prefix_and_schema_survive_compilation(
        string $driver,
    ): void {
        config()->set('gatetrail.database.prefix', 'x_');
        config()->set('gatetrail.database.schema', 'gt');

        foreach ([SchemaContext::class, EffectiveAccessQueryBuilder::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }

        $sql = $this->compile($driver, 'roleDerivedPermissionsQuery');

        foreach ([
            'x_acl_role_permissions',
            'x_acl_roles',
            'x_acl_permissions',
            'x_acl_role_assignments',
        ] as $table) {
            $this->assertStringContainsString(
                $this->qualify($driver, 'gt', $table),
                $sql,
                $table.' must keep its configured schema and prefix.',
            );
        }

        $this->assertStringContainsString(
            $this->qualify($driver, 'gt', 'x_acl_role_assignments', 'role_id').' = '
                .$this->qualify($driver, 'gt', 'x_acl_role_permissions', 'role_id'),
            $sql,
            'The correlation stays qualified through the schema prefix.',
        );
    }

    // ------------------------------------------------------ Chunking ownership

    /**
     * Chunking decides how many statements are issued, which is a resolution
     * concern. Keeping it out of the builder is what stops a per-engine chunk
     * size from ever being introduced here.
     */
    public function test_the_chunk_bound_belongs_to_the_resolver_not_the_builder(): void
    {
        $this->assertTrue(
            (new ReflectionClass(EffectiveAccessResolver::class))
                ->hasConstant('CANDIDATE_ROLE_CHUNK'),
        );
        $this->assertFalse(
            (new ReflectionClass(EffectiveAccessQueryBuilder::class))
                ->hasConstant('CANDIDATE_ROLE_CHUNK'),
        );
        $this->assertSame(
            900,
            (new ReflectionClass(EffectiveAccessResolver::class))
                ->getConstant('CANDIDATE_ROLE_CHUNK'),
        );
    }

    public function test_the_builder_carries_no_driver_branch(): void
    {
        // Comments are stripped first: the class docblock explains that it
        // uses no `DB::raw()` and names the engines it stays portable across,
        // and a prose mention of a thing is not a use of it.
        $code = $this->executableCodeOf(EffectiveAccessQueryBuilder::class);

        foreach ([
            'pgsql', 'sqlsrv', 'mysql', 'sqlite', 'oracle', 'oci8',
            'getDriverName', 'DB::raw', 'whereRaw', 'selectRaw', 'joinRaw',
            'havingRaw', 'orderByRaw',
        ] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase(
                $forbidden,
                $code,
                'One shape on every engine, with no branch and no raw SQL.',
            );
        }
    }

    /**
     * Same builder object, two grammars: the emitted text differs only in how
     * each engine wraps identifiers, which is the whole claim of "no driver
     * branching".
     */
    public function test_the_two_grammars_compile_one_identical_shape(): void
    {
        foreach ([
            'effectiveRolesQuery',
            'directPermissionsQuery',
            'roleDerivedPermissionsQuery',
        ] as $method) {
            $postgres = $this->compile('pgsql', $method);
            $sqlServer = $this->compile('sqlsrv', $method);

            $this->assertNotSame($postgres, $sqlServer, 'Wrapping differs.');
            $this->assertSame(
                str_replace(['[', ']'], '"', $sqlServer),
                $postgres,
                'Unwrapped, the two are the same statement.',
            );
        }
    }

    // ------------------------------------------------------------- Helpers

    private function compile(string $driver, string $method): string
    {
        return $this->grammar($driver)
            ->compileSelect($this->build($method)->toBase());
    }

    private function grammar(string $driver): Grammar
    {
        return $driver === 'pgsql'
            ? new PostgresGrammar(
                new PostgresConnection(static fn () => null, 'db', '', []),
            )
            : new SqlServerGrammar(
                new SqlServerConnection(static fn () => null, 'db', '', []),
            );
    }

    /**
     * @return EloquentBuilder<Model>
     */
    private function build(string $method): EloquentBuilder
    {
        $queries = $this->app->make(EffectiveAccessQueryBuilder::class);
        $subject = new SubjectIdentity('App\\Models\\User', 7);
        $evaluatedAt = new DateTimeImmutable('2026-08-19 09:30:00');

        if ($method === 'roleDerivedPermissionsQuery') {
            return $queries->roleDerivedPermissionsQuery(
                $subject,
                'web',
                $evaluatedAt,
                [3, 4],
            );
        }

        /** @var EloquentBuilder<Model> $query */
        $query = $queries->{$method}($subject, 'web', $evaluatedAt);

        return $query;
    }

    /**
     * A class's source with every comment and docblock removed, so a
     * prohibition can be asserted against what the file executes rather than
     * against what it says about itself.
     *
     * @param  class-string  $class
     */
    private function executableCodeOf(string $class): string
    {
        $path = (new ReflectionClass($class))->getFileName();

        $this->assertIsString($path);

        $code = '';

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
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

    private function wrap(string $driver, string $identifier): string
    {
        return $driver === 'pgsql'
            ? '"'.$identifier.'"'
            : '['.$identifier.']';
    }

    private function qualify(string $driver, string ...$parts): string
    {
        return implode(
            '.',
            array_map(fn (string $part): string => $this->wrap($driver, $part), $parts),
        );
    }

    /**
     * @return array{string, string}
     */
    private function splitAtExists(string $sql): array
    {
        $position = strpos($sql, 'exists (');

        $this->assertIsInt($position);

        return [substr($sql, 0, $position), substr($sql, $position)];
    }

    /**
     * Every raw fragment reachable from the query, including inside joins and
     * subqueries.
     *
     * @return list<string>
     */
    private function rawFragmentsIn(QueryBuilder $query): array
    {
        $found = [];

        if ($query->from instanceof Expression) {
            $found[] = 'from';
        }

        foreach ($query->columns ?? [] as $column) {
            if ($column instanceof Expression) {
                $found[] = 'column';
            }
        }

        foreach ($query->joins ?? [] as $join) {
            $found = [...$found, ...$this->rawFragmentsIn($join)];
        }

        foreach ($query->wheres as $where) {
            if (($where['type'] ?? null) === 'raw') {
                $found[] = 'where.raw';
            }

            if (($where['column'] ?? null) instanceof Expression) {
                $found[] = 'where.column';
            }

            if (($where['query'] ?? null) instanceof QueryBuilder) {
                $found = [...$found, ...$this->rawFragmentsIn($where['query'])];
            }
        }

        return $found;
    }
}

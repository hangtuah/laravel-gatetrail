<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Access;

use DateTimeImmutable;
use DateTimeInterface;
use HangTuah\GateTrail\Access\EffectiveAccessQueryBuilder;
use HangTuah\GateTrail\Contracts\PermissionResolver;
use HangTuah\GateTrail\Contracts\RoleResolver;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\PermissionAssignment;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\Models\RoleAssignment;
use HangTuah\GateTrail\Subjects\SubjectIdentity;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Database\SqlServerConnection;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pins the shape of the two joined-definition snapshot queries.
 *
 * The predicate that matters most here is the one that is *not* inherited.
 * `roles.deleted_at IS NULL` cannot come from a global scope, because the root
 * model of every snapshot query is an assignment model and none of those soft
 * delete; and it cannot come from a relation, because this package's
 * definition relations are deliberately `withTrashed()`. If it is not written
 * explicitly it does not exist, and a deactivated role keeps granting
 * permissions. That failure mode is silent, so it is asserted against compiled
 * SQL rather than inferred from row results.
 *
 * Everything is asserted by compiling the builder. No test here executes one.
 */
final class EffectiveAccessQueryBuilderTest extends TestCase
{
    private const GUARD = 'web';

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function queries(): array
    {
        return [
            'effective roles' => [
                'effectiveRolesQuery',
                'acl_role_assignments',
                'acl_roles',
                'role_id',
            ],
            'direct permissions' => [
                'directPermissionsQuery',
                'acl_permission_assignments',
                'acl_permissions',
                'permission_id',
            ],
        ];
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function grammars(): array
    {
        return [
            'sqlite' => [SQLiteConnection::class],
            'mysql' => [MySqlConnection::class],
            'postgres' => [PostgresConnection::class],
            'sqlserver' => [SqlServerConnection::class],
        ];
    }

    // -------------------------------------------------- 1. Builder-only behaviour

    #[DataProvider('queries')]
    public function test_it_returns_an_unexecuted_eloquent_builder(
        string $method,
    ): void {
        $query = $this->build($method);

        $this->assertInstanceOf(Builder::class, $query);
        $this->assertNotInstanceOf(Collection::class, $query);
        $this->assertNotInstanceOf(EloquentCollection::class, $query);
        $this->assertNotInstanceOf(Model::class, $query);

        $returnType = (new ReflectionMethod(
            EffectiveAccessQueryBuilder::class,
            $method,
        ))->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertSame(Builder::class, (string) $returnType);
    }

    /**
     * Building a query is not running one. A statement here would mean the
     * component had already decided when the snapshot is read, which is
     * Increment 5's decision to make.
     */
    public function test_building_both_queries_executes_no_statement(): void
    {
        $statements = 0;

        $this->app['db']->listen(function () use (&$statements): void {
            $statements++;
        });

        $this->build('effectiveRolesQuery');
        $this->build('directPermissionsQuery');

        $this->assertSame(
            0,
            $statements,
            'Constructing a builder must not reach the database.',
        );
        $this->assertSame(0, $this->app['db']->transactionLevel());
    }

    #[DataProvider('queries')]
    public function test_it_requests_no_lock(string $method): void
    {
        $query = $this->build($method);

        $this->assertNull($query->toBase()->lock);
        $this->assertStringNotContainsStringIgnoringCase(
            'for update',
            $query->toSql(),
        );
        $this->assertStringNotContainsStringIgnoringCase(
            'lock in share mode',
            $query->toSql(),
        );
    }

    /**
     * Asserted against the source with comments stripped, so the prose in the
     * class docblock — which names these methods precisely because they are
     * forbidden — cannot satisfy or break the check.
     */
    public function test_the_component_calls_no_terminal_or_raw_operation(): void
    {
        $source = $this->executableSource();

        foreach ([
            '->get(', '->first(', '->firstOrFail(', '->exists(', '->count(',
            '->cursor(', '->lazy(', '->paginate(', '->simplePaginate(',
            '->find(', '->pluck(', '->value(', '->chunk(', '->each(',
            '->lockForUpdate(', '->sharedLock(',
            '->orderBy(', '->distinct(', '->limit(', '->offset(',
            'selectRaw', 'whereRaw', 'joinRaw', 'havingRaw', 'orderByRaw',
            'DB::raw', 'Expression', '::raw(',
        ] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                sprintf('The query builder must not call [%s].', $forbidden),
            );
        }
    }

    // ----------------------------------------------- 2. activeAt() lifecycle reuse

    /**
     * The complete half-open shape, asserted as one contiguous fragment rather
     * than as three independent substrings, so a partially applied scope
     * cannot pass.
     */
    #[DataProvider('queries')]
    public function test_it_carries_the_whole_half_open_lifecycle_predicate(
        string $method,
    ): void {
        $sql = $this->build($method)->toSql();

        $this->assertStringContainsString(
            'where "revoked_at" is null '
            .'and ("starts_at" <= ? or ("starts_at" is null and "assigned_at" <= ?)) '
            .'and ("expires_at" is null or "expires_at" > ?)',
            $sql,
        );
    }

    #[DataProvider('queries')]
    public function test_the_evaluation_instant_is_bound_through_the_grammar(
        string $method,
    ): void {
        $evaluatedAt = new DateTimeImmutable('2026-08-17 09:41:07');
        $query = $this->build($method, evaluatedAt: $evaluatedAt);

        $bindings = $query->getBindings();

        // Bound as the object. Formatting belongs to the connection's grammar,
        // which is what keeps the shape portable across engines.
        $this->assertSame($evaluatedAt, $bindings[0]);
        $this->assertSame($evaluatedAt, $bindings[1]);
        $this->assertSame($evaluatedAt, $bindings[2]);

        $prepared = $query->getConnection()->prepareBindings($bindings);

        $this->assertSame('2026-08-17 09:41:07', $prepared[0]);
        $this->assertSame('2026-08-17 09:41:07', $prepared[1]);
        $this->assertSame('2026-08-17 09:41:07', $prepared[2]);
    }

    /**
     * Behavioural proof that the trait scope is *called*, not re-implemented.
     *
     * The configured assignment models override `scopeActiveAt()` to delegate
     * to the parent and add one harmless sentinel predicate. The sentinel can
     * only reach the compiled SQL if the component invoked the model's scope,
     * and the half-open fragment can only survive alongside it if the parent
     * scope really produced it.
     */
    public function test_it_calls_the_models_own_active_at_scope(): void
    {
        config()->set(
            'gatetrail.models.role_assignment',
            SentinelRoleAssignment::class,
        );
        config()->set(
            'gatetrail.models.permission_assignment',
            SentinelPermissionAssignment::class,
        );

        foreach (['effectiveRolesQuery', 'directPermissionsQuery'] as $method) {
            $sql = $this->build($method)->toSql();

            $this->assertStringContainsString(
                '"gatetrail_active_at_sentinel" is null',
                $sql,
                'The component must call the configured model scope.',
            );
            $this->assertStringContainsString(
                'where "revoked_at" is null '
                .'and ("starts_at" <= ? or ("starts_at" is null and "assigned_at" <= ?)) '
                .'and ("expires_at" is null or "expires_at" > ?)',
                $sql,
                'The inherited half-open shape must survive unchanged.',
            );
        }
    }

    /**
     * The half-open rule exists in exactly one place. A copy here would be a
     * second lifecycle algorithm, which is the drift ADR 0012 was written to
     * prevent.
     *
     * This doubles as the guard on `executableSource()`: the class docblock
     * names all four lifecycle columns, so the assertions below can only pass
     * if comment stripping actually worked, and the `activeAt(` assertion can
     * only pass if it left the code intact.
     */
    public function test_the_component_does_not_reimplement_the_lifecycle(): void
    {
        $source = $this->executableSource();

        foreach ([
            'revoked_at',
            'starts_at',
            'assigned_at',
            'expires_at',
        ] as $lifecycleColumn) {
            $this->assertStringNotContainsString(
                $lifecycleColumn,
                $source,
                'The lifecycle columns belong to HasAssignmentPeriodScopes.',
            );
        }

        $this->assertStringContainsString('activeAt(', $source);
    }

    // ----------------------------------------- 3. Explicit definition lifecycle

    #[DataProvider('queries')]
    public function test_it_writes_a_qualified_definition_deleted_at_predicate(
        string $method,
        string $rootTable,
        string $definitionTable,
    ): void {
        $this->assertStringContainsString(
            sprintf('"%s"."deleted_at" is null', $definitionTable),
            $this->build($method)->toSql(),
        );
    }

    /**
     * The control case. The identical join built without this component
     * carries **no** `deleted_at` predicate at all, which is what proves the
     * predicate above is written rather than inherited from a global scope.
     */
    #[DataProvider('queries')]
    public function test_the_same_join_without_the_component_has_no_lifecycle_filter(
        string $method,
        string $rootTable,
        string $definitionTable,
        string $foreignKey,
    ): void {
        $naive = $this->rootModel($method)
            ->newQuery()
            ->join(
                $definitionTable,
                $definitionTable.'.id',
                '=',
                $rootTable.'.'.$foreignKey,
            )
            ->toSql();

        $this->assertStringNotContainsString('deleted_at', $naive);
        $this->assertStringNotContainsString('guard_name', $naive);
    }

    /**
     * The other half of the same proof: the root models do not soft delete, so
     * there is no global scope on the query at all.
     */
    #[DataProvider('queries')]
    public function test_the_root_assignment_model_has_no_soft_delete_scope(
        string $method,
    ): void {
        $model = $this->rootModel($method);

        $this->assertNotContains(
            SoftDeletes::class,
            class_uses_recursive($model::class),
        );
        $this->assertSame(
            [],
            $model->newQuery()->getQuery()->wheres,
            'An unfiltered root query must carry no inherited predicate.',
        );
        $this->assertArrayNotHasKey(
            SoftDeletingScope::class,
            $model->getGlobalScopes(),
        );
    }

    /**
     * The historical relations must keep including deactivated definitions, so
     * an audit snapshot can still be rebuilt. This test exists to fail if a
     * future change "fixes" them into the resolver's filter.
     */
    public function test_the_historical_definition_relations_remain_with_trashed(): void
    {
        foreach ([
            [new RoleAssignment, 'role'],
            [new PermissionAssignment, 'permission'],
        ] as [$assignment, $relation]) {
            $sql = $assignment->{$relation}()->getQuery()->toSql();

            $this->assertStringNotContainsString(
                'deleted_at',
                $sql,
                sprintf(
                    'The [%s] relation must keep withTrashed() for audit rebuilds.',
                    $relation,
                ),
            );
        }
    }

    /**
     * A relation-based implementation would compile to a correlated subquery.
     * A flat join proves none was used.
     */
    #[DataProvider('queries')]
    public function test_it_uses_a_flat_join_rather_than_a_relation(
        string $method,
        string $rootTable,
        string $definitionTable,
        string $foreignKey,
    ): void {
        $sql = $this->build($method)->toSql();

        $this->assertStringContainsString(
            sprintf(
                'inner join "%s" on "%s"."id" = "%s"."%s"',
                $definitionTable,
                $definitionTable,
                $rootTable,
                $foreignKey,
            ),
            $sql,
        );
        $this->assertStringNotContainsString('exists (', $sql);
        $this->assertStringNotContainsString('in (select', $sql);
    }

    // -------------------------------------------------- 4. Explicit guard filter

    #[DataProvider('queries')]
    public function test_it_writes_a_qualified_definition_guard_predicate(
        string $method,
        string $rootTable,
        string $definitionTable,
    ): void {
        $query = $this->build($method, guardName: 'api');

        $this->assertStringContainsString(
            sprintf('"%s"."guard_name" = ?', $definitionTable),
            $query->toSql(),
        );
        $this->assertContains('api', $query->getBindings());
    }

    #[DataProvider('queries')]
    public function test_the_subject_identity_is_bound_qualified(
        string $method,
        string $rootTable,
    ): void {
        $query = $this->build($method);

        $this->assertStringContainsString(
            sprintf(
                '"%s"."subject_type" = ? and "%s"."subject_id" = ?',
                $rootTable,
                $rootTable,
            ),
            $query->toSql(),
        );

        $bindings = $query->getBindings();

        $this->assertContains('App\\Models\\User', $bindings);
        $this->assertContains(7, $bindings);
    }

    // ------------------------------------------------------- 5. Full qualification

    /**
     * The complete statement of the qualification rule: every identifier that
     * appears bare in the compiled SQL is listed here, and nothing else may.
     *
     * The four lifecycle columns are the documented exception — they are what
     * `scopeActiveAt()` emits, and they are unambiguous because the assignment
     * root is the only lifecycle-bearing table in the scope. The two table
     * names are bare in `from` and `join` because that is where a table name
     * belongs, and the three aliases are new names, not references.
     */
    #[DataProvider('queries')]
    public function test_nothing_but_the_lifecycle_scope_is_left_unqualified(
        string $method,
        string $rootTable,
        string $definitionTable,
    ): void {
        $this->assertSame(
            [
                $rootTable,
                $definitionTable,
                'assigned_at',
                'expires_at',
                'gatetrail_guard',
                'gatetrail_id',
                'gatetrail_name',
                'revoked_at',
                'starts_at',
            ],
            $this->bareIdentifiers($this->build($method)->toSql()),
        );
    }

    /**
     * Stated positively as well, because "no bare `id`" is the assertion that
     * actually protects the join from becoming ambiguous once a third table
     * enters the scope in Increment 6.
     */
    #[DataProvider('queries')]
    public function test_no_ambiguous_bare_column_survives(string $method): void
    {
        $bare = $this->bareIdentifiers($this->build($method)->toSql());

        foreach ([
            'id', 'name', 'guard_name', 'deleted_at',
            'role_id', 'permission_id', 'subject_type', 'subject_id',
        ] as $column) {
            $this->assertNotContains($column, $bare);
        }
    }

    // ---------------------------------------------------- 7. Replacement DELETED_AT

    public function test_it_honours_a_replacement_models_deleted_at_column(): void
    {
        config()->set('gatetrail.models.role', DeactivatedAtRole::class);
        config()->set(
            'gatetrail.models.permission',
            DeactivatedAtPermission::class,
        );

        foreach ([
            'effectiveRolesQuery' => 'acl_roles',
            'directPermissionsQuery' => 'acl_permissions',
        ] as $method => $table) {
            $sql = $this->build($method)->toSql();

            $this->assertStringContainsString(
                sprintf('"%s"."deactivated_at" is null', $table),
                $sql,
            );
            $this->assertStringNotContainsString(
                sprintf('"%s"."deleted_at"', $table),
                $sql,
                'The literal default column must not be assumed.',
            );
        }
    }

    // -------------------------------------------------- 8. Replacement primary key

    public function test_it_honours_a_replacement_models_primary_key(): void
    {
        config()->set('gatetrail.models.role', ReKeyedRole::class);
        config()->set('gatetrail.models.permission', ReKeyedPermission::class);

        foreach ([
            'effectiveRolesQuery' => ['acl_roles', 'role_uuid', 'role_id'],
            'directPermissionsQuery' => [
                'acl_permissions',
                'permission_uuid',
                'permission_id',
            ],
        ] as $method => [$table, $key, $foreignKey]) {
            $sql = $this->build($method)->toSql();

            $this->assertStringContainsString(
                sprintf('on "%s"."%s" = ', $table, $key),
                $sql,
                'The join must use getQualifiedKeyName().',
            );
            $this->assertStringContainsString(
                sprintf('"%s"."%s" as "gatetrail_id"', $table, $key),
                $sql,
                'The selected identifier must use the configured key.',
            );
            $this->assertStringNotContainsString(
                sprintf('"%s"."id"', $table),
                $sql,
            );
        }
    }

    // ------------------------------------------------------- Selected columns

    /**
     * The aliases are what lets Increment 5 map a row without asking the root
     * assignment model about attributes it does not own, and without knowing
     * how the definition model names its key.
     */
    #[DataProvider('queries')]
    public function test_it_selects_only_the_three_aliased_definition_columns(
        string $method,
        string $rootTable,
        string $definitionTable,
    ): void {
        $sql = $this->build($method)->toSql();

        $this->assertStringStartsWith(
            sprintf(
                'select "%s"."id" as "gatetrail_id", '
                .'"%s"."name" as "gatetrail_name", '
                .'"%s"."guard_name" as "gatetrail_guard" from ',
                $definitionTable,
                $definitionTable,
                $definitionTable,
            ),
            $sql,
        );
        $this->assertStringNotContainsString('select *', $sql);
        $this->assertStringNotContainsString(
            sprintf('"%s".*', $rootTable),
            $sql,
        );
    }

    public function test_the_aliases_are_fixed_and_portable(): void
    {
        $this->assertSame('gatetrail_id', EffectiveAccessQueryBuilder::ID_ALIAS);
        $this->assertSame('gatetrail_name', EffectiveAccessQueryBuilder::NAME_ALIAS);
        $this->assertSame('gatetrail_guard', EffectiveAccessQueryBuilder::GUARD_ALIAS);

        foreach ([
            EffectiveAccessQueryBuilder::ID_ALIAS,
            EffectiveAccessQueryBuilder::NAME_ALIAS,
            EffectiveAccessQueryBuilder::GUARD_ALIAS,
        ] as $alias) {
            // The bound the package already enforces for every identifier it
            // emits, so an alias cannot be the thing that breaks Oracle.
            $this->assertLessThanOrEqual(30, strlen($alias));
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $alias);
        }
    }

    // ---------------------------------------------------------- Portability

    /**
     * Compiled under every query grammar `illuminate/database` ships. Oracle is
     * **not** compiled here: no Oracle query grammar is bundled, and this
     * increment makes no live-engine claim.
     */
    #[DataProvider('grammars')]
    public function test_both_queries_compile_under_each_bundled_grammar(
        string $connectionClass,
    ): void {
        $connection = new $connectionClass(static fn () => null, 'db', '', []);
        $grammar = $connection->getQueryGrammar();

        foreach ([
            'effectiveRolesQuery' => 'acl_roles',
            'directPermissionsQuery' => 'acl_permissions',
        ] as $method => $table) {
            $sql = $grammar->compileSelect($this->build($method)->toBase());

            $this->assertStringContainsString('inner join', $sql);
            $this->assertStringContainsString('is null', $sql);
            $this->assertStringContainsString('gatetrail_id', $sql);
            $this->assertStringContainsString($table, $sql);
            $this->assertStringNotContainsStringIgnoringCase('order by', $sql);
            $this->assertStringNotContainsStringIgnoringCase('distinct', $sql);
            $this->assertStringNotContainsStringIgnoringCase('with ', $sql);
            $this->assertStringNotContainsStringIgnoringCase('over (', $sql);
            $this->assertStringNotContainsStringIgnoringCase('lateral', $sql);
        }
    }

    // ------------------------------------------------------ Container wiring

    public function test_the_component_is_registered_as_a_concrete_singleton(): void
    {
        $this->assertTrue($this->app->bound(EffectiveAccessQueryBuilder::class));
        $this->assertSame(
            $this->app->make(EffectiveAccessQueryBuilder::class),
            $this->app->make(EffectiveAccessQueryBuilder::class),
        );
    }

    // --------------------------------------------- 10. No later-increment behaviour

    /**
     * The public contracts are bound as of Increment 7, so "unbound" is no
     * longer the constraint. What survives is that the query builder is not
     * itself a resolver: it describes statements and answers no authorization
     * question.
     */
    public function test_the_query_builder_is_not_a_resolver(): void
    {
        $this->assertNotInstanceOf(
            PermissionResolver::class,
            $this->app->make(EffectiveAccessQueryBuilder::class),
        );
        $this->assertNotInstanceOf(
            RoleResolver::class,
            $this->app->make(EffectiveAccessQueryBuilder::class),
        );
    }

    /**
     * Query 3 arrived in Increment 6, so its shape is no longer forbidden
     * here — `RoleDerivedPermissionQueryTest` owns that assertion now. What
     * this class must still never grow is a **chunk bound**: how many
     * statements exist is the resolver's decision, and a bound here would
     * split that ownership in two.
     */
    public function test_it_does_not_own_the_chunk_bound(): void
    {
        $source = $this->executableSource();

        $this->assertStringNotContainsString(
            '900',
            $source,
            'The candidate chunk bound belongs to EffectiveAccessResolver.',
        );
        $this->assertStringNotContainsString('array_chunk', $source);
    }

    /**
     * The component builds queries. It does not execute them, map rows, or
     * construct the value objects — that division is what keeps Increment 5
     * able to own execution and the query budget.
     */
    public function test_it_neither_executes_nor_maps_results(): void
    {
        $source = $this->executableSource();

        $this->assertStringNotContainsString('EffectiveRole', $source);
        $this->assertStringNotContainsString('EffectivePermission', $source);
        $this->assertStringNotContainsString('EffectiveAccessSnapshot', $source);

        $public = [];

        foreach (
            (new ReflectionClass(EffectiveAccessQueryBuilder::class))
                ->getMethods(ReflectionMethod::IS_PUBLIC) as $method
        ) {
            $public[] = $method->getName();
        }

        sort($public);

        $this->assertSame(
            [
                '__construct',
                'directPermissionsQuery',
                'effectiveRolesQuery',
                'roleDerivedPermissionsQuery',
            ],
            $public,
        );
    }

    public function test_its_only_constants_are_the_column_aliases(): void
    {
        $this->assertSame(
            ['ID_ALIAS', 'NAME_ALIAS', 'GUARD_ALIAS', 'ROLE_GUARD_ALIAS'],
            array_keys(
                (new ReflectionClass(EffectiveAccessQueryBuilder::class))
                    ->getConstants(),
            ),
        );
    }

    // ------------------------------------------------------------------ Helpers

    private function build(
        string $method,
        string $guardName = self::GUARD,
        ?DateTimeImmutable $evaluatedAt = null,
    ): Builder {
        /** @var Builder $query */
        $query = $this->app->make(EffectiveAccessQueryBuilder::class)->{$method}(
            new SubjectIdentity('App\\Models\\User', 7),
        $guardName,
        $evaluatedAt ?? new DateTimeImmutable('2026-08-17 09:41:07'),
        );

        return $query;
    }

    private function rootModel(string $method): Model
    {
        return $method === 'effectiveRolesQuery'
            ? new RoleAssignment
            : new PermissionAssignment;
    }

    /**
     * Every quoted identifier that stands alone — neither preceded nor
     * followed by a dot — sorted and deduplicated.
     *
     * @return list<string>
     */
    private function bareIdentifiers(string $sql): array
    {
        preg_match_all('/(?<!\.)"([a-z0-9_]+)"(?!\.)/', $sql, $matches);

        $identifiers = array_values(array_unique($matches[1]));
        sort($identifiers);

        return $identifiers;
    }

    /**
     * The component's source with every comment removed, so an assertion about
     * what the code calls is never satisfied by what the docblock describes.
     */
    private function executableSource(): string
    {
        $file = (new ReflectionClass(EffectiveAccessQueryBuilder::class))
            ->getFileName();

        $this->assertIsString($file);

        $contents = file_get_contents($file);

        $this->assertIsString($contents);

        $code = '';

        foreach (token_get_all($contents) as $token) {
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

/**
 * Adds one harmless predicate on top of the inherited half-open rule, so the
 * compiled SQL can prove the component called this scope rather than writing
 * its own copy.
 */
final class SentinelRoleAssignment extends RoleAssignment
{
    public function scopeActiveAt(Builder $query, DateTimeInterface $time): Builder
    {
        return parent::scopeActiveAt($query, $time)
            ->whereNull('gatetrail_active_at_sentinel');
    }
}

final class SentinelPermissionAssignment extends PermissionAssignment
{
    public function scopeActiveAt(Builder $query, DateTimeInterface $time): Builder
    {
        return parent::scopeActiveAt($query, $time)
            ->whereNull('gatetrail_active_at_sentinel');
    }
}

final class DeactivatedAtRole extends Role
{
    public const DELETED_AT = 'deactivated_at';
}

final class DeactivatedAtPermission extends Permission
{
    public const DELETED_AT = 'deactivated_at';
}

final class ReKeyedRole extends Role
{
    public $incrementing = false;

    protected $primaryKey = 'role_uuid';

    protected $keyType = 'string';
}

final class ReKeyedPermission extends Permission
{
    public $incrementing = false;

    protected $primaryKey = 'permission_uuid';

    protected $keyType = 'string';
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Access;

use DateTimeImmutable;
use HangTuah\GateTrail\Access\EffectiveAccessQueryBuilder;
use HangTuah\GateTrail\Subjects\SubjectIdentity;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Compiles both snapshot queries against a configured prefix and schema.
 *
 * `SchemaContext` is resolved once and captures configuration at construction,
 * so the prefix and schema are set in `defineEnvironment()` rather than inside
 * a test — the same convention `ConfiguredTableNamesTest` already uses.
 *
 * Compilation is the right evidence here. A schema-qualified name is a
 * deployment concern of the target engine, and creating a live schema-scoped
 * SQLite table to observe it would test the fixture rather than the query.
 */
final class EffectiveAccessQuerySchemaTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function queries(): array
    {
        return [
            'effective roles' => [
                'effectiveRolesQuery',
                'gt"."x_acl_role_assignments',
                'gt"."x_acl_roles',
                'role_id',
            ],
            'direct permissions' => [
                'directPermissionsQuery',
                'gt"."x_acl_permission_assignments',
                'gt"."x_acl_permissions',
                'permission_id',
            ],
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('gatetrail.database.prefix', 'x_');
        $app['config']->set('gatetrail.database.schema', 'gt');
    }

    #[DataProvider('queries')]
    public function test_the_root_table_carries_the_prefix_and_schema(
        string $method,
        string $rootTable,
    ): void {
        $this->assertStringContainsString(
            sprintf('from "%s"', $rootTable),
            $this->build($method)->toSql(),
        );
    }

    #[DataProvider('queries')]
    public function test_the_joined_definition_carries_the_prefix_and_schema(
        string $method,
        string $rootTable,
        string $definitionTable,
        string $foreignKey,
    ): void {
        $this->assertStringContainsString(
            sprintf(
                'inner join "%s" on "%s"."id" = "%s"."%s"',
                $definitionTable,
                $definitionTable,
                $rootTable,
                $foreignKey,
            ),
            $this->build($method)->toSql(),
        );
    }

    /**
     * The two written predicates are the ones a literal table name would break
     * first, so both are asserted against the fully resolved name.
     */
    #[DataProvider('queries')]
    public function test_the_written_predicates_qualify_against_the_resolved_table(
        string $method,
        string $rootTable,
        string $definitionTable,
    ): void {
        $sql = $this->build($method)->toSql();

        $this->assertStringContainsString(
            sprintf('"%s"."deleted_at" is null', $definitionTable),
            $sql,
        );
        $this->assertStringContainsString(
            sprintf('"%s"."guard_name" = ?', $definitionTable),
            $sql,
        );
        $this->assertStringContainsString(
            sprintf('"%s"."subject_type" = ?', $rootTable),
            $sql,
        );
        $this->assertStringContainsString(
            sprintf('"%s"."subject_id" = ?', $rootTable),
            $sql,
        );
    }

    #[DataProvider('queries')]
    public function test_the_selected_columns_qualify_against_the_resolved_table(
        string $method,
        string $rootTable,
        string $definitionTable,
    ): void {
        $this->assertStringStartsWith(
            sprintf(
                'select "%s"."id" as "gatetrail_id", '
                .'"%s"."name" as "gatetrail_name", '
                .'"%s"."guard_name" as "gatetrail_guard" from ',
                $definitionTable,
                $definitionTable,
                $definitionTable,
            ),
            $this->build($method)->toSql(),
        );
    }

    /**
     * The unprefixed names must be gone entirely, not merely accompanied by
     * the prefixed ones.
     */
    #[DataProvider('queries')]
    public function test_no_unprefixed_table_name_survives(string $method): void
    {
        $sql = $this->build($method)->toSql();

        foreach ([
            '"acl_roles"',
            '"acl_permissions"',
            '"acl_role_assignments"',
            '"acl_permission_assignments"',
        ] as $unprefixed) {
            $this->assertStringNotContainsString($unprefixed, $sql);
        }
    }

    private function build(string $method): Builder
    {
        /** @var Builder $query */
        $query = $this->app->make(EffectiveAccessQueryBuilder::class)->{$method}(
            new SubjectIdentity('App\\Models\\User', 7),
        'web',
        new DateTimeImmutable('2026-08-17 09:41:07'),
        );

        return $query;
    }
}

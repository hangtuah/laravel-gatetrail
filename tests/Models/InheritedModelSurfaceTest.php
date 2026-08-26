<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Models;

use DateTimeInterface;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Models\AccessAudit;
use HangTuah\GateTrail\Models\Concerns\HasAssignmentPeriodScopes;
use HangTuah\GateTrail\Models\GateTrailModel;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\PermissionAssignment;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\Models\RoleAssignment;
use HangTuah\GateTrail\Models\RolePermission;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

final class InheritedModelSurfaceTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<GateTrailModel>, string}>
     */
    public static function supportedModels(): iterable
    {
        yield 'role' => [Role::class, 'acl_roles'];
        yield 'permission' => [Permission::class, 'acl_permissions'];
        yield 'role assignment' => [RoleAssignment::class, 'acl_role_assignments'];
        yield 'permission assignment' => [
            PermissionAssignment::class,
            'acl_permission_assignments',
        ];
        yield 'role permission' => [RolePermission::class, 'acl_role_permissions'];
        yield 'access audit' => [AccessAudit::class, 'acl_access_audits'];
    }

    /**
     * @param  class-string<GateTrailModel>  $model
     */
    #[DataProvider('supportedModels')]
    public function test_supported_models_inherit_connection_and_table_resolution(
        string $model,
        string $table,
    ): void {
        config()->set('gatetrail.database.connection', 'testing');

        $instance = new $model;

        $this->assertSame('testing', $instance->getConnectionName());
        $this->assertSame($table, $instance->getTable());
    }

    public function test_gate_trail_model_pins_the_seven_valid_table_keys(): void
    {
        $keys = [
            'roles',
            'permissions',
            'role_assignments',
            'permission_assignments',
            'role_permissions',
            'assignment_locks',
            'access_audits',
        ];
        $documentation = (string) (new ReflectionClass(GateTrailModel::class))
            ->getDocComment();
        $schema = app(SchemaContext::class);

        foreach ($keys as $key) {
            $this->assertStringContainsString($key, $documentation);
            $this->assertSame(
                (string) config('gatetrail.tables.'.$key),
                $schema->table($key),
            );
        }

        $this->assertStringContainsString('@internal', $documentation);
    }

    public function test_inherited_base_method_signatures_are_stable(): void
    {
        $connection = new ReflectionMethod(
            GateTrailModel::class,
            'getConnectionName',
        );
        $table = new ReflectionMethod(GateTrailModel::class, 'getTable');
        $key = new ReflectionMethod(GateTrailModel::class, 'tableKey');

        $this->assertTrue($connection->isPublic());
        $this->assertSame('string', $this->namedType($connection)->getName());
        $this->assertTrue($this->namedType($connection)->allowsNull());
        $this->assertTrue($table->isPublic());
        $this->assertSame('string', $this->namedType($table)->getName());
        $this->assertTrue($key->isProtected());
        $this->assertTrue($key->isAbstract());
        $this->assertSame('string', $this->namedType($key)->getName());
    }

    public function test_all_four_assignment_period_scopes_are_stable(): void
    {
        $expected = [
            'scopeActiveAt' => ['query', 'time'],
            'scopeScheduledAt' => ['query', 'time'],
            'scopeExpiredAt' => ['query', 'time'],
            'scopeRevoked' => ['query'],
        ];
        $trait = new ReflectionClass(HasAssignmentPeriodScopes::class);

        foreach ($expected as $name => $parameters) {
            $method = $trait->getMethod($name);

            $this->assertTrue($method->isPublic());
            $this->assertSame(
                $parameters,
                array_map(
                    static fn (ReflectionParameter $parameter): string => $parameter
                        ->getName(),
                    $method->getParameters(),
                ),
            );
            $this->assertSame(
                Builder::class,
                $this->namedType($method)->getName(),
            );
        }

        foreach (
            ['scopeActiveAt', 'scopeScheduledAt', 'scopeExpiredAt'] as $name
        ) {
            $time = $trait->getMethod($name)->getParameters()[1]->getType();

            $this->assertInstanceOf(ReflectionNamedType::class, $time);
            $this->assertSame(DateTimeInterface::class, $time->getName());
        }

        $documentation = (string) $trait->getDocComment();
        $this->assertStringContainsString(
            'All four scopes are Tier 2',
            $documentation,
        );
        $this->assertStringContainsString(
            'depends on activeAt()',
            $documentation,
        );
    }

    public function test_every_assignment_model_inherits_the_scope_trait(): void
    {
        foreach (
            [
                RoleAssignment::class,
                PermissionAssignment::class,
                RolePermission::class,
            ] as $model
        ) {
            $this->assertContains(
                HasAssignmentPeriodScopes::class,
                class_uses_recursive($model),
            );
        }
    }

    private function namedType(ReflectionMethod $method): ReflectionNamedType
    {
        $type = $method->getReturnType();

        $this->assertInstanceOf(ReflectionNamedType::class, $type);

        return $type;
    }
}

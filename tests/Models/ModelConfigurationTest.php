<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Models;

use HangTuah\GateTrail\Contracts\PermissionAssignmentContract;
use HangTuah\GateTrail\Contracts\RoleAssignmentContract;
use HangTuah\GateTrail\Contracts\RoleContract;
use HangTuah\GateTrail\Contracts\RolePermissionContract;
use HangTuah\GateTrail\Exceptions\InvalidModelConfiguration;
use HangTuah\GateTrail\Models\AccessAudit;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\PermissionAssignment;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\Models\RoleAssignment;
use HangTuah\GateTrail\Models\RolePermission;
use HangTuah\GateTrail\Support\ModelRegistry;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

final class ModelConfigurationTest extends TestCase
{
    public function test_default_models_use_configured_connection_and_table_context(): void
    {
        config()->set('gatetrail.database.connection', 'testing');
        config()->set('gatetrail.database.prefix', 'tenant_');

        $role = new Role;

        $this->assertSame('testing', $role->getConnectionName());
        $this->assertSame('tenant_acl_roles', $role->getTable());
        $registry = app(ModelRegistry::class);

        $this->assertSame(Role::class, $registry->role());
        $this->assertSame(Permission::class, $registry->permission());
        $this->assertSame(
            RoleAssignment::class,
            $registry->roleAssignment(),
        );
        $this->assertSame(
            PermissionAssignment::class,
            $registry->permissionAssignment(),
        );
        $this->assertSame(RolePermission::class, $registry->rolePermission());
        $this->assertSame(AccessAudit::class, $registry->accessAudit());
    }

    public function test_custom_model_is_resolved_from_configuration(): void
    {
        config()->set('gatetrail.models.role', CustomRole::class);

        $this->assertSame(
            CustomRole::class,
            app(ModelRegistry::class)->role(),
        );
    }

    public function test_invalid_custom_model_must_implement_the_role_contract(): void
    {
        config()->set('gatetrail.models.role', InvalidRole::class);

        $this->expectException(InvalidModelConfiguration::class);
        $this->expectExceptionMessage('must implement');

        app(ModelRegistry::class)->role();
    }

    public function test_custom_role_model_must_preserve_soft_deletion(): void
    {
        config()->set(
            'gatetrail.models.role',
            RoleWithoutSoftDeletes::class,
        );

        $this->expectException(InvalidModelConfiguration::class);
        $this->expectExceptionMessage('must use SoftDeletes');

        app(ModelRegistry::class)->role();
    }

    public function test_contract_only_assignment_models_are_rejected_with_typed_error(): void
    {
        config()->set(
            'gatetrail.models.role_assignment',
            ContractOnlyRoleAssignment::class,
        );
        config()->set(
            'gatetrail.models.permission_assignment',
            ContractOnlyPermissionAssignment::class,
        );
        config()->set(
            'gatetrail.models.role_permission',
            ContractOnlyRolePermission::class,
        );
        $registry = app(ModelRegistry::class);

        foreach ([
            'roleAssignment',
            'permissionAssignment',
            'rolePermission',
        ] as $accessor) {
            try {
                $registry->{$accessor}();

                $this->fail(sprintf(
                    'Accessor [%s] should reject a contract-only model.',
                    $accessor,
                ));
            } catch (InvalidModelConfiguration $exception) {
                $this->assertStringContainsString(
                    'must extend',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_assignment_model_subclasses_resolve_from_configuration(): void
    {
        config()->set(
            'gatetrail.models.role_assignment',
            ExtendedRoleAssignment::class,
        );
        config()->set(
            'gatetrail.models.permission_assignment',
            ExtendedPermissionAssignment::class,
        );
        config()->set(
            'gatetrail.models.role_permission',
            ExtendedRolePermission::class,
        );
        $registry = app(ModelRegistry::class);

        $this->assertSame(
            ExtendedRoleAssignment::class,
            $registry->roleAssignment(),
        );
        $this->assertSame(
            ExtendedPermissionAssignment::class,
            $registry->permissionAssignment(),
        );
        $this->assertSame(
            ExtendedRolePermission::class,
            $registry->rolePermission(),
        );
    }
}

final class CustomRole extends Role {}

final class InvalidRole extends Model {}

final class RoleWithoutSoftDeletes extends Model implements RoleContract {}

final class ContractOnlyRoleAssignment extends Model implements RoleAssignmentContract {}

final class ContractOnlyPermissionAssignment extends Model implements PermissionAssignmentContract {}

final class ContractOnlyRolePermission extends Model implements RolePermissionContract {}

final class ExtendedRoleAssignment extends RoleAssignment {}

final class ExtendedPermissionAssignment extends PermissionAssignment {}

final class ExtendedRolePermission extends RolePermission {}

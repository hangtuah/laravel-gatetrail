<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Laravel;

use DateTimeImmutable;
use HangTuah\GateTrail\Access\EffectivePermission;
use HangTuah\GateTrail\Access\EffectiveRole;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\RoleReference;
use HangTuah\GateTrail\Tests\Fixtures\AuthorizableSubject;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Events\QueryExecuted;
use PHPUnit\Framework\Attributes\Test;
use Psr\Clock\ClockInterface;

final class HasGateTrailTest extends TestCase
{
    private AuthorizableSubject $subject;

    private SchemaContext $schema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--database' => 'testing'])
            ->assertExitCode(0);
        $this->app->instance(
            ClockInterface::class,
            new FrozenClock(new DateTimeImmutable('2026-10-10 10:00:00')),
        );

        $this->subject = AuthorizableSubject::query()->create([
            'name' => 'Concern subject',
        ]);
        $this->schema = $this->app->make(SchemaContext::class);

        $this->seedDefinition('roles', 10, 'reviewer');
        $this->seedDefinition('roles', 20, 'publisher');
        $this->seedDefinition('permissions', 10, 'documents.view');
        $this->seedDefinition('permissions', 20, 'documents.review');
        $this->seedSubjectAssignment('role_assignments', 'role_id', 10);
        $this->seedSubjectAssignment(
            'permission_assignments',
            'permission_id',
            10,
        );
        $this->seedDefinitionAssignment('role_permissions', 10, 20);
    }

    #[Test]
    public function singular_helpers_delegate_to_the_public_resolvers(): void
    {
        $role = Role::query()->findOrFail(10);
        $permission = Permission::query()->findOrFail(20);

        $this->assertTrue($this->subject->hasRole('reviewer'));
        $this->assertTrue($this->subject->hasRole(RoleReference::id(10)));
        $this->assertTrue($this->subject->hasRole($role));
        $this->assertFalse($this->subject->hasRole('publisher'));
        $this->assertTrue($this->subject->hasPermissionTo('documents.view'));
        $this->assertTrue($this->subject->hasPermissionTo($permission));
        $this->assertFalse($this->subject->hasPermissionTo('documents.publish'));
    }

    #[Test]
    public function any_and_all_roles_use_one_snapshot_and_fail_closed_when_empty(): void
    {
        $this->assertTrue($this->subject->hasAnyRole([
            'publisher',
            'reviewer',
        ]));
        $this->assertTrue($this->subject->hasAllRoles([
            'reviewer',
            RoleReference::id(10),
        ]));
        $this->assertFalse($this->subject->hasAllRoles([
            'reviewer',
            'publisher',
        ]));
        $this->assertFalse($this->subject->hasAnyRole([]));
        $this->assertFalse($this->subject->hasAllRoles([]));
    }

    #[Test]
    public function role_batches_have_a_constant_query_budget(): void
    {
        $statements = 0;
        $this->app->make('db')->connection('testing')->listen(
            static function (QueryExecuted $query) use (&$statements): void {
                $statements++;
            },
        );

        $this->assertTrue($this->subject->hasAnyRole([
            'missing.1', 'missing.2', 'missing.3', 'missing.4',
            'missing.5', 'missing.6', 'reviewer',
        ]));

        $this->assertSame(3, $statements);
    }

    #[Test]
    public function effective_collections_are_the_resolver_value_objects(): void
    {
        $permissions = $this->subject->effectivePermissions();
        $roles = $this->subject->roles();

        $this->assertSame(
            ['documents.review', 'documents.view'],
            $permissions->map(
                static fn (EffectivePermission $permission): string => $permission->name,
            )->all(),
        );
        $this->assertSame(
            ['reviewer'],
            $roles->map(
                static fn (EffectiveRole $role): string => $role->name,
            )->all(),
        );
    }

    #[Test]
    public function assignment_relations_expose_history_without_becoming_mutation_apis(): void
    {
        $this->assertCount(1, $this->subject->roleAssignments);
        $this->assertCount(1, $this->subject->permissionAssignments);
        $this->assertSame('reviewer', $this->subject->roleAssignments->first()->role->name);
        $this->assertSame(
            'documents.view',
            $this->subject->permissionAssignments->first()->permission->name,
        );
    }

    #[Test]
    public function direct_permissions_is_a_raw_definition_relation_not_an_authorization_check(): void
    {
        $this->assertSame(
            ['documents.view'],
            $this->subject->directPermissions->pluck('name')->all(),
        );

        $this->app->make('db')->connection('testing')
            ->table($this->schema->table('permission_assignments'))
            ->update([
                'revoked_at' => '2026-10-10 10:00:00',
                'revoked_by_type' => 'gatetrail.system',
                'revoked_by_id' => 'test',
                'revocation_reason' => 'Concern relation test',
            ]);
        $this->subject->unsetRelation('directPermissions');

        $this->assertSame(
            ['documents.view'],
            $this->subject->directPermissions->pluck('name')->all(),
        );
        $this->assertFalse($this->subject->hasPermissionTo('documents.view'));
    }

    private function seedDefinition(string $table, int $id, string $name): void
    {
        $now = '2026-10-10 10:00:00';

        $this->app->make('db')->connection('testing')
            ->table($this->schema->table($table))
            ->insert([
                'id' => $id,
                'name' => $name,
                'guard_name' => 'web',
                'description' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ]);
    }

    private function seedSubjectAssignment(
        string $table,
        string $definitionColumn,
        int $definitionId,
    ): void {
        $this->seedAssignment($table, [
            'subject_type' => $this->subject->getMorphClass(),
            'subject_id' => $this->subject->getKey(),
            $definitionColumn => $definitionId,
        ]);
    }

    private function seedDefinitionAssignment(
        string $table,
        int $roleId,
        int $permissionId,
    ): void {
        $this->seedAssignment($table, [
            'role_id' => $roleId,
            'permission_id' => $permissionId,
        ]);
    }

    /** @param array<string, int|string> $identity */
    private function seedAssignment(string $table, array $identity): void
    {
        $now = '2026-10-10 10:00:00';

        $this->app->make('db')->connection('testing')
            ->table($this->schema->table($table))
            ->insert([
                ...$identity,
                'assigned_at' => $now,
                'assigned_by_type' => 'gatetrail.system',
                'assigned_by_id' => 'test',
                'assignment_reason' => 'Concern fixture',
                'starts_at' => null,
                'expires_at' => null,
                'revoked_at' => null,
                'revoked_by_type' => null,
                'revoked_by_id' => null,
                'revocation_reason' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
    }
}

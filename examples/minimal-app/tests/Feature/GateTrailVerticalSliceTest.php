<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use HangTuah\GateTrail\Contracts\AssignmentManager;
use HangTuah\GateTrail\Contracts\PermissionManager;
use HangTuah\GateTrail\Contracts\RoleManager;
use HangTuah\GateTrail\Models\AccessAudit;
use HangTuah\GateTrail\Models\RoleAssignment;
use HangTuah\GateTrail\SystemActor;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

final class GateTrailVerticalSliceTest extends TestCase
{
    use DatabaseMigrations;

    public function test_a_fresh_consumer_can_complete_the_documented_flow(): void
    {
        $actor = SystemActor::named('minimal-example');
        $roles = $this->app->make(RoleManager::class);
        $permissions = $this->app->make(PermissionManager::class);
        $assignments = $this->app->make(AssignmentManager::class);
        $user = User::query()->create([
            'name' => 'Example Reviewer',
            'email' => 'reviewer@example.test',
            'password' => 'not-used-in-this-example',
        ]);

        $role = $roles->create(
            name: 'reviewer',
            guard: 'web',
            actor: $actor,
            reason: 'Provision the example role',
        )->resource;
        $permission = $permissions->create(
            name: 'documents.approve',
            guard: 'web',
            actor: $actor,
            reason: 'Provision the example permission',
        )->resource;
        $assignments->grantPermissionToRole(
            role: $role,
            permission: $permission,
            actor: $actor,
            reason: 'Reviewers may approve documents',
        );
        $roleAssignment = $assignments->assignRole(
            subject: $user,
            role: $role,
            actor: $actor,
            reason: 'Assign the example reviewer',
        )->resource;

        $this->assertTrue($user->can('documents.approve'));
        $this->actingAs($user)
            ->getJson('/documents/42/approve')
            ->assertOk()
            ->assertExactJson([
                'document' => 42,
                'status' => 'approved',
            ]);

        $assignments->revokeRole(
            assignment: $roleAssignment,
            actor: $actor,
            reason: 'Remove the example reviewer',
        );

        $this->assertFalse($user->can('documents.approve'));
        $this->actingAs($user)
            ->getJson('/documents/42/approve')
            ->assertForbidden();
        $this->assertSame(1, RoleAssignment::query()->count());
        $this->assertNotNull($roleAssignment->fresh()?->revoked_at);
        $this->assertSame(5, AccessAudit::query()->count());
        $this->assertSame([
            'Provision the example role',
            'Provision the example permission',
            'Reviewers may approve documents',
            'Assign the example reviewer',
            'Remove the example reviewer',
        ], AccessAudit::query()->orderBy('id')->pluck('reason')->all());
        $this->assertSame(
            ['minimal-example'],
            AccessAudit::query()->distinct()->pluck('actor_id')->all(),
        );
    }
}

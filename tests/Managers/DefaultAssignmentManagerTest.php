<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Managers;

use DateTimeImmutable;
use HangTuah\GateTrail\AssignmentPeriod;
use HangTuah\GateTrail\AssignmentReference;
use HangTuah\GateTrail\Contracts\AssignmentManager;
use HangTuah\GateTrail\Exceptions\AssignmentNotActive;
use HangTuah\GateTrail\Managers\DefaultAssignmentManager;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;
use Psr\Clock\ClockInterface;
use ReflectionClass;
use ReflectionMethod;

final class DefaultAssignmentManagerTest extends TestCase
{
    private TestSubject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);

        $this->subject = TestSubject::query()->create([
            'name' => 'Reviewer',
        ]);
        Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'web',
            'description' => null,
        ]);
        $this->app->instance(
            ClockInterface::class,
            new FrozenClock(
                new DateTimeImmutable('2026-07-29 12:00:00 UTC'),
            ),
        );
    }

    public function test_contract_resolves_to_the_default_manager(): void
    {
        $manager = app(AssignmentManager::class);

        $this->assertInstanceOf(
            DefaultAssignmentManager::class,
            $manager,
        );
        $this->assertSame(
            $manager,
            app(AssignmentManager::class),
        );
    }

    public function test_public_api_assigns_an_immediate_role(): void
    {
        $result = app(AssignmentManager::class)->assignRole(
            subject: $this->subject,
            role: 'reviewer',
            actor: SystemActor::named('test-suite'),
            reason: 'Assigned through public manager',
        );

        $this->assertSame(MutationOutcome::Created, $result->outcome);
        $this->assertNull($result->resource->getAttribute('starts_at'));
        $this->assertNull($result->resource->getAttribute('expires_at'));
        $this->assertNotNull($result->auditId);
    }

    public function test_public_api_preserves_period_and_metadata(): void
    {
        $result = app(AssignmentManager::class)->assignRole(
            subject: $this->subject,
            role: 'reviewer',
            actor: SystemActor::named('test-suite'),
            reason: 'Covering staff leave',
            period: AssignmentPeriod::between(
                new DateTimeImmutable('2026-08-01 08:00:00 UTC'),
                new DateTimeImmutable('2026-08-31 17:00:00 UTC'),
            ),
            metadata: ['source' => 'staff-system'],
        );
        $audit = $this->app['db']
            ->table('acl_access_audits')
            ->where('id', $result->auditId)
            ->first();

        $this->assertSame(
            '2026-08-01 08:00:00',
            $result->resource->getAttribute('starts_at')
                ?->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-08-31 17:00:00',
            $result->resource->getAttribute('expires_at')
                ?->format('Y-m-d H:i:s'),
        );
        $this->assertNotNull($audit);
        $this->assertSame(
            ['source' => 'staff-system'],
            json_decode(
                $audit->context,
                true,
                flags: JSON_THROW_ON_ERROR,
            )['metadata'],
        );
    }

    public function test_public_replay_returns_unchanged(): void
    {
        $manager = app(AssignmentManager::class);

        $first = $manager->assignRole(
            $this->subject,
            'reviewer',
            SystemActor::named('test-suite'),
            'Initial assignment',
        );
        $second = $manager->assignRole(
            $this->subject,
            'reviewer',
            SystemActor::named('test-suite'),
            'Replay assignment',
        );

        $this->assertSame(MutationOutcome::Created, $first->outcome);
        $this->assertSame(MutationOutcome::Unchanged, $second->outcome);
        $this->assertNull($second->auditId);
        $this->assertDatabaseCount('acl_role_assignments', 1);
        $this->assertDatabaseCount('acl_access_audits', 1);
    }

    public function test_public_api_revokes_role_with_reason_and_metadata(): void
    {
        $manager = app(AssignmentManager::class);
        $assigned = $manager->assignRole(
            $this->subject,
            'reviewer',
            SystemActor::named('test-suite'),
            'Initial assignment',
        );

        $revoked = $manager->revokeRole(
            assignment: $assigned->resource,
            actor: SystemActor::named('security-suite'),
            reason: 'Transferred to another unit',
            metadata: ['ticket' => 'SEC-42'],
        );
        $audit = $this->app['db']
            ->table('acl_access_audits')
            ->where('id', $revoked->auditId)
            ->first();

        $this->assertSame(MutationOutcome::Revoked, $revoked->outcome);
        $this->assertSame(
            'Transferred to another unit',
            $revoked->resource->getAttribute('revocation_reason'),
        );
        $this->assertNotNull($audit);
        $this->assertSame('role.revoked', $audit->event);
        $this->assertSame(
            ['ticket' => 'SEC-42'],
            json_decode(
                $audit->context,
                true,
                flags: JSON_THROW_ON_ERROR,
            )['metadata'],
        );
    }

    public function test_public_revoke_accepts_reference_and_rejects_replay(): void
    {
        $manager = app(AssignmentManager::class);
        $assigned = $manager->assignRole(
            $this->subject,
            'reviewer',
            SystemActor::named('test-suite'),
            'Initial assignment',
        );
        $reference = AssignmentReference::id(
            (string) $assigned->resource->getKey(),
        );

        $manager->revokeRole(
            $reference,
            SystemActor::named('security-suite'),
            'First revocation',
        );

        $this->expectException(AssignmentNotActive::class);

        $manager->revokeRole(
            (string) $assigned->resource->getKey(),
            SystemActor::named('security-suite'),
            'Repeated revocation',
        );
    }

    public function test_current_public_contract_exposes_no_stub_mutations(): void
    {
        $methods = array_values(array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            array_filter(
                (new ReflectionClass(AssignmentManager::class))
                    ->getMethods(ReflectionMethod::IS_PUBLIC),
                static fn (ReflectionMethod $method): bool => $method
                    ->getDeclaringClass()
                    ->getName() === AssignmentManager::class,
            ),
        ));

        $this->assertSame(
            [
                'assignRole',
                'revokeRole',
                'grantDirectPermission',
                'revokeDirectPermission',
                'grantPermissionToRole',
            ],
            $methods,
        );
    }

    public function test_public_direct_permission_grant_and_revoke(): void
    {
        Permission::query()->create([
            'name' => 'documents.approve',
            'guard_name' => 'web',
        ]);
        $manager = app(AssignmentManager::class);
        $granted = $manager->grantDirectPermission(
            $this->subject,
            'documents.approve',
            SystemActor::named('security-suite'),
            'Grant direct permission',
        );
        $revoked = $manager->revokeDirectPermission(
            $granted->resource,
            SystemActor::named('security-suite'),
            'Revoke direct permission',
        );

        $this->assertSame(MutationOutcome::Created, $granted->outcome);
        $this->assertSame(MutationOutcome::Revoked, $revoked->outcome);
        $this->assertDatabaseHas('acl_access_audits', [
            'event' => 'permission.revoked_from_subject',
        ]);
    }

    public function test_public_api_grants_permission_to_role(): void
    {
        Permission::query()->create([
            'name' => 'documents.approve',
            'guard_name' => 'web',
        ]);

        $result = app(AssignmentManager::class)
            ->grantPermissionToRole(
                role: 'reviewer',
                permission: 'documents.approve',
                actor: SystemActor::named('security-suite'),
                reason: 'Reviewer may approve documents',
                metadata: ['ticket' => 'SEC-42'],
            );

        $this->assertSame(MutationOutcome::Created, $result->outcome);
        $this->assertNotNull($result->auditId);
        $this->assertDatabaseHas('acl_access_audits', [
            'event' => 'permission.granted_to_role',
            'target_type' => 'role_permission',
            'target_id' => $result->resource->getKey(),
        ]);
    }
}

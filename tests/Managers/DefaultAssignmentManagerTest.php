<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Managers;

use DateTimeImmutable;
use HangTuah\GateTrail\AssignmentPeriod;
use HangTuah\GateTrail\Contracts\AssignmentManager;
use HangTuah\GateTrail\Managers\DefaultAssignmentManager;
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

        $this->assertSame(['assignRole'], $methods);
    }
}

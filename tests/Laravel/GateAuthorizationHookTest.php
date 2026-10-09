<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Laravel;

use DateTimeImmutable;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Exceptions\InvalidGuard;
use HangTuah\GateTrail\Laravel\GateAuthorizationHook;
use HangTuah\GateTrail\Tests\Fixtures\AuthorizableSubject;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Psr\Clock\ClockInterface;
use stdClass;

final class GateAuthorizationHookTest extends TestCase
{
    private AuthorizableSubject $allowed;

    private AuthorizableSubject $denied;

    private SchemaContext $schema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--database' => 'testing'])->assertExitCode(0);
        $this->app->instance(
            ClockInterface::class,
            new FrozenClock(new DateTimeImmutable('2026-10-10 10:00:00')),
        );

        $this->allowed = AuthorizableSubject::query()->create(['name' => 'Allowed']);
        $this->denied = AuthorizableSubject::query()->create(['name' => 'Denied']);
        $this->schema = $this->app->make(SchemaContext::class);

        $this->seedPermission(10, 'documents.approve', assigned: true);
        $this->seedPermission(20, 'documents.unassigned');
        $this->seedPermission(30, 'documents.suspended', deactivated: true);
        $this->seedPermission(40, 'Documents.Exact');
    }

    #[Test]
    public function an_active_effective_permission_owns_an_argument_free_gate_check(): void
    {
        $this->assertTrue($this->gate()->forUser($this->allowed)->allows('documents.approve'));
        $this->assertTrue($this->allowed->can('documents.approve'));
        $this->assertFalse($this->denied->can('documents.approve'));
    }

    #[Test]
    public function an_active_but_unassigned_permission_denies_authoritatively(): void
    {
        $this->gate()->define('documents.unassigned', static fn (): bool => true);

        $this->assertFalse($this->allowed->can('documents.unassigned'));
    }

    #[Test]
    public function a_deactivated_permission_denies_instead_of_falling_through(): void
    {
        $this->gate()->define('documents.suspended', static fn (): bool => true);

        $this->assertFalse($this->allowed->can('documents.suspended'));
    }

    #[Test]
    public function a_missing_definition_falls_through_to_an_application_gate(): void
    {
        $this->gate()->define('application.ability', static fn (): bool => true);

        $this->assertTrue($this->allowed->can('application.ability'));
    }

    #[Test]
    public function definition_matching_is_exact_and_case_preserving(): void
    {
        $this->gate()->define('documents.exact', static fn (): bool => true);

        $this->assertTrue($this->allowed->can('documents.exact'));
        $this->assertFalse($this->allowed->can('Documents.Exact'));
    }

    #[Test]
    public function resource_arguments_bypass_gatetrail_and_continue_to_a_policy(): void
    {
        $this->gate()->policy(GateDocument::class, GateDocumentPolicy::class);

        $owned = new GateDocument($this->allowed->getKey());
        $foreign = new GateDocument($this->denied->getKey());

        $this->assertTrue($this->allowed->can('update', $owned));
        $this->assertFalse($this->allowed->can('update', $foreign));
    }

    #[Test]
    public function gate_for_user_evaluates_the_supplied_subject(): void
    {
        $this->assertTrue($this->gate()->forUser($this->allowed)->allows('documents.approve'));
        $this->assertFalse($this->gate()->forUser($this->denied)->allows('documents.approve'));
    }

    #[Test]
    public function gate_authorize_uses_laravels_normal_authorization_exception(): void
    {
        $this->gate()->forUser($this->allowed)->authorize('documents.approve');

        $this->expectException(AuthorizationException::class);

        $this->gate()->forUser($this->denied)->authorize('documents.approve');
    }

    #[Test]
    public function blade_can_uses_the_same_gate_hook(): void
    {
        $this->app->make('auth')->setUser($this->allowed);

        $allowed = Blade::render(
            '@can("documents.approve") allowed @endcan',
        );
        $denied = Blade::render(
            '@cannot("documents.unassigned") denied @endcannot',
        );

        $this->assertSame('allowed', trim($allowed));
        $this->assertSame('denied', trim($denied));
    }

    #[Test]
    public function unsupported_subjects_and_argumented_checks_return_null_directly(): void
    {
        $hook = $this->app->make(GateAuthorizationHook::class);

        $this->assertNull($hook->authorize(new stdClass, 'documents.approve'));
        $this->assertNull($hook->authorize(
            $this->allowed,
            'documents.approve',
            [new stdClass],
        ));
    }

    #[Test]
    public function an_unauthenticated_gate_check_denies_without_an_exception(): void
    {
        $this->app->make('auth')->forgetGuards();

        $this->assertFalse($this->gate()->allows('documents.approve'));
    }

    #[Test]
    public function invalid_guard_configuration_remains_visible(): void
    {
        $subject = new InvalidGuardSubject;
        $subject->setRawAttributes(['id' => 999], true);
        $subject->exists = true;

        $this->expectException(InvalidGuard::class);

        $this->app->make(GateAuthorizationHook::class)
            ->authorize($subject, 'documents.approve');
    }

    #[Test]
    public function database_failures_remain_visible(): void
    {
        DB::connection('testing')->getSchemaBuilder()->rename(
            $this->schema->table('permissions'),
            $this->schema->table('permissions').'_missing',
        );

        $this->expectException(QueryException::class);

        $this->allowed->can('documents.approve');
    }

    #[Test]
    public function permission_rows_are_not_registered_as_individual_gate_abilities(): void
    {
        $this->assertArrayNotHasKey('documents.approve', $this->gate()->abilities());
        $this->assertArrayNotHasKey('documents.unassigned', $this->gate()->abilities());
        $this->assertArrayNotHasKey('documents.suspended', $this->gate()->abilities());
    }

    private function gate(): Gate
    {
        return $this->app->make(Gate::class);
    }

    private function seedPermission(
        int $id,
        string $name,
        bool $assigned = false,
        bool $deactivated = false,
    ): void {
        $now = '2026-10-10 10:00:00';

        DB::connection('testing')
            ->table($this->schema->table('permissions'))
            ->insert([
                'id' => $id,
                'name' => $name,
                'guard_name' => 'web',
                'description' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => $deactivated ? $now : null,
            ]);

        if (! $assigned) {
            return;
        }

        DB::connection('testing')
            ->table($this->schema->table('permission_assignments'))
            ->insert([
                'subject_type' => $this->allowed->getMorphClass(),
                'subject_id' => $this->allowed->getKey(),
                'permission_id' => $id,
                'assigned_at' => $now,
                'assigned_by_type' => 'gatetrail.system',
                'assigned_by_id' => 'test',
                'assignment_reason' => 'Gate integration fixture',
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

final class GateDocument
{
    public function __construct(public int|string $ownerId) {}
}

final class GateDocumentPolicy
{
    public function update(AuthorizableSubject $subject, GateDocument $document): bool
    {
        return $subject->getKey() === $document->ownerId;
    }
}

final class InvalidGuardSubject extends Model
{
    protected $table = 'test_subjects';

    public $timestamps = false;

    public function gateTrailGuardName(): string
    {
        return '   ';
    }
}

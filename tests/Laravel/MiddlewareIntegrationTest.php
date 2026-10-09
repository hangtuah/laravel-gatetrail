<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Laravel;

use DateTimeImmutable;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Exceptions\InvalidMiddlewareParameter;
use HangTuah\GateTrail\Exceptions\InvalidSubject;
use HangTuah\GateTrail\GateTrailServiceProvider;
use HangTuah\GateTrail\Laravel\Middleware\RequireAllPermissions;
use HangTuah\GateTrail\Laravel\Middleware\RequireAllRoles;
use HangTuah\GateTrail\Laravel\Middleware\RequireAnyPermission;
use HangTuah\GateTrail\Laravel\Middleware\RequireAnyRole;
use HangTuah\GateTrail\Laravel\Middleware\RequirePermission;
use HangTuah\GateTrail\Laravel\Middleware\RequireRole;
use HangTuah\GateTrail\Tests\Fixtures\AuthorizableSubject;
use HangTuah\GateTrail\Tests\Fixtures\FrozenClock;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Psr\Clock\ClockInterface;
use stdClass;

final class MiddlewareIntegrationTest extends TestCase
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
            'name' => 'Middleware subject',
        ]);
        $this->schema = $this->app->make(SchemaContext::class);

        $this->seedDefinition('roles', 10, 'reviewer');
        $this->seedDefinition('roles', 20, 'publisher');
        $this->seedDefinition('permissions', 10, 'documents.view');
        $this->seedDefinition('permissions', 20, 'documents.review');
        $this->seedDefinition('permissions', 30, 'Documents.Exact');
        $this->seedSubjectAssignment('role_assignments', 'role_id', 10);
        $this->seedSubjectAssignment('role_assignments', 'role_id', 20);
        $this->seedSubjectAssignment(
            'permission_assignments',
            'permission_id',
            10,
        );
        $this->seedDefinitionAssignment('role_permissions', 10, 20);
    }

    #[Test]
    public function all_six_aliases_are_registered_to_the_accepted_classes(): void
    {
        $aliases = $this->app->make(Router::class)->getMiddleware();

        $this->assertSame(RequirePermission::class, $aliases['gatetrail.permission']);
        $this->assertSame(RequireAnyPermission::class, $aliases['gatetrail.permission.any']);
        $this->assertSame(RequireAllPermissions::class, $aliases['gatetrail.permission.all']);
        $this->assertSame(RequireRole::class, $aliases['gatetrail.role']);
        $this->assertSame(RequireAnyRole::class, $aliases['gatetrail.role.any']);
        $this->assertSame(RequireAllRoles::class, $aliases['gatetrail.role.all']);
    }

    #[Test]
    public function registered_aliases_execute_in_the_real_route_pipeline(): void
    {
        $this->app->make(Router::class)
            ->get('/gatetrail-protected', static fn (): string => 'allowed')
            ->middleware([
                'gatetrail.role:reviewer',
                'gatetrail.permission:documents.view',
            ]);
        $this->app->make('auth')->setUser($this->subject);

        $this->get('/gatetrail-protected')
            ->assertOk()
            ->assertSeeText('allowed');
    }

    #[Test]
    public function route_denials_are_generic_laravel_forbidden_responses(): void
    {
        $this->app->make(Router::class)
            ->get('/gatetrail-denied', static fn (): string => 'unreachable')
            ->middleware('gatetrail.permission:documents.secret');
        $this->app->make('auth')->setUser($this->subject);

        $this->get('/gatetrail-denied')
            ->assertForbidden()
            ->assertDontSeeText('documents.secret');
    }

    #[Test]
    public function permission_one_any_and_all_use_one_effective_snapshot(): void
    {
        $request = $this->requestFor($this->subject);

        $this->assertSame('next', $this->executeMiddleware(
            RequirePermission::class,
            $request,
            ' documents.view ',
        ));
        $this->assertSame('next', $this->executeMiddleware(
            RequireAnyPermission::class,
            $request,
            'missing',
            'documents.review',
        ));
        $this->assertSame('next', $this->executeMiddleware(
            RequireAllPermissions::class,
            $request,
            'documents.view',
            'documents.review',
        ));
    }

    #[Test]
    public function role_one_any_and_all_use_one_effective_snapshot(): void
    {
        $request = $this->requestFor($this->subject);

        $this->assertSame('next', $this->executeMiddleware(
            RequireRole::class,
            $request,
            'reviewer',
        ));
        $this->assertSame('next', $this->executeMiddleware(
            RequireAnyRole::class,
            $request,
            'missing',
            'publisher',
        ));
        $this->assertSame('next', $this->executeMiddleware(
            RequireAllRoles::class,
            $request,
            'reviewer',
            'publisher',
        ));
    }

    #[Test]
    public function missing_and_case_mismatched_names_deny_generically(): void
    {
        foreach (['missing.secret', 'documents.exact'] as $name) {
            try {
                $this->executeMiddleware(
                    RequirePermission::class,
                    $this->requestFor($this->subject),
                    $name,
                );
                $this->fail('A missing permission was accepted.');
            } catch (AuthorizationException $exception) {
                $this->assertStringNotContainsString(
                    $name,
                    $exception->getMessage(),
                );
            }
        }
    }

    #[Test]
    public function an_unrelated_application_gate_cannot_satisfy_permission_middleware(): void
    {
        $this->app->make(Gate::class)->define(
            'application.only',
            static fn (): bool => true,
        );

        $this->expectException(AuthorizationException::class);

        $this->executeMiddleware(
            RequirePermission::class,
            $this->requestFor($this->subject),
            'application.only',
        );
    }

    #[Test]
    public function unauthenticated_requests_raise_laravels_authentication_exception(): void
    {
        $this->expectException(AuthenticationException::class);

        $this->executeMiddleware(
            RequirePermission::class,
            $this->requestFor(null),
            'documents.view',
        );
    }

    #[Test]
    public function authenticated_non_model_subjects_remain_visible_configuration_errors(): void
    {
        $this->expectException(InvalidSubject::class);

        $this->executeMiddleware(
            RequirePermission::class,
            $this->requestFor(new stdClass),
            'documents.view',
        );
    }

    #[Test]
    public function invalid_route_parameters_fail_before_subject_resolution(): void
    {
        $this->expectException(InvalidMiddlewareParameter::class);

        $this->executeMiddleware(
            RequirePermission::class,
            $this->requestFor(null),
            'documents.view',
            'documents.review',
        );
    }

    #[Test]
    public function any_checks_have_a_constant_query_budget_for_long_lists(): void
    {
        $statements = 0;
        $this->app->make('db')->connection('testing')->listen(
            static function (QueryExecuted $query) use (&$statements): void {
                $statements++;
            },
        );

        $this->executeMiddleware(
            RequireAnyPermission::class,
            $this->requestFor($this->subject),
            ...[
                'missing.1', 'missing.2', 'missing.3', 'missing.4',
                'missing.5', 'missing.6', 'missing.7', 'missing.8',
                'documents.review',
            ],
        );

        $this->assertSame(3, $statements);
    }

    #[Test]
    public function an_existing_unrelated_alias_is_not_silently_replaced(): void
    {
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('gatetrail.permission', stdClass::class);

        $this->expectException(LogicException::class);

        (new GateTrailServiceProvider($this->app))->boot();
    }

    private function requestFor(mixed $subject): Request
    {
        $request = Request::create('/protected');
        $request->setUserResolver(static fn (): mixed => $subject);

        return $request;
    }

    /**
     * @param  class-string  $middleware
     */
    private function executeMiddleware(
        string $middleware,
        Request $request,
        string ...$parameters,
    ): mixed {
        return $this->app->make($middleware)->handle(
            $request,
            static fn (): string => 'next',
            ...$parameters,
        );
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
                'assignment_reason' => 'Middleware fixture',
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

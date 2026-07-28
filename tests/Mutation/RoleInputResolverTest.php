<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use HangTuah\GateTrail\Contracts\RoleContract;
use HangTuah\GateTrail\Exceptions\GuardMismatch;
use HangTuah\GateTrail\Exceptions\RoleNotFound;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\Mutations\RoleInputResolver;
use HangTuah\GateTrail\RoleReference;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class RoleInputResolverTest extends TestCase
{
    private Role $webRole;

    private Role $adminRole;

    private TestSubject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);

        $this->webRole = Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'web',
        ]);
        $this->adminRole = Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'admin',
        ]);
        $this->subject = TestSubject::query()->create([
            'name' => 'Reviewer',
        ]);
    }

    public function test_string_input_resolves_as_name_within_subject_guard(): void
    {
        $resolved = $this->resolve('reviewer');

        $this->assertTrue($resolved->is($this->webRole));
    }

    public function test_integer_input_resolves_as_primary_key(): void
    {
        $resolved = $this->resolve($this->webRole->getKey());

        $this->assertTrue($resolved->is($this->webRole));
    }

    public function test_explicit_string_id_reference_resolves_as_primary_key(): void
    {
        $resolved = $this->resolve(
            RoleReference::id((string) $this->webRole->getKey()),
        );

        $this->assertTrue($resolved->is($this->webRole));
    }

    public function test_explicit_model_is_used_directly(): void
    {
        $resolved = $this->resolve($this->webRole);

        $this->assertSame($this->webRole, $resolved);
    }

    public function test_numeric_looking_string_remains_a_name(): void
    {
        Role::query()->create([
            'name' => (string) $this->webRole->getKey(),
            'guard_name' => 'web',
        ]);

        $resolved = $this->resolve(
            (string) $this->webRole->getKey(),
        );

        $this->assertSame(
            (string) $this->webRole->getKey(),
            $resolved->name,
        );
    }

    public function test_explicit_cross_guard_id_throws_guard_mismatch(): void
    {
        $this->expectException(GuardMismatch::class);
        $this->expectExceptionMessage(
            'subject guard [web] does not match role guard [admin]',
        );

        $this->resolve($this->adminRole->getKey());
    }

    public function test_name_does_not_fall_back_to_another_guard(): void
    {
        $this->adminRole->update(['name' => 'admin-only']);

        $this->expectException(RoleNotFound::class);
        $this->expectExceptionMessage('guard [web]');

        $this->resolve('admin-only');
    }

    public function test_soft_deleted_role_cannot_receive_a_new_assignment(): void
    {
        $this->webRole->delete();

        $this->expectException(RoleNotFound::class);

        $this->resolve($this->webRole);
    }

    public function test_role_resolution_requires_an_existing_transaction(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('inside a database transaction');

        app(RoleInputResolver::class)->resolve(
            $this->subject,
            'reviewer',
        );
    }

    private function resolve(
        RoleContract|RoleReference|string|int $input,
    ): Model&RoleContract {
        return $this->app['db']->connection('testing')->transaction(
            fn () => app(RoleInputResolver::class)->resolve(
                $this->subject,
                $input,
            ),
        );
    }
}

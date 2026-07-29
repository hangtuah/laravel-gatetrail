<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use HangTuah\GateTrail\Exceptions\GuardMismatch;
use HangTuah\GateTrail\Exceptions\PermissionNotFound;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Mutations\PermissionInputResolver;
use HangTuah\GateTrail\PermissionReference;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;
use LogicException;

final class PermissionInputResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--database' => 'testing'])->assertExitCode(0);
    }

    public function test_name_id_and_reference_resolve_inside_transaction(): void
    {
        $subject = TestSubject::query()->create(['name' => 'Reviewer']);
        $permission = Permission::query()->create(['name' => 'documents.approve', 'guard_name' => 'web']);

        $this->app['db']->connection('testing')->transaction(function () use ($subject, $permission): void {
            foreach (['documents.approve', $permission->getKey(), PermissionReference::id($permission->getKey())] as $input) {
                $this->assertSame(
                    $permission->getKey(),
                    app(PermissionInputResolver::class)->resolve($subject, $input)->getKey(),
                );
            }
        });
    }

    public function test_missing_and_guard_mismatch_are_typed(): void
    {
        $subject = TestSubject::query()->create(['name' => 'Reviewer']);
        $permission = Permission::query()->create(['name' => 'admin.only', 'guard_name' => 'admin']);

        try {
            $this->app['db']->connection('testing')->transaction(
                fn () => app(PermissionInputResolver::class)->resolve($subject, 'missing'),
            );
            $this->fail();
        } catch (PermissionNotFound) {
            $this->assertTrue(true);
        }

        $this->expectException(GuardMismatch::class);
        $this->app['db']->connection('testing')->transaction(
            fn () => app(PermissionInputResolver::class)->resolve($subject, $permission->getKey()),
        );
    }

    public function test_resolution_requires_transaction(): void
    {
        $subject = TestSubject::query()->create(['name' => 'Reviewer']);
        $this->expectException(LogicException::class);
        app(PermissionInputResolver::class)->resolve($subject, 'anything');
    }
}

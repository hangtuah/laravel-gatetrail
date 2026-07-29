<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use HangTuah\GateTrail\Exceptions\RoleNotFound;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\Mutations\DefinitionIdentifierNormalizer;
use HangTuah\GateTrail\Mutations\RoleDefinitionInputResolver;
use HangTuah\GateTrail\RoleReference;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class RoleDefinitionInputResolverTest extends TestCase
{
    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);
        $this->role = Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'web',
            'description' => 'Reviews documents.',
        ]);
    }

    public function test_model_input_is_reloaded_from_persisted_state(): void
    {
        $this->role->setAttribute('description', 'Stale in-memory value');

        $this->transaction(function (): void {
            $resolved = app(RoleDefinitionInputResolver::class)
                ->resolve($this->role);

            $this->assertSame(
                $this->role->getKey(),
                $resolved->getKey(),
            );
            $this->assertNotSame($this->role, $resolved);
            $this->assertSame(
                'Reviews documents.',
                $resolved->getAttribute('description'),
            );
        });
    }

    public function test_integer_and_reference_ids_resolve_the_same_row(): void
    {
        $this->transaction(function (): void {
            $inputs = [
                $this->role->getKey(),
                RoleReference::id($this->role->getKey()),
                RoleReference::id((string) $this->role->getKey()),
            ];

            foreach ($inputs as $input) {
                $this->assertSame(
                    $this->role->getKey(),
                    app(RoleDefinitionInputResolver::class)
                        ->resolve($input)
                        ->getKey(),
                );
            }
        });
    }

    public function test_string_input_resolves_by_name_in_the_package_default_guard(): void
    {
        config()->set('gatetrail.guards.default', 'api');
        $apiRole = Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'api',
            'description' => null,
        ]);

        $this->transaction(function () use ($apiRole): void {
            $this->assertSame(
                $apiRole->getKey(),
                app(RoleDefinitionInputResolver::class)
                    ->resolve('reviewer')
                    ->getKey(),
            );
        });
    }

    public function test_string_input_falls_back_to_the_laravel_default_guard(): void
    {
        config()->set('gatetrail.guards.default', null);
        config()->set('auth.defaults.guard', 'web');

        $this->transaction(function (): void {
            $this->assertSame(
                $this->role->getKey(),
                app(RoleDefinitionInputResolver::class)
                    ->resolve('reviewer')
                    ->getKey(),
            );
        });
    }

    public function test_numeric_looking_string_remains_a_name(): void
    {
        $numericName = Role::query()->create([
            'name' => '123',
            'guard_name' => 'web',
            'description' => null,
        ]);

        $this->transaction(function () use ($numericName): void {
            $this->assertSame(
                $numericName->getKey(),
                app(RoleDefinitionInputResolver::class)
                    ->resolve('123')
                    ->getKey(),
            );
            $this->assertSame(
                $this->role->getKey(),
                app(RoleDefinitionInputResolver::class)
                    ->resolve((int) $this->role->getKey())
                    ->getKey(),
            );
        });
    }

    public function test_non_default_guard_role_resolves_through_explicit_inputs(): void
    {
        $apiRole = Role::query()->create([
            'name' => 'auditor',
            'guard_name' => 'api',
            'description' => null,
        ]);

        $this->transaction(function () use ($apiRole): void {
            $resolver = app(RoleDefinitionInputResolver::class);

            $this->assertSame(
                $apiRole->getKey(),
                $resolver->resolve($apiRole)->getKey(),
            );
            $this->assertSame(
                $apiRole->getKey(),
                $resolver->resolve($apiRole->getKey())->getKey(),
            );
            $this->assertSame(
                $apiRole->getKey(),
                $resolver
                    ->resolve(RoleReference::id($apiRole->getKey()))
                    ->getKey(),
            );
        });
    }

    public function test_missing_role_throws_role_not_found(): void
    {
        $this->transaction(function (): void {
            $resolver = app(RoleDefinitionInputResolver::class);

            try {
                $resolver->resolve('unknown-role');

                $this->fail('A missing name should be rejected.');
            } catch (RoleNotFound $exception) {
                $this->assertSame('web', $exception->guard);
                $this->assertSame('name', $exception->referenceType);
            }

            try {
                $resolver->resolve(4242);

                $this->fail('A missing ID should be rejected.');
            } catch (RoleNotFound $exception) {
                $this->assertSame('ID', $exception->referenceType);
            }
        });
    }

    public function test_soft_deleted_role_throws_role_not_found(): void
    {
        $this->role->delete();

        $this->transaction(function (): void {
            $resolver = app(RoleDefinitionInputResolver::class);

            foreach ([
                'reviewer',
                $this->role->getKey(),
                RoleReference::id($this->role->getKey()),
            ] as $input) {
                try {
                    $resolver->resolve($input);

                    $this->fail('A deactivated role should be rejected.');
                } catch (RoleNotFound $exception) {
                    $this->assertSame('web', $exception->guard);
                }
            }
        });
    }

    public function test_malformed_reference_ids_are_rejected_before_querying(): void
    {
        $this->transaction(function (): void {
            foreach ([' 1', '1 ', '+1', '-1', '1.0', '1e3', 'abc'] as $id) {
                try {
                    app(RoleDefinitionInputResolver::class)
                        ->resolve(RoleReference::id($id));

                    $this->fail(sprintf(
                        'Identifier [%s] should be rejected.',
                        $id,
                    ));
                } catch (RoleNotFound $exception) {
                    $this->assertSame('ID', $exception->referenceType);
                    $this->assertStringNotContainsString(
                        $id,
                        $exception->getMessage(),
                    );
                }
            }
        });
    }

    public function test_string_keyed_models_preserve_uuid_and_ulid_identifiers(): void
    {
        $normalizer = new DefinitionIdentifierNormalizer;
        $stringKeyed = new StringKeyedDefinition;

        $this->assertSame(
            '018f4ac0-73a6-7c3e-9a34-0242ac120002',
            $normalizer->normalize(
                $stringKeyed,
                '018f4ac0-73a6-7c3e-9a34-0242ac120002',
            ),
        );
        $this->assertSame(
            '01J9Z3M7QX8B2N4P6R8T0V2W4Y',
            $normalizer->normalize(
                $stringKeyed,
                '01J9Z3M7QX8B2N4P6R8T0V2W4Y',
            ),
        );
        $this->assertNull(
            $normalizer->normalize(new Role, 'not-an-integer'),
        );
        $this->assertSame(7, $normalizer->normalize(new Role, '7'));
    }

    public function test_resolver_requires_an_existing_transaction(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('inside a database transaction');

        app(RoleDefinitionInputResolver::class)->resolve($this->role);
    }

    public function test_configured_custom_role_model_is_supported(): void
    {
        config()->set(
            'gatetrail.models.role',
            ResolverCustomRole::class,
        );

        $this->transaction(function (): void {
            $resolved = app(RoleDefinitionInputResolver::class)
                ->resolve('reviewer');

            $this->assertInstanceOf(ResolverCustomRole::class, $resolved);
            $this->assertSame(
                $this->role->getKey(),
                $resolved->getKey(),
            );
        });
    }

    private function transaction(callable $callback): void
    {
        $this->app['db']->connection('testing')->transaction($callback);
    }
}

final class ResolverCustomRole extends Role {}

final class StringKeyedDefinition extends Model
{
    protected $keyType = 'string';
}

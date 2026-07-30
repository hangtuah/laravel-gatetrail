<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use HangTuah\GateTrail\Exceptions\InvalidGuard;
use HangTuah\GateTrail\Exceptions\RoleNotFound;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\Mutations\DeactivatedRoleDefinitionInputResolver;
use HangTuah\GateTrail\Mutations\DefinitionIdentifierNormalizer;
use HangTuah\GateTrail\RoleReference;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\QueryException;
use LogicException;

final class DeactivatedRoleDefinitionInputResolverTest extends TestCase
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
        $this->role->delete();
    }

    public function test_string_input_resolves_by_name_in_the_package_default_guard(): void
    {
        config()->set('gatetrail.guards.default', 'api');
        $apiRole = Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'api',
            'description' => null,
        ]);
        $apiRole->delete();

        $this->transaction(function () use ($apiRole): void {
            $this->assertSame(
                $apiRole->getKey(),
                $this->resolver()->resolve('reviewer')->getKey(),
            );
        });
    }

    public function test_string_input_falls_back_to_the_laravel_default_guard(): void
    {
        config()->set('gatetrail.guards.default');
        config()->set('auth.defaults.guard', 'web');

        $this->transaction(function (): void {
            $this->assertSame(
                $this->role->getKey(),
                $this->resolver()->resolve('reviewer')->getKey(),
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
        $numericName->delete();

        $this->transaction(function () use ($numericName): void {
            $this->assertSame(
                $numericName->getKey(),
                $this->resolver()->resolve('123')->getKey(),
            );
            $this->assertSame(
                $this->role->getKey(),
                $this->resolver()
                    ->resolve((int) $this->role->getKey())
                    ->getKey(),
            );
        });
    }

    public function test_model_integer_and_reference_inputs_resolve_the_same_row(): void
    {
        $this->transaction(function (): void {
            $inputs = [
                $this->role,
                (int) $this->role->getKey(),
                RoleReference::id($this->role->getKey()),
                RoleReference::id((string) $this->role->getKey()),
            ];

            foreach ($inputs as $input) {
                $this->assertSame(
                    $this->role->getKey(),
                    $this->resolver()->resolve($input)->getKey(),
                );
            }
        });
    }

    public function test_model_input_is_reloaded_from_persisted_state(): void
    {
        $this->role->setAttribute('description', 'Stale in-memory value');

        $this->transaction(function (): void {
            $resolved = $this->resolver()->resolve($this->role);

            $this->assertNotSame($this->role, $resolved);
            $this->assertSame(
                'Reviews documents.',
                $resolved->getAttribute('description'),
            );
            $this->assertTrue($resolved->trashed());
        });
    }

    public function test_non_default_guard_role_resolves_through_explicit_inputs(): void
    {
        $apiRole = Role::query()->create([
            'name' => 'auditor',
            'guard_name' => 'api',
            'description' => null,
        ]);
        $apiRole->delete();

        $this->transaction(function () use ($apiRole): void {
            foreach ([
                $apiRole,
                (int) $apiRole->getKey(),
                RoleReference::id($apiRole->getKey()),
            ] as $input) {
                $this->assertSame(
                    $apiRole->getKey(),
                    $this->resolver()->resolve($input)->getKey(),
                );
            }
        });
    }

    public function test_explicit_inputs_ignore_an_invalid_package_default_guard(): void
    {
        config()->set('gatetrail.guards.default', ' ');

        $this->transaction(function (): void {
            foreach ([
                $this->role,
                (int) $this->role->getKey(),
                RoleReference::id($this->role->getKey()),
            ] as $input) {
                $this->assertSame(
                    $this->role->getKey(),
                    $this->resolver()->resolve($input)->getKey(),
                );
            }
        });
    }

    public function test_explicit_inputs_ignore_a_missing_laravel_default_guard(): void
    {
        config()->set('gatetrail.guards.default');
        config()->set('auth.defaults.guard', '');

        $this->transaction(function (): void {
            $this->assertSame(
                $this->role->getKey(),
                $this->resolver()
                    ->resolve(RoleReference::id($this->role->getKey()))
                    ->getKey(),
            );
        });
    }

    public function test_string_name_input_requires_a_valid_default_guard(): void
    {
        config()->set('gatetrail.guards.default', ' ');

        $this->transaction(function (): void {
            try {
                $this->resolver()->resolve('reviewer');

                $this->fail('An invalid default guard should be rejected.');
            } catch (InvalidGuard $exception) {
                $this->assertSame('role definition', $exception->contextType);
                $this->assertSame('package default', $exception->source);
            }
        });
    }

    public function test_missing_name_throws_role_not_found(): void
    {
        $this->transaction(function (): void {
            try {
                $this->resolver()->resolve('unknown-role');

                $this->fail('A missing name should be rejected.');
            } catch (RoleNotFound $exception) {
                $this->assertSame('web', $exception->guard);
                $this->assertSame('name', $exception->referenceType);
            }
        });
    }

    public function test_missing_explicit_reference_reports_no_guard(): void
    {
        config()->set('gatetrail.guards.default', ' ');

        $this->transaction(function (): void {
            foreach ([4242, RoleReference::id(4242)] as $input) {
                try {
                    $this->resolver()->resolve($input);

                    $this->fail('A missing reference should be rejected.');
                } catch (RoleNotFound $exception) {
                    $this->assertNull($exception->guard);
                    $this->assertSame('ID', $exception->referenceType);
                }
            }
        });
    }

    public function test_malformed_reference_is_rejected_before_any_binding(): void
    {
        config()->set('gatetrail.guards.default', ' ');

        $this->transaction(function (): void {
            foreach ([' 1', '1 ', '+1', '-1', '1.0', '1e3', 'abc'] as $id) {
                try {
                    $this->resolver()->resolve(RoleReference::id($id));

                    $this->fail(sprintf(
                        'Identifier [%s] should be rejected.',
                        $id,
                    ));
                } catch (RoleNotFound $exception) {
                    $this->assertSame('ID', $exception->referenceType);
                    $this->assertNull($exception->guard);
                    $this->assertStringNotContainsString(
                        $id,
                        $exception->getMessage(),
                    );
                }
            }
        });
    }

    public function test_unpersisted_model_is_rejected_safely(): void
    {
        $this->transaction(function (): void {
            try {
                $this->resolver()->resolve(new Role);

                $this->fail('An unpersisted model should be rejected.');
            } catch (RoleNotFound $exception) {
                $this->assertSame('model', $exception->referenceType);
                $this->assertNull($exception->guard);
            }
        });
    }

    public function test_active_name_is_rejected(): void
    {
        $active = Role::query()->create([
            'name' => 'auditor',
            'guard_name' => 'web',
            'description' => null,
        ]);

        $this->transaction(function () use ($active): void {
            try {
                $this->resolver()->resolve('auditor');

                $this->fail('An active role should be rejected.');
            } catch (RoleNotFound $exception) {
                $this->assertSame('web', $exception->guard);
                $this->assertSame('name', $exception->referenceType);
            }

            $this->assertNull($active->fresh()?->getAttribute('deleted_at'));
        });
    }

    public function test_active_explicit_inputs_are_rejected_with_persisted_guard(): void
    {
        $active = Role::query()->create([
            'name' => 'auditor',
            'guard_name' => 'api',
            'description' => null,
        ]);

        $this->transaction(function () use ($active): void {
            foreach ([
                $active,
                (int) $active->getKey(),
                RoleReference::id($active->getKey()),
            ] as $input) {
                try {
                    $this->resolver()->resolve($input);

                    $this->fail('An active role should be rejected.');
                } catch (RoleNotFound $exception) {
                    $this->assertSame('api', $exception->guard);
                }
            }
        });
    }

    public function test_resolver_requires_an_existing_transaction(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('inside a database transaction');

        $this->resolver()->resolve($this->role);
    }

    public function test_resolution_locks_the_matched_row_without_a_soft_delete_filter(): void
    {
        $connection = $this->app['db']->connection('testing');
        $statements = [];
        $connection->beforeExecuting(
            static function (string $query) use (&$statements): void {
                $statements[] = $query;
            },
        );
        // SQLite compiles `lockForUpdate()` to an empty clause, so the lock is
        // only observable through a grammar that emits one. The statement is
        // captured before SQLite rejects it.
        $connection->setQueryGrammar(new MySqlGrammar($connection));

        try {
            $connection->transaction(function (): void {
                $this->resolver()
                    ->resolve(RoleReference::id($this->role->getKey()));
            });

            $this->fail('SQLite cannot execute a MySQL lock clause.');
        } catch (QueryException) {
            // The compiled statement is the assertion target.
        }

        $selects = array_values(array_filter(
            $statements,
            static fn (string $query): bool => str_starts_with(
                $query,
                'select',
            ),
        ));

        $this->assertNotSame([], $selects);
        $this->assertStringContainsString('for update', $selects[0]);
        $this->assertStringNotContainsString('deleted_at', $selects[0]);
    }

    public function test_configured_custom_role_model_is_supported(): void
    {
        config()->set(
            'gatetrail.models.role',
            RestoreResolverCustomRole::class,
        );

        $this->transaction(function (): void {
            $resolved = $this->resolver()->resolve('reviewer');

            $this->assertInstanceOf(
                RestoreResolverCustomRole::class,
                $resolved,
            );
            $this->assertSame($this->role->getKey(), $resolved->getKey());
        });
    }

    public function test_string_keyed_identifiers_are_preserved_unchanged(): void
    {
        $normalizer = new DefinitionIdentifierNormalizer;
        $stringKeyed = new StringKeyedRestoreDefinition;

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

    private function resolver(): DeactivatedRoleDefinitionInputResolver
    {
        return app(DeactivatedRoleDefinitionInputResolver::class);
    }

    private function transaction(callable $callback): void
    {
        $this->app['db']->connection('testing')->transaction($callback);
    }
}

final class RestoreResolverCustomRole extends Role {}

final class StringKeyedRestoreDefinition extends Model
{
    protected $keyType = 'string';
}

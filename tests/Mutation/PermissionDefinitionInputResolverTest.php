<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use HangTuah\GateTrail\Exceptions\InvalidGuard;
use HangTuah\GateTrail\Exceptions\PermissionNotFound;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Mutations\DefinitionIdentifierNormalizer;
use HangTuah\GateTrail\Mutations\PermissionDefinitionInputResolver;
use HangTuah\GateTrail\PermissionReference;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\QueryException;
use LogicException;

final class PermissionDefinitionInputResolverTest extends TestCase
{
    private Permission $permission;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);
        $this->permission = Permission::query()->create([
            'name' => 'documents.approve',
            'guard_name' => 'web',
            'description' => 'Approve submitted documents',
        ]);
    }

    public function test_string_input_resolves_by_name_in_the_package_default_guard(): void
    {
        config()->set('gatetrail.guards.default', 'api');
        $apiPermission = Permission::query()->create([
            'name' => 'documents.approve',
            'guard_name' => 'api',
            'description' => null,
        ]);

        $this->transaction(function () use ($apiPermission): void {
            $this->assertSame(
                $apiPermission->getKey(),
                $this->resolver()->resolve('documents.approve')->getKey(),
            );
        });
    }

    public function test_string_input_falls_back_to_the_laravel_default_guard(): void
    {
        config()->set('gatetrail.guards.default');
        config()->set('auth.defaults.guard', 'web');

        $this->transaction(function (): void {
            $this->assertSame(
                $this->permission->getKey(),
                $this->resolver()->resolve('documents.approve')->getKey(),
            );
        });
    }

    public function test_numeric_looking_string_remains_a_name(): void
    {
        $numericName = Permission::query()->create([
            'name' => '123',
            'guard_name' => 'web',
            'description' => null,
        ]);

        $this->transaction(function () use ($numericName): void {
            $this->assertSame(
                $numericName->getKey(),
                $this->resolver()->resolve('123')->getKey(),
            );
            $this->assertSame(
                $this->permission->getKey(),
                $this->resolver()
                    ->resolve((int) $this->permission->getKey())
                    ->getKey(),
            );
        });
    }

    public function test_string_name_input_requires_a_valid_default_guard(): void
    {
        config()->set('gatetrail.guards.default', ' ');

        $this->transaction(function (): void {
            try {
                $this->resolver()->resolve('documents.approve');

                $this->fail('An invalid default guard should be rejected.');
            } catch (InvalidGuard $exception) {
                $this->assertSame(
                    'permission definition',
                    $exception->contextType,
                );
                $this->assertSame('package default', $exception->source);
            }
        });
    }

    public function test_model_integer_and_reference_inputs_resolve_the_same_row(): void
    {
        $this->transaction(function (): void {
            $inputs = [
                $this->permission,
                (int) $this->permission->getKey(),
                PermissionReference::id($this->permission->getKey()),
                PermissionReference::id(
                    (string) $this->permission->getKey(),
                ),
            ];

            foreach ($inputs as $input) {
                $this->assertSame(
                    $this->permission->getKey(),
                    $this->resolver()->resolve($input)->getKey(),
                );
            }
        });
    }

    public function test_model_input_is_reloaded_from_persisted_state(): void
    {
        $this->permission->setAttribute(
            'description',
            'Stale in-memory value',
        );

        $this->transaction(function (): void {
            $resolved = $this->resolver()->resolve($this->permission);

            $this->assertNotSame($this->permission, $resolved);
            $this->assertSame(
                'Approve submitted documents',
                $resolved->getAttribute('description'),
            );
        });
    }

    public function test_non_default_guard_permission_resolves_through_explicit_inputs(): void
    {
        $apiPermission = Permission::query()->create([
            'name' => 'documents.reject',
            'guard_name' => 'api',
            'description' => null,
        ]);

        $this->transaction(function () use ($apiPermission): void {
            foreach ([
                $apiPermission,
                (int) $apiPermission->getKey(),
                PermissionReference::id($apiPermission->getKey()),
            ] as $input) {
                $this->assertSame(
                    $apiPermission->getKey(),
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
                $this->permission,
                (int) $this->permission->getKey(),
                PermissionReference::id($this->permission->getKey()),
            ] as $input) {
                $this->assertSame(
                    $this->permission->getKey(),
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
                $this->permission->getKey(),
                $this->resolver()
                    ->resolve(
                        PermissionReference::id(
                            $this->permission->getKey(),
                        ),
                    )
                    ->getKey(),
            );
        });
    }

    public function test_missing_name_throws_permission_not_found(): void
    {
        $this->transaction(function (): void {
            try {
                $this->resolver()->resolve('documents.unknown');

                $this->fail('A missing name should be rejected.');
            } catch (PermissionNotFound $exception) {
                $this->assertSame('web', $exception->guard);
                $this->assertSame('name', $exception->referenceType);
            }
        });
    }

    public function test_missing_explicit_reference_reports_no_guard(): void
    {
        config()->set('gatetrail.guards.default', ' ');

        $this->transaction(function (): void {
            foreach ([4242, PermissionReference::id(4242)] as $input) {
                try {
                    $this->resolver()->resolve($input);

                    $this->fail('A missing reference should be rejected.');
                } catch (PermissionNotFound $exception) {
                    $this->assertNull($exception->guard);
                    $this->assertSame('ID', $exception->referenceType);
                    $this->assertStringNotContainsString(
                        'guard',
                        $exception->getMessage(),
                    );
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
                    $this->resolver()->resolve(
                        PermissionReference::id($id),
                    );

                    $this->fail(sprintf(
                        'Identifier [%s] should be rejected.',
                        $id,
                    ));
                } catch (PermissionNotFound $exception) {
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
                $this->resolver()->resolve(new Permission);

                $this->fail('An unpersisted model should be rejected.');
            } catch (PermissionNotFound $exception) {
                $this->assertSame('model', $exception->referenceType);
                $this->assertNull($exception->guard);
            }
        });
    }

    public function test_deactivated_permission_is_rejected_for_every_input(): void
    {
        $this->permission->delete();

        $this->transaction(function (): void {
            foreach ([
                'documents.approve',
                (int) $this->permission->getKey(),
                PermissionReference::id($this->permission->getKey()),
            ] as $input) {
                try {
                    $this->resolver()->resolve($input);

                    $this->fail(
                        'A deactivated permission should be rejected.',
                    );
                } catch (PermissionNotFound $exception) {
                    $this->assertSame('web', $exception->guard);
                }
            }
        });
    }

    public function test_deactivated_explicit_input_reports_the_persisted_guard(): void
    {
        $apiPermission = Permission::query()->create([
            'name' => 'documents.reject',
            'guard_name' => 'api',
            'description' => null,
        ]);
        $apiPermission->delete();
        config()->set('gatetrail.guards.default', ' ');

        $this->transaction(function () use ($apiPermission): void {
            try {
                $this->resolver()->resolve(
                    PermissionReference::id($apiPermission->getKey()),
                );

                $this->fail('A deactivated permission should be rejected.');
            } catch (PermissionNotFound $exception) {
                $this->assertSame('api', $exception->guard);
                $this->assertSame('ID', $exception->referenceType);
            }
        });
    }

    public function test_resolver_requires_an_existing_transaction(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('inside a database transaction');

        $this->resolver()->resolve($this->permission);
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
                $this->resolver()->resolve(
                    PermissionReference::id($this->permission->getKey()),
                );
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

    public function test_configured_custom_permission_model_is_supported(): void
    {
        config()->set(
            'gatetrail.models.permission',
            DefinitionResolverCustomPermission::class,
        );

        $this->transaction(function (): void {
            $resolved = $this->resolver()->resolve('documents.approve');

            $this->assertInstanceOf(
                DefinitionResolverCustomPermission::class,
                $resolved,
            );
            $this->assertSame($this->permission->getKey(), $resolved->getKey());
        });
    }

    public function test_string_keyed_identifiers_are_preserved_unchanged(): void
    {
        $normalizer = new DefinitionIdentifierNormalizer;
        $stringKeyed = new StringKeyedPermissionDefinition;

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
            $normalizer->normalize(new Permission, 'not-an-integer'),
        );
        $this->assertSame(7, $normalizer->normalize(new Permission, '7'));
    }

    private function resolver(): PermissionDefinitionInputResolver
    {
        return app(PermissionDefinitionInputResolver::class);
    }

    private function transaction(callable $callback): void
    {
        $this->app['db']->connection('testing')->transaction($callback);
    }
}

final class DefinitionResolverCustomPermission extends Permission {}

final class StringKeyedPermissionDefinition extends Model
{
    protected $keyType = 'string';
}

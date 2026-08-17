<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Access;

use HangTuah\GateTrail\Access\RequestedDefinition;
use HangTuah\GateTrail\Access\RequestedDefinitionNormalizer;
use HangTuah\GateTrail\Contracts\PermissionContract;
use HangTuah\GateTrail\Contracts\RoleContract;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\PermissionReference;
use HangTuah\GateTrail\RoleReference;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The one place a caller's requested role or permission is interpreted.
 *
 * Both resolvers share this component precisely so the rules cannot drift
 * apart, and the sharpest of those rules is that **a bare string is always a
 * name**. `'007'` and `PermissionReference::id('007')` are different questions,
 * and everything downstream depends on that distinction being made exactly
 * once, here.
 *
 * Tested at this seam rather than only end to end, because a string-keyed
 * definition model cannot be exercised honestly against an integer-keyed
 * SQLite column, and because `null` — "impossible, deny before building a
 * snapshot" — is a return value rather than an observable query.
 */
final class RequestedDefinitionNormalizerTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function definitions(): array
    {
        return [
            'role' => [Role::class],
            'permission' => [Permission::class],
        ];
    }

    /**
     * Names that look like identifiers. Every one is a name.
     *
     * @return array<string, array{string}>
     */
    public static function names(): array
    {
        return [
            'ordinary' => ['reviewer'],
            'dotted' => ['documents.approve'],
            'numeric' => ['42'],
            'zero padded' => ['007'],
            'zero' => ['0'],
            'negative looking' => ['-1'],
            'signed looking' => ['+1'],
            'decimal looking' => ['1.0'],
            'exponent looking' => ['1e3'],
            'hexadecimal looking' => ['0x1f'],
            'above php int max' => ['99999999999999999999'],
            'surrounded by spaces' => [' reviewer '],
            'uppercase' => ['REVIEWER'],
            'unicode' => ['Émile'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unusableNames(): array
    {
        return [
            'empty' => [''],
            'single space' => [' '],
            'spaces' => ['   '],
            'tab' => ["\t"],
            'newline' => ["\n"],
            'mixed whitespace' => [" \t\n "],
        ];
    }

    /**
     * @return array<string, array{int|string, int}>
     */
    public static function acceptedIdentifiers(): array
    {
        return [
            'native integer' => [7, 7],
            'decimal string' => ['7', 7],
            'zero padded string' => ['007', 7],
            'zero' => ['0', 0],
            'zero padded zero' => ['000', 0],
            'php int max' => [PHP_INT_MAX, PHP_INT_MAX],
            'php int max as string' => ['9223372036854775807', PHP_INT_MAX],
        ];
    }

    /**
     * @return array<string, array{int|string}>
     */
    public static function rejectedIdentifiers(): array
    {
        return [
            'above php int max' => ['9223372036854775808'],
            'far above php int max' => ['99999999999999999999'],
            'negative string' => ['-1'],
            'signed string' => ['+1'],
            'leading space' => [' 1'],
            'trailing space' => ['1 '],
            'decimal notation' => ['1.0'],
            'exponent notation' => ['1e3'],
            'hexadecimal notation' => ['0x1f'],
            'alphabetic' => ['abc'],
            'uuid' => ['0193c4a1-6f2e-7a3b-9c4d-5e6f70819a2b'],
        ];
    }

    // ------------------------------------------------------------ Bare strings

    #[DataProvider('names')]
    public function test_every_bare_string_is_a_name(string $input): void
    {
        $requested = $this->normalize($input, new Role);

        $this->assertInstanceOf(RequestedDefinition::class, $requested);
        $this->assertSame($input, $requested->name);
        $this->assertNull(
            $requested->identifier,
            'A bare string is never an identifier, whatever it looks like.',
        );
    }

    /**
     * The name is not touched. `' reviewer '` stays exactly that and simply
     * fails to match, rather than quietly becoming `'reviewer'`.
     */
    public function test_a_name_is_passed_through_verbatim(): void
    {
        foreach ([' reviewer ', 'Reviewer', "reviewer\n"] as $input) {
            $this->assertSame($input, $this->normalize($input, new Role)?->name);
        }
    }

    #[DataProvider('unusableNames')]
    public function test_an_empty_or_whitespace_only_name_is_impossible(
        string $input,
    ): void {
        $this->assertNull(
            $this->normalize($input, new Role),
            'Definition names are trimmed and non-empty at creation.',
        );
    }

    // ------------------------------------------------------------- Identifiers

    #[DataProvider('acceptedIdentifiers')]
    public function test_an_integer_keyed_identifier_is_canonicalized(
        int|string $input,
        int $expected,
    ): void {
        $requested = $this->normalize(PermissionReference::id($input), new Permission);

        $this->assertInstanceOf(RequestedDefinition::class, $requested);
        $this->assertSame($expected, $requested->identifier);
        $this->assertNull($requested->name);
    }

    #[DataProvider('rejectedIdentifiers')]
    public function test_an_unusable_identifier_is_impossible(
        int|string $input,
    ): void {
        $this->assertNull($this->normalize(RoleReference::id($input), new Role));
        $this->assertNull(
            $this->normalize(PermissionReference::id($input), new Permission),
        );
    }

    /**
     * `(int) '99999999999999999999'` saturates at `PHP_INT_MAX`, which is a
     * real definition's identifier. Rejecting rather than casting is what stops
     * an overflowing request from asking about the wrong row.
     */
    public function test_an_overflowing_identifier_does_not_saturate(): void
    {
        $this->assertSame(PHP_INT_MAX, (int) '99999999999999999999');
        $this->assertNull(
            $this->normalize(RoleReference::id('99999999999999999999'), new Role),
        );
    }

    public function test_a_native_integer_is_always_an_identifier(): void
    {
        $requested = $this->normalize(7, new Role);

        $this->assertSame(7, $requested?->identifier);
        $this->assertNull($requested?->name);
    }

    /**
     * A string-keyed definition owns its identifier exactly, so `'007'` is a
     * different key from `'7'`. Canonicalizing it would point at a row that
     * does not exist.
     */
    public function test_a_string_keyed_definition_preserves_its_identifier(): void
    {
        $definition = new StringKeyedRoleDefinition;

        $this->assertSame(
            '007',
            $this->normalize(RoleReference::id('007'), $definition)?->identifier,
        );
        $this->assertSame(
            '7',
            $this->normalize(RoleReference::id('7'), $definition)?->identifier,
        );
        $this->assertSame(
            'anything-at-all',
            $this->normalize(
                RoleReference::id('anything-at-all'),
                $definition,
            )?->identifier,
        );
    }

    // ------------------------------------------------------- Definition models

    public function test_a_persisted_configured_model_is_read_by_key_only(): void
    {
        $this->migrate();

        $role = Role::query()->create(['name' => 'reviewer', 'guard_name' => 'web']);

        // Attributes a caller could have edited in memory must not steer the
        // decision, so only the persisted key is read.
        $role->name = 'something-else';
        $role->guard_name = 'api';

        $requested = $this->normalize($role, new Role);

        $this->assertSame($role->getKey(), $requested?->identifier);
        $this->assertNull($requested?->name);
    }

    public function test_a_subclass_of_the_configured_model_is_accepted(): void
    {
        $this->migrate();

        $role = SubclassedRoleDefinition::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'web',
        ]);

        $this->assertSame(
            $role->getKey(),
            $this->normalize($role, new Role)?->identifier,
        );
    }

    public function test_an_unsaved_configured_model_is_impossible(): void
    {
        $this->assertNull($this->normalize(new Role, new Role));
        $this->assertNull($this->normalize(new Permission, new Permission));
    }

    public function test_a_model_without_a_usable_key_is_impossible(): void
    {
        $role = new Role;
        $role->exists = true;

        $this->assertNull($this->normalize($role, new Role), 'A null key.');

        // An auto-incrementing model casts its key to `int`, so an unusable
        // scalar is only observable on a model that does not. That is the
        // shape a replacement model with a custom key can genuinely have.
        foreach ([1.5, true, []] as $unusable) {
            $unkeyed = new StringKeyedRoleDefinition;
            $unkeyed->exists = true;
            $unkeyed->setAttribute('id', $unusable);

            $this->assertNull(
                $this->normalize($unkeyed, new StringKeyedRoleDefinition),
                sprintf('A [%s] key.', get_debug_type($unusable)),
            );
        }
    }

    /**
     * The escalation path this rule exists to close. `PermissionContract` is an
     * empty marker, so any application class can implement it — and key `1`
     * exists in nearly every table, so a colliding foreign key would otherwise
     * authorize the caller.
     */
    public function test_an_unrelated_contract_implementation_is_impossible(): void
    {
        $this->assertNull(
            $this->normalize(new UnrelatedPermission, new Permission),
        );
        $this->assertNull($this->normalize(new UnrelatedRole, new Role));
        $this->assertNull(
            $this->normalize(new UnrelatedNonEloquentPermission, new Permission),
        );
    }

    public function test_an_unrelated_implementation_denies_rather_than_throwing(): void
    {
        $unrelated = new UnrelatedPermission;
        $unrelated->exists = true;
        $unrelated->setAttribute('id', 1);

        // Throwing here would also let a caller probe which classes are
        // configured, which non-disclosure forbids.
        $this->assertNull($this->normalize($unrelated, new Permission));
    }

    /**
     * A permission is not a role, even though both are configured GateTrail
     * models.
     */
    public function test_the_wrong_definition_kind_is_impossible(): void
    {
        $this->migrate();

        $role = Role::query()->create(['name' => 'reviewer', 'guard_name' => 'web']);

        $this->assertNull($this->normalize($role, new Permission));
    }

    // ---------------------------------------------------------------- Structure

    public function test_it_executes_no_statement(): void
    {
        $this->migrate();

        $role = Role::query()->create(['name' => 'reviewer', 'guard_name' => 'web']);
        $statements = 0;

        $this->app['db']->listen(function () use (&$statements): void {
            $statements++;
        });

        $this->normalize('reviewer', new Role);
        $this->normalize(7, new Role);
        $this->normalize(RoleReference::id('007'), new Role);
        $this->normalize($role, new Role);
        $this->normalize('', new Role);

        $this->assertSame(
            0,
            $statements,
            'Normalization must never look a definition up.',
        );
    }

    public function test_the_request_carries_exactly_one_of_name_or_identifier(): void
    {
        foreach ([
            $this->normalize('reviewer', new Role),
            $this->normalize(7, new Role),
        ] as $requested) {
            $this->assertInstanceOf(RequestedDefinition::class, $requested);
            $this->assertSame(
                1,
                (int) ($requested->name !== null)
                    + (int) ($requested->identifier !== null),
            );
        }
    }

    // ------------------------------------------------------------------ Helpers

    private function normalize(
        PermissionContract|RoleContract|PermissionReference|RoleReference|string|int $input,
        Model $definition,
    ): ?RequestedDefinition {
        return $this->app->make(RequestedDefinitionNormalizer::class)
            ->normalize($input, $definition);
    }

    private function migrate(): void
    {
        $this->artisan('migrate', ['--database' => 'testing'])->assertExitCode(0);
    }
}

final class StringKeyedRoleDefinition extends Role
{
    public $incrementing = false;

    protected $keyType = 'string';
}

final class SubclassedRoleDefinition extends Role {}

/**
 * An application model that merely implements the marker interface. Its key
 * space is unrelated to GateTrail's and collides with it constantly.
 */
final class UnrelatedPermission extends Model implements PermissionContract
{
    protected $table = 'unrelated';
}

final class UnrelatedRole extends Model implements RoleContract
{
    protected $table = 'unrelated';
}

final class UnrelatedNonEloquentPermission implements PermissionContract
{
    public int $id = 1;
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Access;

use HangTuah\GateTrail\Access\EffectiveAccessQueryBuilder;
use HangTuah\GateTrail\Access\EffectiveAccessRowMapper;
use HangTuah\GateTrail\Access\EffectivePermission;
use HangTuah\GateTrail\Access\EffectiveRole;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\PermissionAssignment;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\Models\RoleAssignment;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The boundary where a persisted row becomes an authorization value.
 *
 * This is the smallest seam at which a **driver-shaped scalar** can be
 * exercised honestly. Oracle returns a numeric identifier as a string, and no
 * SQLite fixture can reproduce that; building the row directly is the only way
 * to assert the behaviour without weakening what production code sees. Rows
 * here are therefore raw attribute sets, exactly as Eloquent hydrates them
 * from the aliased select.
 *
 * Two outcomes are deliberately different and are asserted as such: a
 * cross-guard row is **excluded silently**, and a malformed row **raises**.
 * Collapsing the second into the first would hide a schema defect behind what
 * looks like ordinary policy.
 */
final class EffectiveAccessRowMapperTest extends TestCase
{
    private const GUARD = 'web';

    /**
     * @return array<string, array{string}>
     */
    public static function kinds(): array
    {
        return [
            'roles' => ['roles'],
            'permissions' => ['permissions'],
        ];
    }

    /**
     * Values a driver may hand back for an integer-keyed definition, and the
     * canonical native integer each must become.
     *
     * @return array<string, array{int|string, int}>
     */
    public static function normalizedIdentifiers(): array
    {
        return [
            'native integer' => [7, 7],
            'driver-shaped numeric string' => ['7', 7],
            'zero-padded string' => ['007', 7],
            'zero' => ['0', 0],
            'large in-range string' => ['9223372036854775807', PHP_INT_MAX],
        ];
    }

    /**
     * @return array<string, array{int|string}>
     */
    public static function unnormalizableIdentifiers(): array
    {
        return [
            'non-numeric' => ['reviewer'],
            'negative' => ['-1'],
            'signed' => ['+1'],
            'decimal' => ['1.0'],
            'exponent' => ['1e3'],
            'hexadecimal' => ['0x1f'],
            'whitespace' => [' 7'],
            'uuid' => ['0193c4a1-6f2e-7a3b-9c4d-5e6f70819a2b'],
            'above php int max' => ['9223372036854775808'],
            'empty' => [''],
        ];
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unusableScalars(): array
    {
        return [
            'null' => [null],
            'float' => [1.5],
            'boolean' => [true],
            'array' => [[]],
        ];
    }

    // ------------------------------------------------------------ Happy path

    public function test_it_maps_role_rows_through_the_three_aliases(): void
    {
        $roles = $this->mapper()->roles(
            [
                $this->row(RoleAssignment::class, 7, 'reviewer'),
                $this->row(RoleAssignment::class, 8, 'editor'),
            ],
            self::GUARD,
        );

        $this->assertCount(2, $roles);
        $this->assertContainsOnlyInstancesOf(EffectiveRole::class, $roles);

        $this->assertSame(7, $roles[0]->id);
        $this->assertSame('reviewer', $roles[0]->name);
        $this->assertSame('web', $roles[0]->guardName);
        $this->assertSame(8, $roles[1]->id);
        $this->assertSame('editor', $roles[1]->name);
    }

    public function test_it_maps_permission_rows_through_the_three_aliases(): void
    {
        $permissions = $this->mapper()->permissions(
            [$this->row(PermissionAssignment::class, 3, 'documents.approve')],
            self::GUARD,
        );

        $this->assertCount(1, $permissions);
        $this->assertContainsOnlyInstancesOf(
            EffectivePermission::class,
            $permissions,
        );
        $this->assertSame(3, $permissions[0]->id);
        $this->assertSame('documents.approve', $permissions[0]->name);
        $this->assertSame('web', $permissions[0]->guardName);
    }

    #[DataProvider('kinds')]
    public function test_an_empty_row_set_maps_to_an_empty_list(string $kind): void
    {
        $this->assertSame([], $this->mapper()->{$kind}([], self::GUARD));
    }

    /**
     * The root assignment model carries its own columns, and none of them may
     * influence what the mapper produces.
     */
    public function test_it_ignores_every_attribute_but_the_three_aliases(): void
    {
        $row = new RoleAssignment;
        $row->setRawAttributes([
            'id' => 999,
            'name' => 'not-the-definition-name',
            'guard_name' => 'api',
            'subject_id' => 42,
            'subject_type' => 'App\\Models\\User',
            'role_id' => 123,
            EffectiveAccessQueryBuilder::ID_ALIAS => 7,
            EffectiveAccessQueryBuilder::NAME_ALIAS => 'reviewer',
            EffectiveAccessQueryBuilder::GUARD_ALIAS => 'web',
        ], true);

        $roles = $this->mapper()->roles([$row], self::GUARD);

        $this->assertSame(7, $roles[0]->id);
        $this->assertSame('reviewer', $roles[0]->name);
        $this->assertSame('web', $roles[0]->guardName);
    }

    public function test_mapping_executes_no_statement(): void
    {
        $statements = 0;

        $this->app['db']->listen(function () use (&$statements): void {
            $statements++;
        });

        $this->mapper()->roles(
            [$this->row(RoleAssignment::class, 7, 'reviewer')],
            self::GUARD,
        );
        $this->mapper()->permissions(
            [$this->row(PermissionAssignment::class, 3, 'documents.approve')],
            self::GUARD,
        );

        $this->assertSame(
            0,
            $statements,
            'Mapping must not load a relation or look a definition up.',
        );
    }

    // -------------------------------------------------- Identifier portability

    /**
     * The reason this matters: the snapshot keys uniqueness on the identifier's
     * PHP type, so `7` arriving as `'7'` from one driver and as `7` from
     * another would split one definition into two entries.
     */
    #[DataProvider('normalizedIdentifiers')]
    public function test_an_integer_keyed_definition_yields_a_native_integer(
        int|string $observed,
        int $expected,
    ): void {
        $roles = $this->mapper()->roles(
            [$this->row(RoleAssignment::class, $observed, 'reviewer')],
            self::GUARD,
        );

        $this->assertSame($expected, $roles[0]->id);
    }

    #[DataProvider('normalizedIdentifiers')]
    public function test_an_integer_keyed_permission_yields_a_native_integer(
        int|string $observed,
        int $expected,
    ): void {
        $permissions = $this->mapper()->permissions(
            [$this->row(PermissionAssignment::class, $observed, 'documents.approve')],
            self::GUARD,
        );

        $this->assertSame($expected, $permissions[0]->id);
    }

    /**
     * A string-keyed definition owns its identifier exactly. `'007'` is a
     * different key from `'7'` when the column is a string, so canonicalizing
     * it here would point at a definition that does not exist.
     */
    public function test_a_string_keyed_definition_preserves_its_identifier(): void
    {
        config()->set('gatetrail.models.role', StringKeyedRole::class);
        config()->set('gatetrail.models.permission', StringKeyedPermission::class);

        $roles = $this->mapper()->roles(
            [$this->row(RoleAssignment::class, '007', 'reviewer')],
            self::GUARD,
        );
        $permissions = $this->mapper()->permissions(
            [$this->row(PermissionAssignment::class, '007', 'documents.approve')],
            self::GUARD,
        );

        $this->assertSame('007', $roles[0]->id);
        $this->assertSame('007', $permissions[0]->id);
    }

    /**
     * `(int) '9223372036854775808'` saturates to `PHP_INT_MAX`, which is a real
     * definition's identifier. Casting would silently point at the wrong row.
     */
    #[DataProvider('unnormalizableIdentifiers')]
    public function test_an_unnormalizable_identifier_raises_rather_than_saturating(
        int|string $observed,
    ): void {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('does not match the configured key type');

        $this->mapper()->roles(
            [$this->row(RoleAssignment::class, $observed, 'reviewer')],
            self::GUARD,
        );
    }

    #[DataProvider('unusableScalars')]
    public function test_an_identifier_that_is_not_int_or_string_raises(
        mixed $observed,
    ): void {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('not an integer or string');

        $this->mapper()->roles(
            [$this->rawRow([
                EffectiveAccessQueryBuilder::ID_ALIAS => $observed,
                EffectiveAccessQueryBuilder::NAME_ALIAS => 'reviewer',
                EffectiveAccessQueryBuilder::GUARD_ALIAS => 'web',
            ])],
            self::GUARD,
        );
    }

    // ------------------------------------------------------- Partial rows

    #[DataProvider('kinds')]
    public function test_a_missing_identifier_raises(string $kind): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('identifier is missing');

        $this->mapper()->{$kind}(
            [$this->rawRow([
                EffectiveAccessQueryBuilder::NAME_ALIAS => 'reviewer',
                EffectiveAccessQueryBuilder::GUARD_ALIAS => 'web',
            ])],
            self::GUARD,
        );
    }

    #[DataProvider('kinds')]
    public function test_a_missing_name_raises(string $kind): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('name is missing or not a string');

        $this->mapper()->{$kind}(
            [$this->rawRow([
                EffectiveAccessQueryBuilder::ID_ALIAS => 7,
                EffectiveAccessQueryBuilder::GUARD_ALIAS => 'web',
            ])],
            self::GUARD,
        );
    }

    #[DataProvider('unusableScalars')]
    public function test_a_name_that_is_not_a_string_raises(mixed $observed): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('name is missing or not a string');

        $this->mapper()->roles(
            [$this->rawRow([
                EffectiveAccessQueryBuilder::ID_ALIAS => 7,
                EffectiveAccessQueryBuilder::NAME_ALIAS => $observed,
                EffectiveAccessQueryBuilder::GUARD_ALIAS => 'web',
            ])],
            self::GUARD,
        );
    }

    #[DataProvider('kinds')]
    public function test_a_missing_guard_raises(string $kind): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('guard is missing or not a string');

        $this->mapper()->{$kind}(
            [$this->rawRow([
                EffectiveAccessQueryBuilder::ID_ALIAS => 7,
                EffectiveAccessQueryBuilder::NAME_ALIAS => 'reviewer',
            ])],
            self::GUARD,
        );
    }

    #[DataProvider('unusableScalars')]
    public function test_a_guard_that_is_not_a_string_raises(mixed $observed): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('guard is missing or not a string');

        $this->mapper()->roles(
            [$this->rawRow([
                EffectiveAccessQueryBuilder::ID_ALIAS => 7,
                EffectiveAccessQueryBuilder::NAME_ALIAS => 'reviewer',
                EffectiveAccessQueryBuilder::GUARD_ALIAS => $observed,
            ])],
            self::GUARD,
        );
    }

    /**
     * No partial value is ever constructed: the first malformed row aborts the
     * whole mapping rather than contributing a half-built entry.
     */
    public function test_a_malformed_row_does_not_yield_a_partial_value(): void
    {
        try {
            $this->mapper()->roles(
                [
                    $this->row(RoleAssignment::class, 7, 'reviewer'),
                    $this->rawRow([
                        EffectiveAccessQueryBuilder::ID_ALIAS => 8,
                        EffectiveAccessQueryBuilder::GUARD_ALIAS => 'web',
                    ]),
                ],
                self::GUARD,
            );

            $this->fail('Expected LogicException.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('name is missing', $exception->getMessage());
        }
    }

    // ------------------------------------------------- Guard post-verification

    /**
     * The SQL predicate is the primary filter. This is the backstop for a
     * case-insensitive collation widening it, which is why the comparison must
     * be byte-for-byte.
     */
    #[DataProvider('kinds')]
    public function test_a_row_from_another_guard_is_excluded_silently(
        string $kind,
    ): void {
        $mapped = $this->mapper()->{$kind}(
            [
                $this->rawRow([
                    EffectiveAccessQueryBuilder::ID_ALIAS => 7,
                    EffectiveAccessQueryBuilder::NAME_ALIAS => 'reviewer',
                    EffectiveAccessQueryBuilder::GUARD_ALIAS => 'api',
                ]),
            ],
            self::GUARD,
        );

        $this->assertSame([], $mapped);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonIdenticalGuards(): array
    {
        return [
            'different guard' => ['api'],
            'uppercase' => ['WEB'],
            'capitalized' => ['Web'],
            'trailing space' => ['web '],
            'leading space' => [' web'],
            'empty' => [''],
        ];
    }

    #[DataProvider('nonIdenticalGuards')]
    public function test_guard_comparison_is_byte_for_byte(string $returned): void
    {
        $this->assertSame([], $this->mapper()->roles(
            [
                $this->rawRow([
                    EffectiveAccessQueryBuilder::ID_ALIAS => 7,
                    EffectiveAccessQueryBuilder::NAME_ALIAS => 'reviewer',
                    EffectiveAccessQueryBuilder::GUARD_ALIAS => $returned,
                ]),
            ],
            self::GUARD,
        ));
    }

    public function test_matching_rows_survive_alongside_excluded_ones(): void
    {
        $roles = $this->mapper()->roles(
            [
                $this->rawRow([
                    EffectiveAccessQueryBuilder::ID_ALIAS => 7,
                    EffectiveAccessQueryBuilder::NAME_ALIAS => 'reviewer',
                    EffectiveAccessQueryBuilder::GUARD_ALIAS => 'API',
                ]),
                $this->row(RoleAssignment::class, 8, 'editor'),
            ],
            self::GUARD,
        );

        $this->assertCount(1, $roles);
        $this->assertSame(8, $roles[0]->id);
    }

    /**
     * A row that is not ours is discarded unexamined. Raising on the fields of
     * a row that is being dropped anyway would turn a widened database
     * comparison into a failed authorization call.
     */
    public function test_a_cross_guard_row_is_dropped_before_its_other_fields_matter(): void
    {
        $roles = $this->mapper()->roles(
            [
                $this->rawRow([
                    EffectiveAccessQueryBuilder::ID_ALIAS => 'not-an-integer',
                    EffectiveAccessQueryBuilder::GUARD_ALIAS => 'api',
                ]),
            ],
            self::GUARD,
        );

        $this->assertSame([], $roles);
    }

    // ------------------------------------------------------ Safe failures

    /**
     * An invariant message is a diagnostic, not a disclosure channel.
     */
    public function test_an_invariant_message_discloses_no_row_content(): void
    {
        $secrets = [
            '4242',
            'secret.permission',
            'confidential-guard',
        ];

        try {
            $this->mapper()->roles(
                [$this->rawRow([
                    EffectiveAccessQueryBuilder::ID_ALIAS => 'not-an-integer',
                    EffectiveAccessQueryBuilder::NAME_ALIAS => 'secret.permission',
                    EffectiveAccessQueryBuilder::GUARD_ALIAS => 'web',
                ])],
                self::GUARD,
            );

            $this->fail('Expected LogicException.');
        } catch (LogicException $exception) {
            foreach ([
                'not-an-integer',
                'secret.permission',
                'select',
                'acl_role',
                'gatetrail_id',
            ] as $forbidden) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $forbidden,
                    $exception->getMessage(),
                );
                $this->assertStringNotContainsStringIgnoringCase(
                    $forbidden,
                    (string) $exception,
                );
            }

            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString(
                    $secret,
                    print_r(get_object_vars($exception), true),
                );
            }

            $this->assertNull($exception->getPrevious());
        }
    }

    // ------------------------------------------------------------ Helpers

    private function mapper(): EffectiveAccessRowMapper
    {
        return $this->app->make(EffectiveAccessRowMapper::class);
    }

    /**
     * @param  class-string<Model>  $rootModel
     */
    private function row(
        string $rootModel,
        int|string $id,
        string $name,
        string $guard = self::GUARD,
    ): Model {
        $row = new $rootModel;
        $row->setRawAttributes([
            EffectiveAccessQueryBuilder::ID_ALIAS => $id,
            EffectiveAccessQueryBuilder::NAME_ALIAS => $name,
            EffectiveAccessQueryBuilder::GUARD_ALIAS => $guard,
        ], true);

        return $row;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function rawRow(array $attributes): Model
    {
        $row = new RoleAssignment;
        $row->setRawAttributes($attributes, true);

        return $row;
    }
}

/**
 * A definition whose key is a string, so `'007'` and `'7'` are different rows.
 */
final class StringKeyedRole extends Role
{
    public $incrementing = false;

    protected $keyType = 'string';
}

final class StringKeyedPermission extends Permission
{
    public $incrementing = false;

    protected $keyType = 'string';
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Access;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Generator;
use HangTuah\GateTrail\Access\EffectiveAccessSnapshot;
use HangTuah\GateTrail\Access\EffectivePermission;
use HangTuah\GateTrail\Access\EffectiveRole;
use HangTuah\GateTrail\Subjects\SubjectIdentity;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionObject;

/**
 * Freezes the internal evaluated-access state.
 *
 * The snapshot is the single state both resolvers will project, so every rule
 * that keeps the two projections from disagreeing lives here: one canonical
 * order, one uniqueness key, one supplied instant, and no retained
 * implementation state that could execute a query or be mutated after the
 * fact. It is `@internal`, so its surface is asserted to stay small rather
 * than to stay compatible.
 *
 * Everything below is built from in-memory fixtures. Nothing in this file
 * needs the database, which is itself asserted.
 */
final class EffectiveAccessSnapshotTest extends TestCase
{
    /**
     * Byte order, case-insensitive order and locale order all disagree about
     * this list, so an assertion against it cannot pass by accident.
     *
     * `_` (0x5F) sorts between the ASCII cases, and `É` (0xC3 0x89) sorts after
     * every ASCII byte — neither is true under `strcasecmp()` or a UTF-8
     * locale collation.
     *
     * @return list<string>
     */
    public static function unorderedNames(): array
    {
        return ['zulu', 'Apple', 'Émile', '_underscore', 'apple', 'Zebra'];
    }

    /**
     * @return list<string>
     */
    public static function byteOrderedNames(): array
    {
        return ['Apple', 'Zebra', '_underscore', 'apple', 'zulu', 'Émile'];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function collectionProjections(): array
    {
        return [
            'roles' => ['roles'],
            'permissions' => ['permissions'],
        ];
    }

    // ---------------------------------------------------------------- 1. Structure

    public function test_it_lives_in_the_accepted_namespace(): void
    {
        $this->assertTrue(class_exists(EffectiveAccessSnapshot::class));
        $this->assertSame(
            'HangTuah\\GateTrail\\Access',
            (new ReflectionClass(EffectiveAccessSnapshot::class))->getNamespaceName(),
        );
    }

    public function test_it_is_final_and_readonly(): void
    {
        $reflection = new ReflectionClass(EffectiveAccessSnapshot::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    /**
     * The snapshot is shared infrastructure, not compatibility surface. If the
     * marker is lost, a consumer can depend on it without a static analyser
     * ever objecting.
     */
    public function test_the_class_is_marked_internal(): void
    {
        $doc = (new ReflectionClass(EffectiveAccessSnapshot::class))->getDocComment();

        $this->assertIsString($doc);
        $this->assertStringContainsString('@internal', $doc);
    }

    public function test_construction_is_package_controlled(): void
    {
        $constructor = (new ReflectionClass(EffectiveAccessSnapshot::class))
            ->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPrivate());
    }

    public function test_the_named_factory_is_public_static_and_internal(): void
    {
        $factory = new ReflectionMethod(EffectiveAccessSnapshot::class, 'of');

        $this->assertTrue($factory->isPublic());
        $this->assertTrue($factory->isStatic());

        $doc = $factory->getDocComment();

        $this->assertIsString($doc);
        $this->assertStringContainsString('@internal', $doc);
        $this->assertStringContainsString(
            'Not supported consumer API',
            $doc,
            'The factory must state that callability is not support.',
        );
    }

    /**
     * `mixed` here would push element validation onto the caller and let an
     * Eloquent model or a raw database row reach the sort comparator.
     */
    public function test_the_factory_inputs_are_precisely_typed(): void
    {
        $factory = new ReflectionMethod(EffectiveAccessSnapshot::class, 'of');
        $actual = [];

        foreach ($factory->getParameters() as $parameter) {
            $type = $parameter->getType();

            $this->assertInstanceOf(ReflectionNamedType::class, $type);

            $actual[$parameter->getName()] = $type->getName();
        }

        $this->assertSame([
            'subject' => SubjectIdentity::class,
            'guardName' => 'string',
            'evaluatedAt' => DateTimeImmutable::class,
            'roles' => 'iterable',
            'permissions' => 'iterable',
        ], $actual);

        $doc = $factory->getDocComment();

        $this->assertIsString($doc);
        $this->assertStringNotContainsString('mixed', $doc);
        $this->assertStringContainsString(
            '@param  iterable<array-key, EffectiveRole>  $roles',
            $doc,
        );
        $this->assertStringContainsString(
            '@param  iterable<array-key, EffectivePermission>  $permissions',
            $doc,
        );
    }

    public function test_its_public_surface_is_exactly_the_internal_projections(): void
    {
        $names = [];

        foreach (
            (new ReflectionClass(EffectiveAccessSnapshot::class))
                ->getMethods(ReflectionMethod::IS_PUBLIC) as $method
        ) {
            $names[] = $method->getName();
        }

        sort($names);

        $this->assertSame([
            'evaluatedAt',
            'guardName',
            'hasPermissionId',
            'hasPermissionNamed',
            'hasRoleId',
            'hasRoleNamed',
            'of',
            'permissions',
            'roles',
            'subject',
        ], $names);
    }

    public function test_its_stored_state_is_private_readonly_and_exactly_typed(): void
    {
        $expected = [
            'subject' => SubjectIdentity::class,
            'guardName' => 'string',
            'evaluatedAt' => DateTimeImmutable::class,
            'roles' => 'array',
            'permissions' => 'array',
            'roleIdKeys' => 'array',
            'roleNameKeys' => 'array',
            'permissionIdKeys' => 'array',
            'permissionNameKeys' => 'array',
        ];
        $actual = [];

        foreach (
            (new ReflectionClass(EffectiveAccessSnapshot::class))
                ->getProperties() as $property
        ) {
            $this->assertTrue($property->isPrivate());
            $this->assertTrue($property->isReadOnly());
            $this->assertFalse($property->isStatic());

            $type = $property->getType();

            $this->assertInstanceOf(ReflectionNamedType::class, $type);

            $actual[$property->getName()] = $type->getName();
        }

        $this->assertSame($expected, $actual);
    }

    public function test_the_accessors_return_exact_types(): void
    {
        $expected = [
            'subject' => SubjectIdentity::class,
            'guardName' => 'string',
            'evaluatedAt' => DateTimeImmutable::class,
            'roles' => Collection::class,
            'permissions' => Collection::class,
            'hasRoleId' => 'bool',
            'hasRoleNamed' => 'bool',
            'hasPermissionId' => 'bool',
            'hasPermissionNamed' => 'bool',
        ];

        foreach ($expected as $method => $type) {
            $returnType = (new ReflectionMethod(
                EffectiveAccessSnapshot::class,
                $method,
            ))->getReturnType();

            $this->assertInstanceOf(ReflectionNamedType::class, $returnType);
            $this->assertSame($type, $returnType->getName());
        }
    }

    // ------------------------------------ 2. Identity and evaluation context

    public function test_it_preserves_the_exact_subject_identity(): void
    {
        $identity = new SubjectIdentity('App\\Models\\User', 7);

        $snapshot = $this->snapshot(subject: $identity);

        $this->assertSame($identity, $snapshot->subject());
        $this->assertSame('App\\Models\\User', $snapshot->subject()->type);
        $this->assertSame(7, $snapshot->subject()->id);
    }

    /**
     * The guard is already resolved when it arrives. Normalizing it here would
     * be a second guard rule, which is exactly what one snapshot exists to
     * prevent.
     */
    public function test_it_preserves_the_guard_name_byte_for_byte(): void
    {
        foreach (['web', 'Web', 'api_v2', ' web '] as $guard) {
            $this->assertSame($guard, $this->snapshot(guardName: $guard)->guardName());
        }
    }

    public function test_it_preserves_the_evaluation_instant_exactly(): void
    {
        $instant = new DateTimeImmutable(
            '2026-08-17 09:41:07.123456',
            new DateTimeZone('Asia/Kuala_Lumpur'),
        );

        $snapshot = $this->snapshot(evaluatedAt: $instant);

        $this->assertSame($instant, $snapshot->evaluatedAt());
        $this->assertSame(
            '2026-08-17T09:41:07.123456+08:00',
            $snapshot->evaluatedAt()->format('Y-m-d\TH:i:s.uP'),
        );
        $this->assertSame(
            'Asia/Kuala_Lumpur',
            $snapshot->evaluatedAt()->getTimezone()->getName(),
        );
    }

    /**
     * One call resolves one instant. A snapshot that reached for the clock, or
     * that renormalized what it was handed, could not return the identical
     * object it was constructed with.
     */
    public function test_it_never_consults_the_system_clock(): void
    {
        $instant = new DateTimeImmutable('1999-12-31 23:59:59.000001');

        $this->assertSame($instant, $this->snapshot(evaluatedAt: $instant)->evaluatedAt());

        $source = $this->source();

        foreach ([
            'new DateTimeImmutable',
            'Carbon',
            'now()',
            'time()',
            'microtime(',
            'date_create',
            'SystemClock',
        ] as $clockCall) {
            $this->assertStringNotContainsString(
                $clockCall,
                $source,
                sprintf('The snapshot must not reach for [%s].', $clockCall),
            );
        }
    }

    // ------------------------------------------------------------- 3. Ordering

    public function test_roles_are_ordered_by_raw_name_bytes(): void
    {
        $roles = [];
        $id = 1;

        foreach (self::unorderedNames() as $name) {
            $roles[] = EffectiveRole::of($id++, $name, 'web');
        }

        $snapshot = $this->snapshot(roles: $roles);

        $this->assertSame(
            self::byteOrderedNames(),
            $snapshot->roles()->map(
                static fn (EffectiveRole $role): string => $role->name,
            )->all(),
        );
    }

    public function test_permissions_are_ordered_by_raw_name_bytes(): void
    {
        $permissions = [];
        $id = 1;

        foreach (self::unorderedNames() as $name) {
            $permissions[] = EffectivePermission::of($id++, $name, 'web');
        }

        $snapshot = $this->snapshot(permissions: $permissions);

        $this->assertSame(
            self::byteOrderedNames(),
            $snapshot->permissions()->map(
                static fn (EffectivePermission $permission): string => $permission->name,
            )->all(),
        );
    }

    /**
     * Asserted against `strcmp()` itself rather than only against a literal
     * list, so the expectation cannot drift away from the rule it encodes.
     */
    public function test_the_order_is_exactly_the_order_strcmp_produces(): void
    {
        $names = self::unorderedNames();

        usort($names, static fn (string $left, string $right): int => strcmp($left, $right));

        $this->assertSame($names, self::byteOrderedNames());
    }

    /**
     * Both case-folding orderings the ADR rejects produce a different list
     * from this fixture, so passing the assertion above is evidence of byte
     * ordering rather than a coincidence.
     */
    public function test_the_order_is_not_case_insensitive(): void
    {
        $caseInsensitive = self::unorderedNames();
        usort($caseInsensitive, static fn (string $left, string $right): int => strcasecmp($left, $right));

        $lowercased = self::unorderedNames();
        usort($lowercased, static fn (string $left, string $right): int => strcmp(
            strtolower($left),
            strtolower($right),
        ));

        foreach ([$caseInsensitive, $lowercased] as $rejected) {
            $this->assertNotSame(self::byteOrderedNames(), $rejected);
        }
    }

    /**
     * Natural ordering agrees with byte ordering on alphabetic names, so it
     * needs a numeric-suffixed fixture of its own: `strnatcmp()` would place
     * `report2` before `report10`, and `strcmp()` does the opposite.
     */
    public function test_the_order_is_not_natural(): void
    {
        $snapshot = $this->snapshot(
            roles: [
                EffectiveRole::of(1, 'report2', 'web'),
                EffectiveRole::of(2, 'report10', 'web'),
            ],
            permissions: [
                EffectivePermission::of(1, 'report2', 'web'),
                EffectivePermission::of(2, 'report10', 'web'),
            ],
        );

        $natural = ['report2', 'report10'];
        usort($natural, static fn (string $left, string $right): int => strnatcmp($left, $right));

        $this->assertSame(['report2', 'report10'], $natural);
        $this->assertSame(
            ['report10', 'report2'],
            $snapshot->roles()->map(
                static fn (EffectiveRole $role): string => $role->name,
            )->all(),
        );
        $this->assertSame(
            ['report10', 'report2'],
            $snapshot->permissions()->map(
                static fn (EffectivePermission $permission): string => $permission->name,
            )->all(),
        );
    }

    // ----------------------------------------------------------- 4. Uniqueness

    /**
     * The realistic duplicate: one definition reached through two roles and a
     * direct grant is the same row three times.
     */
    public function test_duplicate_permission_identifiers_collapse_to_one(): void
    {
        $snapshot = $this->snapshot(permissions: [
            EffectivePermission::of(7, 'documents.approve', 'web'),
            EffectivePermission::of(7, 'documents.approve', 'web'),
            EffectivePermission::of(7, 'documents.approve', 'web'),
            EffectivePermission::of(8, 'documents.delete', 'web'),
        ]);

        $this->assertSame(2, $snapshot->permissions()->count());
        $this->assertSame(
            [7, 8],
            $snapshot->permissions()->map(
                static fn (EffectivePermission $permission): int|string => $permission->id,
            )->all(),
        );
    }

    public function test_duplicate_role_identifiers_collapse_to_one(): void
    {
        $snapshot = $this->snapshot(roles: [
            EffectiveRole::of(3, 'reviewer', 'web'),
            EffectiveRole::of(3, 'reviewer', 'web'),
        ]);

        $this->assertSame(1, $snapshot->roles()->count());
        $this->assertSame(3, $snapshot->roles()->first()?->id);
    }

    /**
     * Synthetic fixture. `(name, guard_name)` is unique, so two same-guard
     * definitions sharing a name cannot exist in a valid database; this proves
     * only that the deduplication key is the primary key and not the name.
     */
    public function test_it_does_not_deduplicate_by_name(): void
    {
        $snapshot = $this->snapshot(
            roles: [
                EffectiveRole::of(1, 'reviewer', 'web'),
                EffectiveRole::of(2, 'reviewer', 'web'),
            ],
            permissions: [
                EffectivePermission::of(1, 'documents.approve', 'web'),
                EffectivePermission::of(2, 'documents.approve', 'web'),
            ],
        );

        $this->assertSame(2, $snapshot->roles()->count());
        $this->assertSame(2, $snapshot->permissions()->count());
        $this->assertTrue($snapshot->hasRoleId(1));
        $this->assertTrue($snapshot->hasRoleId(2));
        $this->assertTrue($snapshot->hasPermissionId(1));
        $this->assertTrue($snapshot->hasPermissionId(2));
    }

    /**
     * PHP rewrites a decimal-string array key to an integer, so an unprefixed
     * index would silently merge these two into one entry.
     */
    public function test_integer_and_string_identifiers_do_not_collide(): void
    {
        $snapshot = $this->snapshot(
            roles: [
                EffectiveRole::of(7, 'reviewer', 'web'),
                EffectiveRole::of('7', 'auditor', 'web'),
            ],
            permissions: [
                EffectivePermission::of(7, 'documents.approve', 'web'),
                EffectivePermission::of('7', 'documents.delete', 'web'),
            ],
        );

        $this->assertSame(2, $snapshot->roles()->count());
        $this->assertSame(2, $snapshot->permissions()->count());

        // Ordered by name, so the integer-keyed role sorts after the
        // string-keyed one and both identifiers survive with their own type.
        $this->assertSame(
            ['7', 7],
            $snapshot->roles()->map(
                static fn (EffectiveRole $role): int|string => $role->id,
            )->all(),
        );

        $this->assertTrue($snapshot->hasRoleId(7));
        $this->assertTrue($snapshot->hasRoleId('7'));
        $this->assertTrue($snapshot->hasPermissionId(7));
        $this->assertTrue($snapshot->hasPermissionId('7'));
    }

    /**
     * A permission identifier must never satisfy a role check merely because
     * both indexes are keyed the same way.
     */
    public function test_role_and_permission_identifier_namespaces_stay_separate(): void
    {
        $snapshot = $this->snapshot(
            roles: [EffectiveRole::of(7, 'reviewer', 'web')],
            permissions: [EffectivePermission::of(9, 'documents.approve', 'web')],
        );

        $this->assertTrue($snapshot->hasRoleId(7));
        $this->assertFalse($snapshot->hasPermissionId(7));

        $this->assertTrue($snapshot->hasPermissionId(9));
        $this->assertFalse($snapshot->hasRoleId(9));
    }

    public function test_identifier_and_name_namespaces_stay_separate(): void
    {
        $snapshot = $this->snapshot(
            roles: [EffectiveRole::of('reviewer', '7', 'web')],
        );

        $this->assertTrue($snapshot->hasRoleId('reviewer'));
        $this->assertTrue($snapshot->hasRoleNamed('7'));
        $this->assertFalse($snapshot->hasRoleNamed('reviewer'));
        $this->assertFalse($snapshot->hasRoleId('7'));
        $this->assertFalse($snapshot->hasRoleId(7));
    }

    /**
     * Synthetic fixture, for the same reason as the name test above. The rule
     * being pinned is that the outcome is deterministic — first occurrence
     * wins — not that this input can be persisted.
     */
    public function test_duplicate_identifiers_resolve_deterministically(): void
    {
        for ($run = 0; $run < 3; $run++) {
            $snapshot = $this->snapshot(roles: [
                EffectiveRole::of(3, 'aaa-first', 'web'),
                EffectiveRole::of(3, 'zzz-second', 'web'),
            ]);

            $this->assertSame(1, $snapshot->roles()->count());
            $this->assertSame('aaa-first', $snapshot->roles()->first()?->name);
        }
    }

    // -------------------------------------------------- 5. Membership projections

    public function test_role_membership_by_identifier(): void
    {
        $snapshot = $this->snapshot(roles: [
            EffectiveRole::of(7, 'reviewer', 'web'),
            EffectiveRole::of('0193c4a1', 'auditor', 'web'),
        ]);

        $this->assertTrue($snapshot->hasRoleId(7));
        $this->assertTrue($snapshot->hasRoleId('0193c4a1'));
        $this->assertFalse($snapshot->hasRoleId(8));
        $this->assertFalse($snapshot->hasRoleId('nope'));
    }

    public function test_permission_membership_by_identifier(): void
    {
        $snapshot = $this->snapshot(permissions: [
            EffectivePermission::of(7, 'documents.approve', 'web'),
            EffectivePermission::of('0193c4a1', 'documents.delete', 'web'),
        ]);

        $this->assertTrue($snapshot->hasPermissionId(7));
        $this->assertTrue($snapshot->hasPermissionId('0193c4a1'));
        $this->assertFalse($snapshot->hasPermissionId(8));
        $this->assertFalse($snapshot->hasPermissionId('nope'));
    }

    /**
     * `in_array()` without the strict flag, or an unprefixed array key, would
     * make each of these four assertions the opposite of what it is.
     */
    public function test_identifier_membership_never_compares_loosely(): void
    {
        $integers = $this->snapshot(
            roles: [EffectiveRole::of(7, 'reviewer', 'web')],
            permissions: [EffectivePermission::of(7, 'documents.approve', 'web')],
        );

        $this->assertTrue($integers->hasRoleId(7));
        $this->assertFalse($integers->hasRoleId('7'));
        $this->assertTrue($integers->hasPermissionId(7));
        $this->assertFalse($integers->hasPermissionId('7'));

        $strings = $this->snapshot(
            roles: [EffectiveRole::of('7', 'reviewer', 'web')],
            permissions: [EffectivePermission::of('7', 'documents.approve', 'web')],
        );

        $this->assertTrue($strings->hasRoleId('7'));
        $this->assertFalse($strings->hasRoleId(7));
        $this->assertTrue($strings->hasPermissionId('7'));
        $this->assertFalse($strings->hasPermissionId(7));

        $this->assertFalse($strings->hasRoleId('007'));
    }

    public function test_role_membership_by_exact_name(): void
    {
        $snapshot = $this->snapshot(roles: [EffectiveRole::of(7, 'Reviewer', 'web')]);

        $this->assertTrue($snapshot->hasRoleNamed('Reviewer'));
        $this->assertFalse($snapshot->hasRoleNamed('reviewer'));
        $this->assertFalse($snapshot->hasRoleNamed('REVIEWER'));
        $this->assertFalse($snapshot->hasRoleNamed(' Reviewer'));
        $this->assertFalse($snapshot->hasRoleNamed('Reviewer '));
        $this->assertFalse($snapshot->hasRoleNamed('editor'));
        $this->assertFalse($snapshot->hasRoleNamed(''));
    }

    public function test_permission_membership_by_exact_name(): void
    {
        $snapshot = $this->snapshot(permissions: [
            EffectivePermission::of(7, 'Documents.Approve', 'web'),
        ]);

        $this->assertTrue($snapshot->hasPermissionNamed('Documents.Approve'));
        $this->assertFalse($snapshot->hasPermissionNamed('documents.approve'));
        $this->assertFalse($snapshot->hasPermissionNamed('DOCUMENTS.APPROVE'));
        $this->assertFalse($snapshot->hasPermissionNamed('Documents.Approve '));
        $this->assertFalse($snapshot->hasPermissionNamed('documents.delete'));
        $this->assertFalse($snapshot->hasPermissionNamed(''));
    }

    public function test_role_and_permission_name_namespaces_stay_separate(): void
    {
        $snapshot = $this->snapshot(
            roles: [EffectiveRole::of(1, 'reviewer', 'web')],
            permissions: [EffectivePermission::of(1, 'documents.approve', 'web')],
        );

        $this->assertTrue($snapshot->hasRoleNamed('reviewer'));
        $this->assertFalse($snapshot->hasPermissionNamed('reviewer'));

        $this->assertTrue($snapshot->hasPermissionNamed('documents.approve'));
        $this->assertFalse($snapshot->hasRoleNamed('documents.approve'));
    }

    public function test_an_empty_snapshot_denies_everything(): void
    {
        $snapshot = $this->snapshot();

        $this->assertTrue($snapshot->roles()->isEmpty());
        $this->assertTrue($snapshot->permissions()->isEmpty());
        $this->assertFalse($snapshot->hasRoleId(1));
        $this->assertFalse($snapshot->hasRoleNamed('reviewer'));
        $this->assertFalse($snapshot->hasPermissionId(1));
        $this->assertFalse($snapshot->hasPermissionNamed('documents.approve'));
    }

    // ------------------------------------------------ 6. Fresh Collection behaviour

    #[DataProvider('collectionProjections')]
    public function test_each_projection_returns_a_fresh_collection(string $method): void
    {
        $snapshot = $this->populated();

        $first = $snapshot->{$method}();
        $second = $snapshot->{$method}();

        $this->assertInstanceOf(Collection::class, $first);
        $this->assertInstanceOf(Collection::class, $second);
        $this->assertNotSame($first, $second);
        $this->assertEquals($first->all(), $second->all());
    }

    #[DataProvider('collectionProjections')]
    public function test_mutating_a_projection_cannot_reach_the_snapshot(
        string $method,
    ): void {
        $snapshot = $this->populated();

        $mutated = $snapshot->{$method}();
        $mutated->push($mutated->first());
        $mutated->forget(0);
        $mutated->pop();

        $this->assertSame(2, $snapshot->{$method}()->count());
        $this->assertSame(
            ['aaa-alpha', 'zzz-omega'],
            $snapshot->{$method}()->map(
                static fn (EffectiveRole|EffectivePermission $value): string => $value->name,
            )->all(),
        );
    }

    /**
     * `sort()` and `filter()` return new collections, but `transform()` and
     * `shift()` mutate in place — the risk is the underlying item array, not
     * the wrapper.
     */
    #[DataProvider('collectionProjections')]
    public function test_mutating_a_projection_cannot_change_membership(
        string $method,
    ): void {
        $snapshot = $this->populated();

        $snapshot->{$method}()->shift();
        $snapshot->{$method}()->transform(static fn (): string => 'clobbered');
        $snapshot->{$method}()->sort(static fn (): int => -1);
        $snapshot->{$method}()->filter(static fn (): bool => false);

        $this->assertTrue($snapshot->hasRoleId(1));
        $this->assertTrue($snapshot->hasRoleNamed('aaa-alpha'));
        $this->assertTrue($snapshot->hasPermissionId(1));
        $this->assertTrue($snapshot->hasPermissionNamed('aaa-alpha'));
        $this->assertSame(2, $snapshot->roles()->count());
        $this->assertSame(2, $snapshot->permissions()->count());
    }

    /**
     * The value objects may be shared because they are `final readonly`. The
     * mutable container must not be.
     */
    public function test_the_projections_share_entries_but_not_containers(): void
    {
        $snapshot = $this->populated();

        $this->assertSame(
            $snapshot->roles()->first(),
            $snapshot->roles()->first(),
        );
        $this->assertNotSame($snapshot->roles(), $snapshot->roles());
    }

    public function test_no_mutable_collection_is_stored(): void
    {
        foreach ($this->storedState($this->populated()) as $name => $value) {
            $this->assertNotInstanceOf(
                Collection::class,
                $value,
                sprintf('[%s] must not retain a mutable Collection.', $name),
            );
        }
    }

    // ------------------------------------------------------ 7. Object-graph safety

    public function test_it_retains_no_implementation_state(): void
    {
        $this->assertGraphIsSafe($this->populated(), 'snapshot');
    }

    /**
     * Section 8 of the ADR rejects a resolver-incremented counter outright: it
     * would report what the algorithm believed it did, not what the connection
     * executed.
     */
    public function test_it_retains_no_query_counter(): void
    {
        foreach ($this->storedState($this->populated()) as $name => $value) {
            $this->assertDoesNotMatchRegularExpression(
                '/count|quer|statement/i',
                $name,
                'The snapshot must carry no query counter.',
            );
            $this->assertIsNotInt($value);
        }
    }

    // ---------------------------------------------------------- 8. No database

    public function test_it_executes_no_database_statement(): void
    {
        $statements = 0;

        $this->app['db']->listen(function () use (&$statements): void {
            $statements++;
        });

        $snapshot = $this->populated();

        $snapshot->subject();
        $snapshot->guardName();
        $snapshot->evaluatedAt();
        $snapshot->roles();
        $snapshot->permissions();
        $snapshot->hasRoleId(1);
        $snapshot->hasRoleNamed('aaa-alpha');
        $snapshot->hasPermissionId(2);
        $snapshot->hasPermissionNamed('zzz-omega');

        $this->assertSame(
            0,
            $statements,
            'Building and reading a snapshot must not reach the database.',
        );
        $this->assertSame(0, $this->app['db']->transactionLevel());
    }

    // ------------------------------------------------------- Element validation

    /**
     * Rejected at the boundary rather than in the comparator, where a foreign
     * element would surface as a `TypeError` inside `usort()`.
     */
    public function test_it_rejects_a_foreign_element_in_the_role_list(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EffectiveAccessSnapshot::of(
            new SubjectIdentity('App\\Models\\User', 7),
            'web',
            new DateTimeImmutable('2026-08-17 00:00:00'),
            [EffectivePermission::of(1, 'documents.approve', 'web')],
            [],
        );
    }

    public function test_it_rejects_a_foreign_element_in_the_permission_list(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EffectiveAccessSnapshot::of(
            new SubjectIdentity('App\\Models\\User', 7),
            'web',
            new DateTimeImmutable('2026-08-17 00:00:00'),
            [],
            [EffectiveRole::of(1, 'reviewer', 'web')],
        );
    }

    /**
     * A rejection message is a diagnostic, not a disclosure channel.
     */
    public function test_the_rejection_message_carries_only_type_labels(): void
    {
        try {
            EffectiveAccessSnapshot::of(
                new SubjectIdentity('App\\Models\\User', 7),
                'web',
                new DateTimeImmutable('2026-08-17 00:00:00'),
                [EffectivePermission::of(4242, 'documents.approve', 'web')],
                [],
            );

            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString(EffectiveRole::class, $exception->getMessage());
            $this->assertStringContainsString(
                EffectivePermission::class,
                $exception->getMessage(),
            );
            $this->assertStringNotContainsString('4242', $exception->getMessage());
            $this->assertStringNotContainsString(
                'documents.approve',
                $exception->getMessage(),
            );
        }
    }

    /**
     * The resolver will hand over lazily produced values, so a `Generator` has
     * to canonicalize identically to an array.
     */
    public function test_it_accepts_any_iterable(): void
    {
        $roles = static function (): Generator {
            yield EffectiveRole::of(2, 'zzz-omega', 'web');
            yield EffectiveRole::of(1, 'aaa-alpha', 'web');
            yield EffectiveRole::of(1, 'aaa-alpha', 'web');
        };

        $snapshot = EffectiveAccessSnapshot::of(
            new SubjectIdentity('App\\Models\\User', 7),
            'web',
            new DateTimeImmutable('2026-08-17 00:00:00'),
            $roles(),
            [],
        );

        $this->assertSame(
            ['aaa-alpha', 'zzz-omega'],
            $snapshot->roles()->map(
                static fn (EffectiveRole $role): string => $role->name,
            )->all(),
        );
    }

    // ------------------------------------------------------------------ Helpers

    /**
     * @param  list<EffectiveRole>  $roles
     * @param  list<EffectivePermission>  $permissions
     */
    private function snapshot(
        array $roles = [],
        array $permissions = [],
        ?SubjectIdentity $subject = null,
        string $guardName = 'web',
        ?DateTimeImmutable $evaluatedAt = null,
    ): EffectiveAccessSnapshot {
        return EffectiveAccessSnapshot::of(
            $subject ?? new SubjectIdentity('App\\Models\\User', 7),
            $guardName,
            $evaluatedAt ?? new DateTimeImmutable('2026-08-17 09:41:07'),
            $roles,
            $permissions,
        );
    }

    private function populated(): EffectiveAccessSnapshot
    {
        return $this->snapshot(
            roles: [
                EffectiveRole::of(2, 'zzz-omega', 'web'),
                EffectiveRole::of(1, 'aaa-alpha', 'web'),
            ],
            permissions: [
                EffectivePermission::of(2, 'zzz-omega', 'web'),
                EffectivePermission::of(1, 'aaa-alpha', 'web'),
            ],
        );
    }

    private function source(): string
    {
        $file = (new ReflectionClass(EffectiveAccessSnapshot::class))->getFileName();

        $this->assertIsString($file);

        $source = file_get_contents($file);

        $this->assertIsString($source);

        return $source;
    }

    /**
     * Every declared property, including the private ones, because that is
     * where implementation state would actually hide.
     *
     * @return array<string, mixed>
     */
    private function storedState(object $value): array
    {
        $state = [];

        foreach ((new ReflectionObject($value))->getProperties() as $property) {
            $state[$property->getName()] = $property->getValue($value);
        }

        return $state;
    }

    /**
     * Walks the retained object graph and fails on anything that could carry a
     * connection, execute a statement, be mutated in place, or capture scope.
     *
     * `DateTimeImmutable` is treated as an opaque leaf: descending into it
     * would assert against PHP's own internals, which is brittle and unrelated
     * to what this test protects.
     */
    private function assertGraphIsSafe(object $value, string $path, int $depth = 0): void
    {
        $this->assertLessThan(
            8,
            $depth,
            sprintf('[%s] is deeper than a snapshot has any reason to be.', $path),
        );

        foreach ([
            Model::class,
            ConnectionInterface::class,
            QueryBuilder::class,
            EloquentBuilder::class,
            Relation::class,
            Collection::class,
            Container::class,
            Closure::class,
        ] as $forbidden) {
            $this->assertNotInstanceOf(
                $forbidden,
                $value,
                sprintf('[%s] must not retain a %s.', $path, $forbidden),
            );
        }

        if ($value instanceof DateTimeImmutable) {
            return;
        }

        foreach ((new ReflectionObject($value))->getProperties() as $property) {
            $this->assertGraphValueIsSafe(
                $property->getValue($value),
                $path.'->'.$property->getName(),
                $depth + 1,
            );
        }
    }

    private function assertGraphValueIsSafe(mixed $value, string $path, int $depth): void
    {
        if (is_object($value)) {
            $this->assertGraphIsSafe($value, $path, $depth);

            return;
        }

        if (is_array($value)) {
            foreach ($value as $key => $entry) {
                $this->assertGraphValueIsSafe(
                    $entry,
                    sprintf('%s[%s]', $path, $key),
                    $depth + 1,
                );
            }

            return;
        }

        // Scalars and null are safe by construction: they carry no connection,
        // no lazy relation and no captured scope.
    }
}

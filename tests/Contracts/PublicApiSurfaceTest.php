<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Contracts;

use HangTuah\GateTrail\Access\EffectivePermission;
use HangTuah\GateTrail\Access\EffectiveRole;
use HangTuah\GateTrail\AssignmentPeriod;
use HangTuah\GateTrail\AssignmentReference;
use HangTuah\GateTrail\Concerns\HasGateTrail;
use HangTuah\GateTrail\Contracts\AssignmentManager;
use HangTuah\GateTrail\Contracts\GateTrailEvent;
use HangTuah\GateTrail\Contracts\PermissionAssignmentContract;
use HangTuah\GateTrail\Contracts\PermissionContract;
use HangTuah\GateTrail\Contracts\PermissionManager;
use HangTuah\GateTrail\Contracts\PermissionResolver;
use HangTuah\GateTrail\Contracts\RoleAssignmentContract;
use HangTuah\GateTrail\Contracts\RoleContract;
use HangTuah\GateTrail\Contracts\RoleManager;
use HangTuah\GateTrail\Contracts\RolePermissionContract;
use HangTuah\GateTrail\Contracts\RoleResolver;
use HangTuah\GateTrail\Database\Migrations\AssignmentTableMigration;
use HangTuah\GateTrail\Database\Migrations\GateTrailMigration;
use HangTuah\GateTrail\Database\Migrations\OracleMigrationExecutor;
use HangTuah\GateTrail\Events\AuditPersistenceFailed;
use HangTuah\GateTrail\Events\MutationCommitted;
use HangTuah\GateTrail\Exceptions\AssignmentNotActive;
use HangTuah\GateTrail\Exceptions\AssignmentScopeCollision;
use HangTuah\GateTrail\Exceptions\AuditWriteFailed;
use HangTuah\GateTrail\Exceptions\ConcurrencyRetryExhausted;
use HangTuah\GateTrail\Exceptions\DdlCompilerUnavailable;
use HangTuah\GateTrail\Exceptions\DuplicateActiveAssignment;
use HangTuah\GateTrail\Exceptions\DuplicatePermission;
use HangTuah\GateTrail\Exceptions\DuplicateRole;
use HangTuah\GateTrail\Exceptions\GuardMismatch;
use HangTuah\GateTrail\Exceptions\InvalidActor;
use HangTuah\GateTrail\Exceptions\InvalidAssignmentPeriod;
use HangTuah\GateTrail\Exceptions\InvalidAuditConfiguration;
use HangTuah\GateTrail\Exceptions\InvalidAuditMetadata;
use HangTuah\GateTrail\Exceptions\InvalidConcurrencyConfiguration;
use HangTuah\GateTrail\Exceptions\InvalidDatabaseIdentifier;
use HangTuah\GateTrail\Exceptions\InvalidGuard;
use HangTuah\GateTrail\Exceptions\InvalidModelConfiguration;
use HangTuah\GateTrail\Exceptions\InvalidSubject;
use HangTuah\GateTrail\Exceptions\MigrationDependencyError;
use HangTuah\GateTrail\Exceptions\PermissionNotFound;
use HangTuah\GateTrail\Exceptions\ReasonRequired;
use HangTuah\GateTrail\Exceptions\RoleNotFound;
use HangTuah\GateTrail\Exceptions\SchemaInspectorUnavailable;
use HangTuah\GateTrail\Exceptions\UnsupportedAfterCommitBoundary;
use HangTuah\GateTrail\Exceptions\UnsupportedDatabaseDriver;
use HangTuah\GateTrail\GateTrailServiceProvider;
use HangTuah\GateTrail\Models\AccessAudit;
use HangTuah\GateTrail\Models\Concerns\HasAssignmentPeriodScopes;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\PermissionAssignment;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\Models\RoleAssignment;
use HangTuah\GateTrail\Models\RolePermission;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\MutationResult;
use HangTuah\GateTrail\PermissionChanges;
use HangTuah\GateTrail\PermissionReference;
use HangTuah\GateTrail\RoleChanges;
use HangTuah\GateTrail\RoleReference;
use HangTuah\GateTrail\SystemActor;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionEnum;
use ReflectionEnumBackedCase;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use SplFileInfo;

/**
 * Freezes the accepted pre-v1 boundary from ADR 0014.
 *
 * A deliberate surface change updates this manifest alongside its ADR and
 * changelog entry. An unclassified symbol or accidental signature drift fails
 * before it can become an undocumented compatibility promise.
 */
final class PublicApiSurfaceTest extends TestCase
{
    private const NAMESPACE = 'HangTuah\\GateTrail\\';

    /** @var list<class-string> */
    public const TIER_ONE = [
        AssignmentManager::class,
        RoleManager::class,
        PermissionManager::class,
        PermissionResolver::class,
        RoleResolver::class,
        GateTrailEvent::class,
        AssignmentPeriod::class,
        AssignmentReference::class,
        RoleReference::class,
        PermissionReference::class,
        RoleChanges::class,
        PermissionChanges::class,
        MutationResult::class,
        MutationOutcome::class,
        SystemActor::class,
        EffectivePermission::class,
        EffectiveRole::class,
        MutationCommitted::class,
        AuditPersistenceFailed::class,
        AssignmentNotActive::class,
        AssignmentScopeCollision::class,
        AuditWriteFailed::class,
        ConcurrencyRetryExhausted::class,
        DuplicateActiveAssignment::class,
        DuplicatePermission::class,
        DuplicateRole::class,
        GuardMismatch::class,
        InvalidActor::class,
        InvalidAssignmentPeriod::class,
        InvalidAuditConfiguration::class,
        InvalidAuditMetadata::class,
        InvalidConcurrencyConfiguration::class,
        InvalidGuard::class,
        InvalidModelConfiguration::class,
        InvalidSubject::class,
        PermissionNotFound::class,
        ReasonRequired::class,
        RoleNotFound::class,
        UnsupportedAfterCommitBoundary::class,
    ];

    /** @var list<class-string> */
    public const TIER_TWO = [
        RoleContract::class,
        PermissionContract::class,
        RoleAssignmentContract::class,
        PermissionAssignmentContract::class,
        RolePermissionContract::class,
        Role::class,
        Permission::class,
        RoleAssignment::class,
        PermissionAssignment::class,
        RolePermission::class,
        AccessAudit::class,
        HasGateTrail::class,
        HasAssignmentPeriodScopes::class,
    ];

    /** @var list<class-string> */
    private const FRAMEWORK = [
        GateTrailServiceProvider::class,
        GateTrailMigration::class,
        AssignmentTableMigration::class,
        OracleMigrationExecutor::class,
    ];

    /** @var list<class-string> */
    private const OPERATIONAL = [
        DdlCompilerUnavailable::class,
        SchemaInspectorUnavailable::class,
        UnsupportedDatabaseDriver::class,
        InvalidDatabaseIdentifier::class,
        MigrationDependencyError::class,
    ];

    private const SURFACE_SHA256 = '93f7bfa869bd507a6d594532d65281bbc3c66c17a6892086a9ebe3f6e0a48013';

    public function test_every_source_symbol_has_exactly_one_accepted_classification(): void
    {
        $source = $this->sourceSymbols();
        $supported = [...self::TIER_ONE, ...self::TIER_TWO];
        $nonInternal = [...$supported, ...self::FRAMEWORK, ...self::OPERATIONAL];

        $this->assertCount(219, $source);
        $this->assertCount(39, self::TIER_ONE);
        $this->assertCount(13, self::TIER_TWO);
        $this->assertCount(61, $nonInternal);
        $this->assertSame($nonInternal, array_values(array_unique($nonInternal)));

        $unclassified = array_values(array_diff($source, $nonInternal));
        $this->assertCount(158, $unclassified);

        foreach ($source as $symbol) {
            $documentation = (string) (new ReflectionClass($symbol))->getDocComment();

            if (in_array($symbol, $nonInternal, true)) {
                $this->assertFalse(
                    $this->hasInternalTag($documentation),
                    sprintf('%s is supported and must not be class-level internal.', $symbol),
                );

                continue;
            }

            $this->assertTrue(
                $this->hasInternalTag($documentation),
                sprintf('%s is an unclassified or unannotated Tier 4 symbol.', $symbol),
            );
        }
    }

    public function test_tier_one_and_two_surface_matches_the_accepted_manifest(): void
    {
        $manifest = [];

        foreach ([...self::TIER_ONE, ...self::TIER_TWO] as $symbol) {
            $manifest[$symbol] = $this->surfaceOf($symbol);
        }

        ksort($manifest);
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->assertSame(
            self::SURFACE_SHA256,
            hash('sha256', $json),
            "The accepted public surface changed. Review this manifest:\n".$json,
        );
    }

    public function test_public_api_document_lists_the_same_supported_symbols(): void
    {
        $this->assertSame(self::TIER_ONE, $this->documentedSymbols('1'));
        $this->assertSame(self::TIER_TWO, $this->documentedSymbols('2'));
    }

    public function test_public_api_document_pins_the_inherited_and_clock_surface(): void
    {
        $documentation = file_get_contents(
            dirname(__DIR__, 2).'/docs/PUBLIC_API.md',
        );

        $this->assertIsString($documentation);

        foreach ([
            'scopeActiveAt(Builder $query, DateTimeInterface $time): Builder',
            'scopeScheduledAt(Builder $query, DateTimeInterface $time): Builder',
            'scopeExpiredAt(Builder $query, DateTimeInterface $time): Builder',
            'scopeRevoked(Builder $query): Builder',
            'Psr\\Clock\\ClockInterface',
            'all 14 mutation command',
            'effective-access evaluation',
        ] as $promise) {
            $this->assertStringContainsString($promise, $documentation);
        }
    }

    /**
     * @return iterable<string, array{\Closure(): mixed}>
     */
    public static function standardArgumentFailures(): iterable
    {
        foreach ([
            AssignmentReference::class,
            RoleReference::class,
            PermissionReference::class,
        ] as $reference) {
            yield $reference.' rejects an empty string' => [
                static fn (): mixed => $reference::id(''),
            ];
            yield $reference.' rejects more than 191 characters' => [
                static fn (): mixed => $reference::id(str_repeat('a', 192)),
            ];
        }

        yield 'RoleChanges rejects non-UTF-8' => [
            static fn (): RoleChanges => RoleChanges::description("\xB1\x31"),
        ];
        yield 'PermissionChanges rejects non-UTF-8' => [
            static fn (): PermissionChanges => PermissionChanges::description("\xB1\x31"),
        ];
        yield 'MutationResult rejects an unchanged audited result' => [
            static fn (): MutationResult => new MutationResult(
                new Role,
                MutationOutcome::Unchanged,
                1,
            ),
        ];
    }

    /** @param \Closure(): mixed $operation */
    #[DataProvider('standardArgumentFailures')]
    public function test_standard_argument_validation_remains_part_of_the_surface(
        \Closure $operation,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        $operation();
    }

    /** @return list<class-string> */
    private function sourceSymbols(): array
    {
        $source = dirname(__DIR__, 2).'/src';
        $symbols = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source));

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($source) + 1, -4);
            $symbol = self::NAMESPACE.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

            $this->assertTrue(
                class_exists($symbol) || interface_exists($symbol) || trait_exists($symbol) || enum_exists($symbol),
                sprintf('%s must declare the PSR-4 symbol implied by its path.', $file->getPathname()),
            );

            $symbols[] = $symbol;
        }

        sort($symbols);

        return $symbols;
    }

    /** @return list<class-string> */
    private function documentedSymbols(string $tier): array
    {
        $documentation = file_get_contents(
            dirname(__DIR__, 2).'/docs/PUBLIC_API.md',
        );

        $this->assertIsString($documentation);
        $matched = preg_match(
            sprintf(
                '/<!-- gatetrail-tier-%1$s:start -->(.*?)<!-- gatetrail-tier-%1$s:end -->/s',
                $tier,
            ),
            $documentation,
            $section,
        );

        $this->assertSame(1, $matched, sprintf('Tier %s manifest markers are required.', $tier));
        preg_match_all(
            '/<code>(HangTuah\\\\GateTrail\\\\[^<]+)<\/code>/',
            $section[1],
            $symbols,
        );

        /** @var list<class-string> $documented */
        $documented = $symbols[1];

        return $documented;
    }

    /**
     * @param  class-string  $symbol
     * @return array<string, mixed>
     */
    private function surfaceOf(string $symbol): array
    {
        $reflection = new ReflectionClass($symbol);
        $methods = [];
        $properties = [];
        $constants = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $symbol
                || $method->isConstructor()
                || $this->hasInternalTag((string) $method->getDocComment())) {
                continue;
            }

            $methods[$method->getName()] = $this->methodSurface($method);
        }

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getDeclaringClass()->getName() !== $symbol) {
                continue;
            }

            $properties[$property->getName()] = [
                'type' => $this->typeName($property->getType()),
                'readonly' => $property->isReadOnly(),
            ];
        }

        foreach ($reflection->getReflectionConstants() as $constant) {
            if ($constant->getDeclaringClass()->getName() !== $symbol
                || ! $constant->isPublic()
                || $constant->isEnumCase()) {
                continue;
            }

            $constants[$constant->getName()] = $constant->getValue();
        }

        ksort($methods);
        ksort($properties);
        ksort($constants);

        $constructor = $reflection->getConstructor();
        $declaredConstructor = $constructor !== null
            && $constructor->getDeclaringClass()->getName() === $symbol;

        $enumCases = [];

        if ($reflection->isEnum()) {
            /** @var class-string<\UnitEnum> $symbol */
            $enumCases = $this->enumCases(new ReflectionEnum($symbol));
        }

        return [
            'kind' => match (true) {
                $reflection->isEnum() => 'enum',
                $reflection->isInterface() => 'interface',
                $reflection->isTrait() => 'trait',
                default => 'class',
            },
            'final' => $reflection->isFinal(),
            'readonly' => $reflection->isReadOnly(),
            'constructor' => $declaredConstructor ? $this->methodSurface($constructor) : null,
            'methods' => $methods,
            'properties' => $properties,
            'constants' => $constants,
            'enum_cases' => $enumCases,
        ];
    }

    /** @return array<string, mixed> */
    private function methodSurface(ReflectionMethod $method): array
    {
        return [
            'visibility' => match (true) {
                $method->isPrivate() => 'private',
                $method->isProtected() => 'protected',
                default => 'public',
            },
            'static' => $method->isStatic(),
            'final' => $method->isFinal(),
            'parameters' => array_map(
                fn (ReflectionParameter $parameter): array => $this->parameterSurface($parameter),
                $method->getParameters(),
            ),
            'return' => $this->typeName($method->getReturnType()),
        ];
    }

    /** @return array<string, mixed> */
    private function parameterSurface(ReflectionParameter $parameter): array
    {
        return [
            'name' => $parameter->getName(),
            'type' => $this->typeName($parameter->getType()),
            'by_reference' => $parameter->isPassedByReference(),
            'variadic' => $parameter->isVariadic(),
            'has_default' => $parameter->isDefaultValueAvailable(),
            'default' => $parameter->isDefaultValueAvailable()
                ? $parameter->getDefaultValue()
                : null,
        ];
    }

    private function typeName(?ReflectionType $type): ?string
    {
        if ($type === null) {
            return null;
        }

        if ($type instanceof ReflectionNamedType) {
            return ($type->allowsNull() && ! in_array($type->getName(), ['mixed', 'null'], true) ? '?' : '')
                .$type->getName();
        }

        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            $names = array_map(
                fn (ReflectionType $member): string => (string) $this->typeName($member),
                $type->getTypes(),
            );
            sort($names);

            return implode($type instanceof ReflectionUnionType ? '|' : '&', $names);
        }

        return (string) $type;
    }

    private function hasInternalTag(string $documentation): bool
    {
        return preg_match('/(?:\/\*\*|\*)\s+@internal\b/', $documentation) === 1;
    }

    /**
     * @param  ReflectionEnum<\UnitEnum>  $enum
     * @return array<string, int|string|null>
     */
    private function enumCases(ReflectionEnum $enum): array
    {
        $cases = [];

        foreach ($enum->getCases() as $case) {
            $cases[$case->getName()] = $case instanceof ReflectionEnumBackedCase
                ? $case->getBackingValue()
                : null;
        }

        return $cases;
    }
}

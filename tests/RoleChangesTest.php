<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests;

use HangTuah\GateTrail\RoleChanges;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class RoleChangesTest extends TestCase
{
    public function test_description_string_is_preserved_exactly(): void
    {
        $changes = RoleChanges::description(
            '  Reviews submitted documents.  ',
        );

        $this->assertSame(
            '  Reviews submitted documents.  ',
            $changes->description,
        );
    }

    public function test_explicit_null_clears_the_description(): void
    {
        $this->assertNull(RoleChanges::description(null)->description);
    }

    public function test_explicit_empty_string_is_preserved(): void
    {
        $this->assertSame(
            '',
            RoleChanges::description('')->description,
        );
    }

    public function test_unicode_description_is_preserved(): void
    {
        $this->assertSame(
            'Menyemak dokumen — 审查文件 ✅',
            RoleChanges::description('Menyemak dokumen — 审查文件 ✅')
                ->description,
        );
    }

    public function test_invalid_utf8_description_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be valid UTF-8');

        RoleChanges::description("\xC3\x28");
    }

    public function test_changes_are_immutable(): void
    {
        $reflection = new ReflectionClass(RoleChanges::class);

        $this->assertTrue($reflection->isReadOnly());
        $this->assertTrue($reflection->isFinal());
    }

    public function test_no_empty_or_ambiguous_changes_object_can_be_built(): void
    {
        $reflection = new ReflectionClass(RoleChanges::class);
        $constructor = $reflection->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPrivate());
        $this->assertSame(
            ['description'],
            array_values(array_map(
                static fn (ReflectionMethod $method): string => $method
                    ->getName(),
                array_filter(
                    $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
                    static fn (ReflectionMethod $method): bool => $method
                        ->isStatic(),
                ),
            )),
        );
    }

    public function test_name_and_guard_have_no_change_api(): void
    {
        $reflection = new ReflectionClass(RoleChanges::class);

        $this->assertFalse($reflection->hasMethod('name'));
        $this->assertFalse($reflection->hasMethod('guard'));
        $this->assertFalse($reflection->hasProperty('name'));
        $this->assertFalse($reflection->hasProperty('guard'));
        $this->assertFalse($reflection->hasProperty('guard_name'));
    }
}

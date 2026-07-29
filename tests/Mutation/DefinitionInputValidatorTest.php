<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use HangTuah\GateTrail\Mutations\DefinitionInputValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DefinitionInputValidatorTest extends TestCase
{
    private DefinitionInputValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new DefinitionInputValidator;
    }

    public function test_name_and_guard_are_trimmed_with_case_preserved(): void
    {
        $this->assertSame(
            'Reviewer-Lead',
            $this->validator->name('  Reviewer-Lead  '),
        );
        $this->assertSame('Web', $this->validator->guard('  Web  '));
    }

    public function test_numeric_looking_name_is_preserved_as_a_name(): void
    {
        $this->assertSame('123', $this->validator->name('123'));
    }

    public function test_empty_name_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('name cannot be empty');

        $this->validator->name('   ');
    }

    public function test_empty_guard_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('guard name cannot be empty');

        $this->validator->guard('');
    }

    public function test_boundary_length_is_accepted_and_overflow_rejected(): void
    {
        $boundary = str_repeat('a', 191);

        $this->assertSame($boundary, $this->validator->name($boundary));
        $this->assertSame($boundary, $this->validator->guard($boundary));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot exceed 191 characters');

        $this->validator->name(str_repeat('a', 192));
    }

    public function test_guard_overflow_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot exceed 191 characters');

        $this->validator->guard(str_repeat('a', 192));
    }

    public function test_unicode_names_count_characters_not_bytes(): void
    {
        $multibyte = str_repeat('ü', 191);

        $this->assertSame('Penyemak-审查-Ω', $this->validator->name('Penyemak-审查-Ω'));
        $this->assertSame($multibyte, $this->validator->name($multibyte));
    }

    public function test_invalid_utf8_name_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be valid UTF-8');

        $this->validator->name("rev\xC3\x28iewer");
    }

    public function test_description_preserves_null_and_explicit_empty_string(): void
    {
        $this->assertNull($this->validator->description(null));
        $this->assertSame('', $this->validator->description(''));
        $this->assertSame(
            "  Reviews documents.\nSecond line.  ",
            $this->validator->description(
                "  Reviews documents.\nSecond line.  ",
            ),
        );
    }

    public function test_invalid_utf8_description_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be valid UTF-8');

        $this->validator->description("\xC3\x28");
    }
}

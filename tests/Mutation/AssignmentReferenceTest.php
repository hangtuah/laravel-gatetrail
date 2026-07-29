<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use HangTuah\GateTrail\AssignmentReference;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AssignmentReferenceTest extends TestCase
{
    public function test_integer_and_string_identifiers_are_preserved(): void
    {
        $this->assertSame(42, AssignmentReference::id(42)->id);
        $this->assertSame('0042', AssignmentReference::id('0042')->id);
    }

    public function test_empty_identifier_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be empty');

        AssignmentReference::id('  ');
    }
}

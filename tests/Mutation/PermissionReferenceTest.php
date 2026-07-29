<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use HangTuah\GateTrail\PermissionReference;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PermissionReferenceTest extends TestCase
{
    public function test_reference_preserves_explicit_identifier(): void
    {
        $this->assertSame('0042', PermissionReference::id('0042')->id);
    }

    public function test_empty_reference_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PermissionReference::id(' ');
    }
}

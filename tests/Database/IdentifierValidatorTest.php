<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Database;

use HangTuah\GateTrail\Database\Schema\IdentifierValidator;
use HangTuah\GateTrail\Exceptions\InvalidDatabaseIdentifier;
use PHPUnit\Framework\TestCase;

final class IdentifierValidatorTest extends TestCase
{
    public function test_it_accepts_a_portable_identifier(): void
    {
        $validator = new IdentifierValidator;

        $validator->validate('acl_role_name_guard_uq');

        $this->addToAssertionCount(1);
    }

    public function test_it_rejects_an_identifier_longer_than_thirty_characters(): void
    {
        $validator = new IdentifierValidator;

        $this->expectException(InvalidDatabaseIdentifier::class);
        $this->expectExceptionMessage('exceeds 30 characters');

        $validator->validate(str_repeat('a', 31));
    }

    public function test_it_rejects_non_portable_characters(): void
    {
        $validator = new IdentifierValidator;

        $this->expectException(InvalidDatabaseIdentifier::class);
        $this->expectExceptionMessage('only ASCII letters');

        $validator->validate('acl-role');
    }
}

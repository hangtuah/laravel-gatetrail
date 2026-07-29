<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use HangTuah\GateTrail\Mutations\AssignmentIdentifierNormalizer;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;

final class AssignmentIdentifierNormalizerTest extends TestCase
{
    public function test_integer_identifiers_pass_through_for_integer_keys(): void
    {
        $normalizer = new AssignmentIdentifierNormalizer;

        $this->assertSame(
            7,
            $normalizer->normalize(new IntegerKeyAssignment, 7),
        );
        $this->assertSame(
            0,
            $normalizer->normalize(new IntegerKeyAssignment, 0),
        );
    }

    public function test_canonical_numeric_strings_bind_as_integers(): void
    {
        $normalizer = new AssignmentIdentifierNormalizer;

        $this->assertSame(
            7,
            $normalizer->normalize(new IntegerKeyAssignment, '7'),
        );
        $this->assertSame(
            0,
            $normalizer->normalize(new IntegerKeyAssignment, '0'),
        );
        $this->assertSame(
            7,
            $normalizer->normalize(new IntegerKeyAssignment, '007'),
        );
        $this->assertSame(
            PHP_INT_MAX,
            $normalizer->normalize(
                new IntegerKeyAssignment,
                (string) PHP_INT_MAX,
            ),
        );
    }

    public function test_malformed_strings_are_rejected_for_integer_keys(): void
    {
        $normalizer = new AssignmentIdentifierNormalizer;
        $malformed = [
            '',
            ' 7',
            '7 ',
            '+7',
            '-7',
            '7.0',
            '7,0',
            '0x1A',
            '1e3',
            "7\n",
            'abc',
            'missing-assignment',
            '9223372036854775808',
        ];

        foreach ($malformed as $identifier) {
            $this->assertNull(
                $normalizer->normalize(
                    new IntegerKeyAssignment,
                    $identifier,
                ),
                sprintf('Identifier [%s] should be rejected.', $identifier),
            );
        }
    }

    public function test_string_keys_preserve_every_identifier_unchanged(): void
    {
        $normalizer = new AssignmentIdentifierNormalizer;

        $this->assertSame(
            '018f4ac0-73a6-7c3e-9a34-0242ac120002',
            $normalizer->normalize(
                new StringKeyAssignment,
                '018f4ac0-73a6-7c3e-9a34-0242ac120002',
            ),
        );
        $this->assertSame(
            '007',
            $normalizer->normalize(new StringKeyAssignment, '007'),
        );
        $this->assertSame(
            7,
            $normalizer->normalize(new StringKeyAssignment, 7),
        );
    }
}

final class IntegerKeyAssignment extends Model {}

final class StringKeyAssignment extends Model
{
    protected $keyType = 'string';
}

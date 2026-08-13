<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Subjects;

use HangTuah\GateTrail\Exceptions\InvalidSubject;
use HangTuah\GateTrail\Subjects\SubjectIdentity;
use HangTuah\GateTrail\Subjects\SubjectIdentityResolver;
use HangTuah\GateTrail\Tests\Fixtures\ArbitraryKeySubject;
use HangTuah\GateTrail\Tests\Fixtures\EmptyMorphSubject;
use HangTuah\GateTrail\Tests\Fixtures\StringKeyedSubject;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

final class SubjectIdentityResolverTest extends TestCase
{
    private SubjectIdentityResolver $resolver;

    /**
     * Every identifier the initial runtime must reject, shared with the
     * mutation-path test so both prove the same table.
     *
     * @return array<string, array{mixed}>
     */
    public static function rejectedIdentifiers(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'negative string' => ['-1'],
            'signed string' => ['+1'],
            'negative integer' => [-1],
            'leading space' => [' 1'],
            'trailing space' => ['1 '],
            'trailing newline' => ["1\n"],
            'decimal notation' => ['1.0'],
            'exponent notation' => ['1e3'],
            'hexadecimal notation' => ['0x1f'],
            'uuid' => ['0193c4a1-6f2e-7a3b-9c4d-5e6f70819a2b'],
            'ulid' => ['01JBQZ9YV8K3M2N4P5R6S7T8V9'],
            'above php int max' => ['9223372036854775808'],
            'far above php int max' => ['99999999999999999999'],
            'non-ascii digits' => ['٧'],
            'float key' => [1.0],
            'boolean key' => [true],
            'array key' => [[1]],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('string_keyed_subjects', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
        });

        $this->resolver = app(SubjectIdentityResolver::class);
    }

    protected function tearDown(): void
    {
        Relation::morphMap([], false);

        parent::tearDown();
    }

    public function test_it_is_bound_as_a_shared_singleton(): void
    {
        $this->assertSame(
            app(SubjectIdentityResolver::class),
            app(SubjectIdentityResolver::class),
        );
    }

    public function test_it_resolves_a_persisted_integer_key_subject(): void
    {
        $subject = TestSubject::query()->create(['name' => 'Reviewer']);

        $identity = $this->resolver->resolve($subject);

        $this->assertInstanceOf(SubjectIdentity::class, $identity);
        $this->assertSame($subject->getMorphClass(), $identity->type);
        $this->assertSame($subject->getKey(), $identity->id);
    }

    public function test_it_resolves_a_persisted_numeric_string_key_subject(): void
    {
        $subject = StringKeyedSubject::query()->create([
            'id' => '42',
            'name' => 'Auditor',
        ]);

        $identity = $this->resolver->resolve($subject);

        $this->assertSame(42, $identity->id);
    }

    /**
     * The canonical identifier is a native integer, so these three inputs are
     * not merely equal — they are identical, including by PHP scalar type.
     */
    public function test_leading_zeros_string_and_integer_keys_resolve_identically(): void
    {
        $canonical = $this->resolver->resolve(
            ArbitraryKeySubject::persistedWith(7),
        );
        $string = $this->resolver->resolve(
            ArbitraryKeySubject::persistedWith('7'),
        );
        $padded = $this->resolver->resolve(
            ArbitraryKeySubject::persistedWith('007'),
        );

        $this->assertSame(7, $canonical->id);
        $this->assertSame(7, $string->id);
        $this->assertSame(7, $padded->id);
        $this->assertEquals($canonical, $string);
        $this->assertEquals($canonical, $padded);
    }

    public function test_it_accepts_zero_consistently_with_the_coordination_key_rule(): void
    {
        $this->assertSame(
            0,
            $this->resolver->resolve(ArbitraryKeySubject::persistedWith(0))->id,
        );
        $this->assertSame(
            0,
            $this->resolver->resolve(ArbitraryKeySubject::persistedWith('0'))->id,
        );
        $this->assertSame(
            0,
            $this->resolver->resolve(ArbitraryKeySubject::persistedWith('000'))->id,
        );
    }

    public function test_it_accepts_the_integer_boundary_from_both_shapes(): void
    {
        $this->assertSame(
            PHP_INT_MAX,
            $this->resolver->resolve(
                ArbitraryKeySubject::persistedWith(PHP_INT_MAX),
            )->id,
        );
        $this->assertSame(
            PHP_INT_MAX,
            $this->resolver->resolve(
                ArbitraryKeySubject::persistedWith((string) PHP_INT_MAX),
            )->id,
        );
    }

    /**
     * Casting a string above the boundary saturates to PHP_INT_MAX, which
     * would resolve an out-of-range key to a real subject.
     */
    public function test_a_value_above_the_boundary_is_rejected_rather_than_saturated(): void
    {
        $overflowing = (string) PHP_INT_MAX;
        $overflowing[strlen($overflowing) - 1] = '8';

        $this->assertSame(PHP_INT_MAX, (int) $overflowing);

        $this->expectException(InvalidSubject::class);

        $this->resolver->resolve(
            ArbitraryKeySubject::persistedWith($overflowing),
        );
    }

    public function test_it_preserves_a_morph_map_alias_exactly(): void
    {
        Relation::morphMap(['test_subject' => TestSubject::class]);

        $subject = TestSubject::query()->create(['name' => 'Reviewer']);

        $this->assertSame(
            'test_subject',
            $this->resolver->resolve($subject)->type,
        );
    }

    public function test_it_does_not_trim_or_rewrite_a_valid_morph_alias(): void
    {
        $subject = EmptyMorphSubject::persistedWith(' spaced.alias ');

        $this->assertSame(
            ' spaced.alias ',
            $this->resolver->resolve($subject)->type,
        );
    }

    public function test_non_key_in_memory_attributes_do_not_change_the_identity(): void
    {
        $subject = TestSubject::query()->create(['name' => 'Reviewer']);
        $before = $this->resolver->resolve($subject);

        $subject->name = 'Escalated';

        $after = $this->resolver->resolve($subject);

        $this->assertTrue($subject->isDirty('name'));
        $this->assertEquals($before, $after);
    }

    public function test_an_unsaved_subject_is_rejected(): void
    {
        $subject = new TestSubject(['name' => 'Reviewer']);

        $this->assertSame(
            InvalidSubject::REASON_NOT_PERSISTED,
            $this->reasonFor($subject),
        );
    }

    public function test_a_null_key_is_reported_as_a_missing_identifier(): void
    {
        $this->assertSame(
            InvalidSubject::REASON_MISSING_IDENTIFIER,
            $this->reasonFor(ArbitraryKeySubject::persistedWith(null)),
        );
    }

    public function test_an_unsupported_key_type_is_rejected(): void
    {
        $this->assertSame(
            InvalidSubject::REASON_UNSUPPORTED_IDENTIFIER,
            $this->reasonFor(ArbitraryKeySubject::persistedWith(new stdClass)),
        );
    }

    #[DataProvider('rejectedIdentifiers')]
    public function test_it_rejects_every_unsupported_identifier(mixed $key): void
    {
        $this->expectException(InvalidSubject::class);

        $this->resolver->resolve(ArbitraryKeySubject::persistedWith($key));
    }

    public function test_a_dirty_primary_key_is_rejected(): void
    {
        $subject = TestSubject::query()->create(['name' => 'Reviewer']);
        $subject->id = 999;

        $this->assertSame(
            InvalidSubject::REASON_MUTATED_IDENTIFIER,
            $this->reasonFor($subject),
        );
    }

    /**
     * Rewriting the key to its own persisted value in a different scalar type
     * is not a mutation: Eloquent compares the key through its cast, so the
     * attribute is never dirty, and canonicalization would collapse the two
     * shapes to one identity regardless. Nothing here can address a subject
     * other than the persisted one, which is what the dirty rule protects.
     */
    public function test_a_key_rewritten_to_an_equivalent_value_still_resolves(): void
    {
        $subject = TestSubject::query()->create(['name' => 'Reviewer']);
        $persisted = $this->resolver->resolve($subject);

        $subject->id = (string) $subject->getKey();

        $this->assertFalse($subject->isDirty('id'));
        $this->assertEquals($persisted, $this->resolver->resolve($subject));
    }

    /**
     * The rule that matters: a key pointing at a *different* subject is
     * rejected rather than resolved to either candidate.
     */
    public function test_a_key_dirtied_to_a_different_subject_is_rejected(): void
    {
        $subject = TestSubject::query()->create(['name' => 'Reviewer']);
        $other = TestSubject::query()->create(['name' => 'Administrator']);

        $subject->id = $other->getKey();

        $this->assertSame(
            InvalidSubject::REASON_MUTATED_IDENTIFIER,
            $this->reasonFor($subject),
        );
    }

    public function test_an_empty_morph_type_is_rejected(): void
    {
        $this->assertSame(
            InvalidSubject::REASON_INVALID_MORPH_TYPE,
            $this->reasonFor(EmptyMorphSubject::persistedWith('')),
        );
    }

    public function test_a_whitespace_only_morph_type_is_rejected(): void
    {
        $this->assertSame(
            InvalidSubject::REASON_INVALID_MORPH_TYPE,
            $this->reasonFor(EmptyMorphSubject::persistedWith("  \t ")),
        );
    }

    /**
     * No usable alias exists for this failure, so the exception falls back to
     * the concrete class name rather than reporting an empty type.
     */
    public function test_an_invalid_morph_type_reports_a_safe_class_label(): void
    {
        try {
            $this->resolver->resolve(EmptyMorphSubject::persistedWith(''));
            $this->fail('Expected InvalidSubject.');
        } catch (InvalidSubject $exception) {
            $this->assertSame(EmptyMorphSubject::class, $exception->subjectType);
        }
    }

    private function reasonFor(Model $subject): string
    {
        try {
            $this->resolver->resolve($subject);
        } catch (InvalidSubject $exception) {
            return $exception->reason;
        }

        $this->fail('Expected InvalidSubject to be thrown.');
    }
}

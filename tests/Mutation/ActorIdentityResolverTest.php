<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use HangTuah\GateTrail\Exceptions\InvalidActor;
use HangTuah\GateTrail\Mutations\ActorIdentityResolver;
use HangTuah\GateTrail\SystemActor;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;

final class ActorIdentityResolverTest extends TestCase
{
    private ActorIdentityResolver $actors;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actors = new ActorIdentityResolver;
    }

    public function test_numeric_human_actor_uses_morph_alias_and_decimal_id(): void
    {
        $actor = new NumericActor;
        $actor->setAttribute('id', 42);

        $identity = $this->actors->resolve($actor);

        $this->assertSame('admin', $identity->type);
        $this->assertSame('42', $identity->id);
    }

    public function test_uuid_human_actor_preserves_its_string_key(): void
    {
        $actor = new StringActor;
        $actor->setAttribute(
            'id',
            '550e8400-e29b-41d4-a716-446655440000',
        );

        $identity = $this->actors->resolve($actor);

        $this->assertSame('service-account', $identity->type);
        $this->assertSame(
            '550e8400-e29b-41d4-a716-446655440000',
            $identity->id,
        );
    }

    public function test_ulid_human_actor_preserves_case(): void
    {
        $actor = new StringActor;
        $actor->setAttribute('id', '01J3ABCDEFGHJKMNPQRSTVWXYZ');

        $identity = $this->actors->resolve($actor);

        $this->assertSame('01J3ABCDEFGHJKMNPQRSTVWXYZ', $identity->id);
    }

    public function test_system_actor_is_trimmed_and_case_preserving(): void
    {
        $identity = $this->actors->resolve(
            SystemActor::named('  Staff-Sync  '),
        );

        $this->assertSame(SystemActor::TYPE, $identity->type);
        $this->assertSame('Staff-Sync', $identity->id);
    }

    public function test_empty_system_actor_name_is_rejected(): void
    {
        $this->expectException(InvalidActor::class);
        $this->expectExceptionMessage('cannot be empty');

        SystemActor::named('   ');
    }

    public function test_system_actor_name_cannot_exceed_portable_limit(): void
    {
        $this->expectException(InvalidActor::class);
        $this->expectExceptionMessage('cannot exceed 191 characters');

        SystemActor::named(str_repeat('a', 192));
    }

    public function test_human_actor_requires_a_model_key(): void
    {
        $this->expectException(InvalidActor::class);
        $this->expectExceptionMessage('must have an integer or string model key');

        $this->actors->resolve(new NumericActor);
    }

    public function test_reserved_system_morph_alias_is_rejected(): void
    {
        $actor = new ReservedActor;
        $actor->setAttribute('id', 42);

        $this->expectException(InvalidActor::class);
        $this->expectExceptionMessage('reserved');

        $this->actors->resolve($actor);
    }
}

final class NumericActor extends Model
{
    public function getMorphClass(): string
    {
        return 'admin';
    }
}

final class StringActor extends Model
{
    protected $keyType = 'string';

    public function getMorphClass(): string
    {
        return 'service-account';
    }
}

final class ReservedActor extends Model
{
    public function getMorphClass(): string
    {
        return SystemActor::TYPE;
    }
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations;

use HangTuah\GateTrail\Exceptions\InvalidActor;
use HangTuah\GateTrail\SystemActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @internal
 */
final class ActorIdentityResolver
{
    public function resolve(Model|SystemActor $actor): ActorIdentity
    {
        if ($actor instanceof SystemActor) {
            return new ActorIdentity(
                SystemActor::TYPE,
                $actor->name,
            );
        }

        $type = $actor->getMorphClass();

        if ($type === SystemActor::TYPE) {
            throw InvalidActor::reservedMorphAlias();
        }

        $this->validateValue($type, 'actor type');

        $key = $actor->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw InvalidActor::missingModelKey();
        }

        $id = (string) $key;
        $this->validateValue($id, 'actor ID');

        return new ActorIdentity($type, $id);
    }

    private function validateValue(string $value, string $label): void
    {
        if ($value === '') {
            throw InvalidActor::emptyValue($label);
        }

        if (Str::length($value) > 191) {
            throw InvalidActor::valueTooLong($label);
        }
    }
}

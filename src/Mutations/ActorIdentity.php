<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations;

final readonly class ActorIdentity
{
    public function __construct(
        public string $type,
        public string $id,
    ) {}
}

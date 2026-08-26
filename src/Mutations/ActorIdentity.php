<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations;

/**
 * @internal
 */
final readonly class ActorIdentity
{
    /**
     * @internal
     */
    public function __construct(
        public string $type,
        public string $id,
    ) {}
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Access;

/**
 * One normalized authorization request: a name, or an identifier.
 *
 * The distinction is the whole point. A bare string is **always** a name, even
 * when it looks like a number, and an identifier only ever arrives through a
 * reference, a native `int` or a configured model's key. Collapsing the two
 * would make `allows($subject, '007')` silently ask a different question from
 * the one the caller wrote.
 *
 * Exactly one of the two properties is non-null. There is no "both" case and
 * no "neither" case: a request that cannot correspond to any definition is not
 * represented here at all — the normalizer returns `null` for it, and the
 * caller denies before a snapshot is built.
 *
 * This carries no lifecycle rule, no guard rule and no query. It is the
 * finished question, not the answer.
 *
 * @internal
 *
 * @see docs/decisions/0012-effective-access-resolution.md
 */
final readonly class RequestedDefinition
{
    private function __construct(
        public ?string $name,
        public int|string|null $identifier,
    ) {}

    /**
     * @internal Package-controlled construction. Not supported consumer API.
     */
    public static function named(string $name): self
    {
        return new self($name, null);
    }

    /**
     * @internal Package-controlled construction. Not supported consumer API.
     */
    public static function identifiedBy(int|string $identifier): self
    {
        return new self(null, $identifier);
    }
}

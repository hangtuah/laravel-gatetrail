<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Subjects;

/**
 * One validated subject identity, in its canonical form.
 *
 * It carries the persisted morph type and one canonical unsigned decimal
 * identifier, and nothing else. No model, connection, attribute or other
 * mutable state is retained, so an identity can never drift from the values
 * that were validated when {@see SubjectIdentityResolver} produced it.
 *
 * The identifier is a native integer, so `7`, `'7'` and `'007'` are the same
 * subject and compare identically — including under `assertSame()` and `===`,
 * which a mixed `int|string` representation could not guarantee. The initial
 * runtime is bounded by `PHP_INT_MAX`; the resolver rejects anything larger
 * rather than saturating it.
 *
 * @internal
 *
 * @see docs/decisions/0012-effective-access-resolution.md
 */
final readonly class SubjectIdentity
{
    public function __construct(
        public string $type,
        public int $id,
    ) {}
}

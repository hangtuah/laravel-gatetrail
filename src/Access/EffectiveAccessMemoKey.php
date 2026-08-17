<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Access;

use DateTimeImmutable;
use HangTuah\GateTrail\Subjects\SubjectIdentity;

/**
 * The identity of one memoized evaluation.
 *
 * Four things, and exactly four:
 *
 * ```text
 * subject morph type + canonical subject id + guard + evaluated UTC second
 * ```
 *
 * **The evaluated second is what makes memoization correct rather than merely
 * fast.** Within one second a burst of checks shares one snapshot; when the
 * second changes the key changes, so a memo entry can never outlive a
 * lifecycle boundary. **The guard is never omitted**, because ADR 0003
 * requires it of every memoization key.
 *
 * The requested role or permission is deliberately absent. A snapshot answers
 * every question about one subject at one instant, so keying by the question
 * would store the same state once per distinct check.
 *
 * **The string form is length-prefixed, not concatenated.** The morph type is
 * a class name or a consumer-chosen morph alias and the guard is a
 * configuration string; both can contain any byte, including whatever
 * separator a naive key would pick. Plain concatenation lets `('a|b', 'c')`
 * and `('a', 'b|c')` produce one key and therefore one answer for two
 * different subjects. Prefixing each segment with its byte length makes the
 * encoding unambiguous, so distinct tuples cannot collide whatever they
 * contain.
 *
 * The identifier carries a type tag even though the canonical subject id is
 * currently a native `int`. That costs nothing now and means a later widening
 * to `int|string` cannot silently merge `7` and `'7'` into one entry.
 *
 * A key is an internal index. **It is never logged, never placed in an
 * exception message and never added to an event payload** — it contains a
 * subject identifier.
 *
 * @internal
 *
 * @see docs/decisions/0012-effective-access-resolution.md
 */
final readonly class EffectiveAccessMemoKey
{
    private function __construct(
        private string $value,
    ) {}

    /**
     * @internal Package-controlled construction. Not supported consumer API.
     *
     * @param  DateTimeImmutable  $evaluatedAt  already normalized to a UTC whole second
     */
    public static function for(
        SubjectIdentity $subject,
        string $guardName,
        DateTimeImmutable $evaluatedAt,
    ): self {
        return new self(implode('|', [
            self::segment($subject->type),
            self::segment('int:'.$subject->id),
            self::segment($guardName),
            // A Unix timestamp is already timezone-independent and whole
            // second, so the instant contributes one unambiguous integer.
            self::segment((string) $evaluatedAt->getTimestamp()),
        ]));
    }

    /**
     * @internal The opaque index. Not for logging, display or disclosure.
     */
    public function value(): string
    {
        return $this->value;
    }

    private static function segment(string $value): string
    {
        return strlen($value).':'.$value;
    }
}

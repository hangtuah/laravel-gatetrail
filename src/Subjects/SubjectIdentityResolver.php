<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Subjects;

use HangTuah\GateTrail\Exceptions\InvalidSubject;
use Illuminate\Database\Eloquent\Model;

/**
 * The one subject-identity rule in GateTrail.
 *
 * Every path that derives a subject identity uses this component: the managed
 * assignment mutations today, and effective access resolution once it exists.
 * That is not a tidiness preference. Two private copies previously lived in
 * the mutation handlers with *different* validation, so GateTrail could accept
 * a subject for one mutation that another would reject. A third copy inside a
 * resolver would let a read and a write disagree about who the subject is —
 * the one disagreement an authorization package cannot afford.
 *
 * Only the morph class and the primary key are read. Every other attribute is
 * ignored, so editing `$user->email` in memory cannot change an authorization
 * result or a persisted assignment. The key itself gets a stronger rule: a
 * dirty key is rejected rather than resolved, because silently preferring
 * either the in-memory or the persisted value could grant to the wrong
 * subject.
 *
 * The component is stateless and safe to bind as a singleton.
 *
 * @internal
 *
 * @see docs/decisions/0012-effective-access-resolution.md
 */
final class SubjectIdentityResolver
{
    /**
     * Validation order is fixed so that the same subject always fails for the
     * same reason on every path.
     */
    public function resolve(Model $subject): SubjectIdentity
    {
        if ($subject->exists !== true) {
            throw InvalidSubject::notPersisted($subject::class);
        }

        $type = $subject->getMorphClass();

        if (trim($type) === '') {
            throw InvalidSubject::invalidMorphType($subject::class);
        }

        $key = $subject->getKey();

        if ($key === null) {
            throw InvalidSubject::missingIdentifier($type);
        }

        if (! is_int($key) && ! is_string($key)) {
            throw InvalidSubject::unsupportedIdentifier($type);
        }

        if ($subject->isDirty($subject->getKeyName())) {
            throw InvalidSubject::mutatedIdentifier($type);
        }

        return new SubjectIdentity($type, $this->identifier($type, $key));
    }

    /**
     * Canonicalizes an unsigned decimal identifier to a native integer.
     *
     * A valid morph alias is never trimmed or rewritten, but the identifier is
     * canonicalized: `'007'` and `'7'` become `7`, so one subject cannot be
     * written under two identities.
     */
    private function identifier(string $type, int|string $key): int
    {
        if (is_int($key)) {
            if ($key < 0) {
                throw InvalidSubject::unsupportedIdentifier($type);
            }

            return $key;
        }

        // `\z` rather than `$`, because `$` also matches before a trailing
        // newline and would accept "42\n" as an identifier.
        if (preg_match('/^[0-9]+\z/', $key) !== 1) {
            throw InvalidSubject::unsupportedIdentifier($type);
        }

        $digits = ltrim($key, '0');

        if ($digits === '') {
            return 0;
        }

        if ($this->exceedsIntegerBoundary($digits)) {
            throw InvalidSubject::unsupportedIdentifier($type);
        }

        return (int) $digits;
    }

    /**
     * Compares digit strings rather than casting, because casting a value
     * above `PHP_INT_MAX` saturates to `PHP_INT_MAX` and would silently
     * resolve an out-of-range key to a real subject.
     *
     * @param  non-empty-string  $digits  canonical digits, no leading zeros
     */
    private function exceedsIntegerBoundary(string $digits): bool
    {
        $boundary = (string) PHP_INT_MAX;

        if (strlen($digits) !== strlen($boundary)) {
            return strlen($digits) > strlen($boundary);
        }

        return strcmp($digits, $boundary) > 0;
    }
}

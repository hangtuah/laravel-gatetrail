<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Exceptions;

use InvalidArgumentException;

/**
 * Reports a subject that cannot be resolved to a stable identity.
 *
 * It carries a safe type label and one term from a fixed vocabulary, and
 * nothing else. The rejected identifier is deliberately absent from the
 * message and from every public property: a subject key can be a customer
 * number, a national identifier or another externally meaningful value in a
 * consuming application, and an exception message is the single most likely
 * thing to reach a log aggregator.
 *
 * `getPrevious()` is always `null`, consistent with the posture ADR 0011
 * established for {@see ConcurrencyRetryExhausted}.
 *
 * @see docs/decisions/0012-effective-access-resolution.md
 */
final class InvalidSubject extends InvalidArgumentException
{
    public const REASON_NOT_PERSISTED = 'not_persisted';

    public const REASON_MISSING_IDENTIFIER = 'missing_identifier';

    public const REASON_UNSUPPORTED_IDENTIFIER = 'unsupported_identifier';

    public const REASON_MUTATED_IDENTIFIER = 'mutated_identifier';

    public const REASON_INVALID_MORPH_TYPE = 'invalid_morph_type';

    private function __construct(
        public readonly string $subjectType,
        public readonly string $reason,
    ) {
        parent::__construct(
            sprintf(
                'GateTrail could not resolve a valid subject identity for [%s]: [%s].',
                $subjectType,
                $reason,
            ),
        );
    }

    public static function notPersisted(string $subjectType): self
    {
        return new self($subjectType, self::REASON_NOT_PERSISTED);
    }

    public static function missingIdentifier(string $subjectType): self
    {
        return new self($subjectType, self::REASON_MISSING_IDENTIFIER);
    }

    /**
     * Covers an unsupported PHP key type and a key that is not an unsigned
     * decimal integer under the initial runtime — including a UUID or ULID.
     * It carries the reason, never the offending value.
     */
    public static function unsupportedIdentifier(string $subjectType): self
    {
        return new self($subjectType, self::REASON_UNSUPPORTED_IDENTIFIER);
    }

    public static function mutatedIdentifier(string $subjectType): self
    {
        return new self($subjectType, self::REASON_MUTATED_IDENTIFIER);
    }

    /**
     * The caller supplies a safe class label here, because by definition no
     * usable morph alias exists for this failure.
     */
    public static function invalidMorphType(string $subjectType): self
    {
        return new self($subjectType, self::REASON_INVALID_MORPH_TYPE);
    }
}

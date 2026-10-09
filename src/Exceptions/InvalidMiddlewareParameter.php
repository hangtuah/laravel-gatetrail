<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Exceptions;

use InvalidArgumentException;

/**
 * Reports an invalid GateTrail route-middleware declaration.
 *
 * The message identifies the stable alias and a fixed reason only. Permission
 * and role names are deliberately omitted so exception rendering cannot turn
 * route configuration into definition disclosure.
 */
final class InvalidMiddlewareParameter extends InvalidArgumentException
{
    public const REASON_EMPTY = 'empty_parameter';

    public const REASON_CARDINALITY = 'invalid_cardinality';

    private function __construct(
        public readonly string $alias,
        public readonly string $reason,
    ) {
        parent::__construct(sprintf(
            'GateTrail middleware [%s] has invalid parameters: [%s].',
            $alias,
            $reason,
        ));
    }

    public static function emptyParameter(string $alias): self
    {
        return new self($alias, self::REASON_EMPTY);
    }

    public static function invalidCardinality(string $alias): self
    {
        return new self($alias, self::REASON_CARDINALITY);
    }
}

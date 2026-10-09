<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Laravel\Middleware;

use HangTuah\GateTrail\Exceptions\InvalidMiddlewareParameter;

/** @internal */
final class MiddlewareParameterNormalizer
{
    /**
     * @param  list<string>  $parameters
     * @return non-empty-list<string>
     */
    public function normalize(
        string $alias,
        MiddlewareMatchMode $mode,
        array $parameters,
    ): array {
        $normalized = [];

        foreach ($parameters as $parameter) {
            $value = trim($parameter);

            if ($value === '') {
                throw InvalidMiddlewareParameter::emptyParameter($alias);
            }

            if (! in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }

        if ($normalized === []) {
            throw InvalidMiddlewareParameter::invalidCardinality($alias);
        }

        if ($mode === MiddlewareMatchMode::One && count($normalized) !== 1) {
            throw InvalidMiddlewareParameter::invalidCardinality($alias);
        }

        return $normalized;
    }
}

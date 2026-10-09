<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Laravel\Middleware;

/** @internal */
final class MiddlewareSetMatcher
{
    /**
     * @param  non-empty-list<string>  $requested
     * @param  list<string>  $effective
     */
    public function matches(
        MiddlewareMatchMode $mode,
        array $requested,
        array $effective,
    ): bool {
        if ($mode === MiddlewareMatchMode::One) {
            return in_array($requested[0], $effective, true);
        }

        foreach ($requested as $name) {
            $present = in_array($name, $effective, true);

            if ($mode === MiddlewareMatchMode::Any && $present) {
                return true;
            }

            if ($mode === MiddlewareMatchMode::All && ! $present) {
                return false;
            }
        }

        return $mode === MiddlewareMatchMode::All;
    }
}

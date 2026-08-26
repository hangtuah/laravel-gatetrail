<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Guards;

use HangTuah\GateTrail\Exceptions\InvalidGuard;
use Illuminate\Contracts\Config\Repository;

/**
 * @internal
 */
final readonly class DefaultGuardResolver
{
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * @param  string  $context  Safe diagnostic label supplied by the caller
     *                           and reported when no valid default guard exists.
     */
    public function resolve(string $context): string
    {
        $packageDefault = $this->config->get(
            'gatetrail.guards.default',
        );

        if ($packageDefault !== null) {
            return $this->normalize(
                $packageDefault,
                'package default',
                $context,
            );
        }

        return $this->normalize(
            $this->config->get('auth.defaults.guard'),
            'Laravel default',
            $context,
        );
    }

    private function normalize(
        mixed $value,
        string $source,
        string $context,
    ): string {
        if (! is_string($value) || trim($value) === '') {
            throw InvalidGuard::forContext($context, $source);
        }

        return trim($value);
    }
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Guards;

use HangTuah\GateTrail\Exceptions\InvalidGuard;
use Illuminate\Contracts\Config\Repository;

final readonly class DefaultGuardResolver
{
    public function __construct(
        private Repository $config,
    ) {}

    public function resolve(): string
    {
        $packageDefault = $this->config->get(
            'gatetrail.guards.default',
        );

        if ($packageDefault !== null) {
            return $this->normalize(
                $packageDefault,
                'package default',
            );
        }

        return $this->normalize(
            $this->config->get('auth.defaults.guard'),
            'Laravel default',
        );
    }

    private function normalize(mixed $value, string $source): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw InvalidGuard::forSubject(
                'role-permission assignment',
                $source,
            );
        }

        return trim($value);
    }
}

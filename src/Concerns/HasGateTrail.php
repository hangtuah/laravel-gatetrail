<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Concerns;

use ReflectionObject;

// Consumer models use this trait outside the package source tree.
// @phpstan-ignore trait.unused
trait HasGateTrail
{
    /**
     * @internal
     *
     * @return array{defined: bool, value: mixed}
     */
    final public function gateTrailGuardNameFromDeclaredProperty(): array
    {
        $reflection = new ReflectionObject($this);

        if (! $reflection->hasProperty('gateTrailGuardName')) {
            return ['defined' => false, 'value' => null];
        }

        $property = $reflection->getProperty('gateTrailGuardName');

        if ($property->isPrivate()) {
            return ['defined' => false, 'value' => null];
        }

        return [
            'defined' => true,
            'value' => $property->getValue($this),
        ];
    }
}

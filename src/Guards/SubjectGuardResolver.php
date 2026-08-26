<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Guards;

use HangTuah\GateTrail\Concerns\HasGateTrail;
use HangTuah\GateTrail\Exceptions\InvalidGuard;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use ReflectionMethod;

/**
 * @internal
 */
final readonly class SubjectGuardResolver
{
    public function __construct(
        private Repository $config,
    ) {}

    public function resolve(Model $subject): string
    {
        if (method_exists($subject, 'gateTrailGuardName')) {
            $method = new ReflectionMethod($subject, 'gateTrailGuardName');

            if (! $method->isPublic() || $method->getNumberOfRequiredParameters() !== 0) {
                throw InvalidGuard::forSubject(
                    $subject::class,
                    'subject method',
                );
            }

            return $this->normalize(
                $method->invoke($subject),
                $subject,
                'subject method',
            );
        }

        if (in_array(HasGateTrail::class, class_uses_recursive($subject), true)) {
            $accessor = new ReflectionMethod(
                $subject,
                'gateTrailGuardNameFromDeclaredProperty',
            );

            /** @var array{defined: bool, value: mixed} $property */
            $property = $accessor->invoke($subject);

            if ($property['defined']) {
                return $this->normalize(
                    $property['value'],
                    $subject,
                    'subject property',
                );
            }
        }

        $subjects = $this->config->get('gatetrail.guards.subjects', []);

        if (
            is_array($subjects)
            && array_key_exists($subject::class, $subjects)
        ) {
            return $this->normalize(
                $subjects[$subject::class],
                $subject,
                'subject configuration',
            );
        }

        $packageDefault = $this->config->get('gatetrail.guards.default');

        if ($packageDefault !== null) {
            return $this->normalize(
                $packageDefault,
                $subject,
                'package default',
            );
        }

        return $this->normalize(
            $this->config->get('auth.defaults.guard'),
            $subject,
            'Laravel default',
        );
    }

    private function normalize(
        mixed $value,
        Model $subject,
        string $source,
    ): string {
        if (! is_string($value) || trim($value) === '') {
            throw InvalidGuard::forSubject($subject::class, $source);
        }

        return trim($value);
    }
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Audit;

use HangTuah\GateTrail\Exceptions\InvalidAuditConfiguration;
use Illuminate\Contracts\Config\Repository;

/**
 * Selects the configured audit mode, failing closed on anything malformed.
 *
 * Both flags must be real booleans. A missing key, `null`, `"true"`, `"false"`,
 * `0`, `1`, an array, or an object is rejected rather than coerced, because
 * every plausible coercion silently weakens a security control: truthy
 * coercion would turn an unset flag into strict, and falsy coercion would turn
 * a typo into non-strict or disabled auditing.
 *
 * The mode is read per mutation rather than cached, so a consumer that changes
 * configuration between mutations gets the mode it asked for.
 *
 * @internal
 */
final readonly class AuditModeResolver
{
    public function __construct(
        private Repository $config,
    ) {}

    public function resolve(): AuditMode
    {
        if (! $this->flag('enabled')) {
            return AuditMode::Disabled;
        }

        return $this->flag('strict')
            ? AuditMode::Strict
            : AuditMode::NonStrict;
    }

    private function flag(string $name): bool
    {
        $key = 'gatetrail.audit.'.$name;
        $value = $this->config->get($key);

        if (! is_bool($value)) {
            throw InvalidAuditConfiguration::forKey($key);
        }

        return $value;
    }
}

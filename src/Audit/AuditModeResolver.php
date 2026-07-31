<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Audit;

use Illuminate\Contracts\Config\Repository;

/**
 * Selects the configured audit mode.
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
        if ($this->config->get('gatetrail.audit.enabled') !== true) {
            return AuditMode::Disabled;
        }

        return $this->config->get('gatetrail.audit.strict') === true
            ? AuditMode::Strict
            : AuditMode::NonStrict;
    }
}

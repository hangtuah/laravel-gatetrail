<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Audit;

use HangTuah\GateTrail\Exceptions\InvalidAuditMetadata;
use Illuminate\Contracts\Config\Repository;

/**
 * @internal
 */
final readonly class SensitiveAuditValueRedactor
{
    public function __construct(
        private Repository $config,
    ) {}

    public function assignmentReason(
        ?string $value,
        string $snapshot,
    ): ?string {
        if (! in_array($snapshot, ['before', 'after'], true)) {
            throw InvalidAuditMetadata::invalidSensitivePath();
        }

        return $this->redact($value, [
            'assignment_reason',
            $snapshot.'.assignment_reason',
        ]);
    }

    public function auditReason(?string $value): ?string
    {
        return $this->redact($value, [
            'reason',
            'audit.reason',
        ]);
    }

    /**
     * @param  list<string>  $acceptedPaths
     */
    private function redact(
        ?string $value,
        array $acceptedPaths,
    ): ?string {
        if ($value === null) {
            return null;
        }

        $configured = $this->config->get(
            'gatetrail.sensitive_fields',
            [],
        );

        if (! is_array($configured)) {
            throw InvalidAuditMetadata::invalidSensitivePath();
        }

        foreach ($configured as $path) {
            if (! is_string($path) || trim($path) === '') {
                throw InvalidAuditMetadata::invalidSensitivePath();
            }

            if (in_array(trim($path), $acceptedPaths, true)) {
                return SensitiveMetadataRedactor::MARKER;
            }
        }

        return $value;
    }
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Audit;

use HangTuah\GateTrail\Exceptions\InvalidAuditMetadata;

final class SensitiveMetadataRedactor
{
    public const MARKER = '__gatetrail_redacted__';

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<mixed>  $paths
     * @return array<string, mixed>
     */
    public function redact(array $metadata, array $paths): array
    {
        foreach ($paths as $path) {
            if (! is_string($path) || trim($path) === '') {
                throw InvalidAuditMetadata::invalidSensitivePath();
            }

            $path = trim($path);

            if (str_starts_with($path, 'metadata.')) {
                $path = substr($path, strlen('metadata.'));
            }

            $segments = explode('.', $path);

            if (
                $path === ''
                || in_array('', $segments, true)
            ) {
                throw InvalidAuditMetadata::invalidSensitivePath();
            }

            if (count($segments) === 1) {
                $this->redactMatchingKey($metadata, $segments[0]);

                continue;
            }

            $this->redactExactPath($metadata, $segments);
        }

        /** @var array<string, mixed> $metadata */
        return $metadata;
    }

    /**
     * @param  array<mixed>  $values
     */
    private function redactMatchingKey(
        array &$values,
        string $sensitiveKey,
    ): void {
        foreach ($values as $key => &$value) {
            if (is_string($key) && $key === $sensitiveKey) {
                $value = self::MARKER;

                continue;
            }

            if (is_array($value)) {
                $this->redactMatchingKey($value, $sensitiveKey);
            }
        }

        unset($value);
    }

    /**
     * @param  array<mixed>  $values
     * @param  non-empty-list<string>  $segments
     */
    private function redactExactPath(
        array &$values,
        array $segments,
    ): void {
        $segment = array_shift($segments);

        if (! array_key_exists($segment, $values)) {
            return;
        }

        if ($segments === []) {
            $values[$segment] = self::MARKER;

            return;
        }

        if (! is_array($values[$segment])) {
            return;
        }

        $this->redactExactPath($values[$segment], $segments);
    }
}

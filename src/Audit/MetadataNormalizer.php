<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Audit;

use HangTuah\GateTrail\Exceptions\InvalidAuditMetadata;
use Illuminate\Contracts\Config\Repository;
use JsonException;
use stdClass;

final readonly class MetadataNormalizer
{
    public function __construct(
        private Repository $config,
        private SensitiveMetadataRedactor $redactor,
    ) {}

    /**
     * @param  array<mixed>  $metadata
     */
    public function normalize(array $metadata): NormalizedMetadata
    {
        if ($metadata !== [] && array_is_list($metadata)) {
            throw InvalidAuditMetadata::topLevelMustBeObject();
        }

        $maxBytes = $this->positiveIntegerConfig(
            'gatetrail.audit.metadata.max_bytes',
        );
        $maxDepth = $this->positiveIntegerConfig(
            'gatetrail.audit.metadata.max_depth',
        );
        $topLevel = $metadata === [] ? new stdClass : $metadata;
        $encoded = $this->encode($topLevel, $maxDepth);

        if (strlen($encoded) > $maxBytes) {
            throw InvalidAuditMetadata::tooLarge($maxBytes);
        }

        /** @var array<string, mixed> $normalized */
        $normalized = $this->normalizeValue(
            $metadata,
            $maxDepth,
            1,
            true,
        );
        $sensitivePaths = $this->config->get(
            'gatetrail.sensitive_fields',
            [],
        );

        if (! is_array($sensitivePaths)) {
            throw InvalidAuditMetadata::invalidSensitivePath();
        }

        $redacted = $this->redactor->redact(
            $normalized,
            $sensitivePaths,
        );
        $value = $redacted === [] ? new stdClass : $redacted;

        return new NormalizedMetadata(
            $value,
            $this->encode($value, $maxDepth),
        );
    }

    /**
     * @return int<1, max>
     */
    private function positiveIntegerConfig(string $key): int
    {
        $value = $this->config->get($key);

        if (! is_int($value) || $value < 1) {
            throw InvalidAuditMetadata::invalidLimit($key);
        }

        return $value;
    }

    /**
     * @param  array<mixed>|stdClass  $value
     * @param  int<1, max>  $maxDepth
     */
    private function encode(
        array|stdClass $value,
        int $maxDepth,
    ): string {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE,
                $maxDepth,
            );
        } catch (JsonException $exception) {
            throw InvalidAuditMetadata::notJsonSafe($exception);
        }
    }

    private function normalizeValue(
        mixed $value,
        int $maxDepth,
        int $depth,
        bool $topLevel = false,
    ): mixed {
        if ($depth > $maxDepth) {
            throw InvalidAuditMetadata::notJsonSafe();
        }

        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw InvalidAuditMetadata::notJsonSafe();
            }

            return $value;
        }

        if (is_string($value)) {
            if (preg_match('//u', $value) !== 1) {
                throw InvalidAuditMetadata::notJsonSafe();
            }

            if ($value === SensitiveMetadataRedactor::MARKER) {
                throw InvalidAuditMetadata::reservedMarker();
            }

            return $value;
        }

        if (! is_array($value)) {
            throw InvalidAuditMetadata::notJsonSafe();
        }

        $isList = array_is_list($value);

        if ($topLevel && $value !== [] && $isList) {
            throw InvalidAuditMetadata::topLevelMustBeObject();
        }

        if (! $isList) {
            foreach (array_keys($value) as $key) {
                if (! is_string($key)) {
                    throw InvalidAuditMetadata::notJsonSafe();
                }
            }
        }

        $normalized = [];

        foreach ($value as $key => $item) {
            $normalized[$key] = $this->normalizeValue(
                $item,
                $maxDepth,
                $depth + 1,
            );
        }

        return $normalized;
    }
}

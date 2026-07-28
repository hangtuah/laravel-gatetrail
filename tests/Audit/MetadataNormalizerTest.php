<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Audit;

use HangTuah\GateTrail\Audit\MetadataNormalizer;
use HangTuah\GateTrail\Audit\SensitiveMetadataRedactor;
use HangTuah\GateTrail\Exceptions\InvalidAuditMetadata;
use HangTuah\GateTrail\Tests\TestCase;
use stdClass;

final class MetadataNormalizerTest extends TestCase
{
    public function test_empty_metadata_becomes_an_empty_json_object(): void
    {
        $metadata = app(MetadataNormalizer::class)->normalize([]);

        $this->assertInstanceOf(stdClass::class, $metadata->value);
        $this->assertSame('{}', $metadata->json);
    }

    public function test_associative_metadata_and_nested_lists_are_preserved(): void
    {
        $metadata = app(MetadataNormalizer::class)->normalize([
            'source' => 'staff-system',
            'attempts' => 2,
            'successful' => true,
            'ratios' => [1.0, 0.5],
        ]);

        $this->assertSame([
            'source' => 'staff-system',
            'attempts' => 2,
            'successful' => true,
            'ratios' => [1.0, 0.5],
        ], $metadata->value);
        $this->assertSame(
            '{"source":"staff-system","attempts":2,"successful":true,"ratios":[1.0,0.5]}',
            $metadata->json,
        );
    }

    public function test_non_empty_top_level_list_is_rejected(): void
    {
        $this->expectException(InvalidAuditMetadata::class);
        $this->expectExceptionMessage('top-level associative object');

        app(MetadataNormalizer::class)->normalize(['one', 'two']);
    }

    public function test_php_object_is_rejected(): void
    {
        $this->expectException(InvalidAuditMetadata::class);
        $this->expectExceptionMessage('not JSON-safe');

        app(MetadataNormalizer::class)->normalize([
            'payload' => new stdClass,
        ]);
    }

    public function test_non_finite_number_is_rejected(): void
    {
        $this->expectException(InvalidAuditMetadata::class);
        $this->expectExceptionMessage('not JSON-safe');

        app(MetadataNormalizer::class)->normalize([
            'ratio' => INF,
        ]);
    }

    public function test_invalid_utf8_is_rejected(): void
    {
        $this->expectException(InvalidAuditMetadata::class);
        $this->expectExceptionMessage('not JSON-safe');

        app(MetadataNormalizer::class)->normalize([
            'text' => "\xB1\x31",
        ]);
    }

    public function test_recursive_array_is_rejected(): void
    {
        $recursive = [];
        $recursive['self'] = &$recursive;

        $this->expectException(InvalidAuditMetadata::class);
        $this->expectExceptionMessage('not JSON-safe');

        app(MetadataNormalizer::class)->normalize([
            'payload' => $recursive,
        ]);
    }

    public function test_reserved_redaction_marker_is_rejected(): void
    {
        $this->expectException(InvalidAuditMetadata::class);
        $this->expectExceptionMessage('reserved redaction marker');

        app(MetadataNormalizer::class)->normalize([
            'value' => SensitiveMetadataRedactor::MARKER,
        ]);
    }

    public function test_configured_depth_limit_is_enforced(): void
    {
        config()->set('gatetrail.audit.metadata.max_depth', 3);

        $this->expectException(InvalidAuditMetadata::class);

        app(MetadataNormalizer::class)->normalize([
            'one' => [
                'two' => [
                    'three' => 'too-deep',
                ],
            ],
        ]);
    }

    public function test_size_is_checked_before_sensitive_redaction(): void
    {
        config()->set('gatetrail.audit.metadata.max_bytes', 40);
        config()->set('gatetrail.sensitive_fields', ['password']);

        $this->expectException(InvalidAuditMetadata::class);
        $this->expectExceptionMessage('40-byte limit');

        app(MetadataNormalizer::class)->normalize([
            'password' => str_repeat('s', 100),
        ]);
    }

    public function test_invalid_limits_fail_closed(): void
    {
        config()->set('gatetrail.audit.metadata.max_depth', 0);

        $this->expectException(InvalidAuditMetadata::class);
        $this->expectExceptionMessage('positive integer');

        app(MetadataNormalizer::class)->normalize([]);
    }
}

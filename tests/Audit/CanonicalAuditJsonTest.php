<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Audit;

use DateTimeImmutable;
use DateTimeZone;
use HangTuah\GateTrail\Audit\CanonicalAuditJson;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CanonicalAuditJsonTest extends TestCase
{
    private CanonicalAuditJson $json;

    protected function setUp(): void
    {
        parent::setUp();

        $this->json = new CanonicalAuditJson;
    }

    public function test_object_preserves_key_order_and_json_value_types(): void
    {
        $encoded = $this->json->object([
            'second' => 1.0,
            'first' => true,
            'path' => 'dokumen/semakan',
            'label' => 'Penyemak ✓',
        ]);

        $this->assertSame(
            '{"second":1.0,"first":true,"path":"dokumen/semakan","label":"Penyemak ✓"}',
            $encoded,
        );
    }

    public function test_empty_values_encode_as_an_object(): void
    {
        $this->assertSame('{}', $this->json->object([]));
    }

    public function test_identifier_preserves_large_values_and_normalizes_zeroes(): void
    {
        $this->assertSame(
            '9223372036854775808',
            $this->json->identifier(
                '9223372036854775808',
                'id',
            ),
        );
        $this->assertSame(
            '42',
            $this->json->identifier('00042', 'id'),
        );
        $this->assertSame(
            '0',
            $this->json->identifier('000', 'id'),
        );
    }

    public function test_invalid_identifier_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsigned decimal identifier');

        $this->json->identifier('42.5', 'id');
    }

    public function test_timestamp_is_utc_with_microseconds(): void
    {
        $timestamp = new DateTimeImmutable(
            '2026-07-29 20:15:30.123456',
            new DateTimeZone('Asia/Kuala_Lumpur'),
        );

        $this->assertSame(
            '2026-07-29T12:15:30.123456Z',
            $this->json->timestamp($timestamp, 'assigned_at'),
        );
    }
}

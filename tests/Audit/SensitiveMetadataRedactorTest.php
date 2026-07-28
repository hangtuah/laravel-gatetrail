<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Audit;

use HangTuah\GateTrail\Audit\SensitiveMetadataRedactor;
use HangTuah\GateTrail\Exceptions\InvalidAuditMetadata;
use PHPUnit\Framework\TestCase;

final class SensitiveMetadataRedactorTest extends TestCase
{
    private SensitiveMetadataRedactor $redactor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redactor = new SensitiveMetadataRedactor;
    }

    public function test_bare_key_redacts_every_matching_nested_value(): void
    {
        $redacted = $this->redactor->redact([
            'password' => 'top-secret',
            'nested' => [
                'password' => 'nested-secret',
                'visible' => true,
            ],
        ], ['password']);

        $this->assertSame([
            'password' => SensitiveMetadataRedactor::MARKER,
            'nested' => [
                'password' => SensitiveMetadataRedactor::MARKER,
                'visible' => true,
            ],
        ], $redacted);
    }

    public function test_dotted_path_redacts_only_the_exact_location(): void
    {
        $redacted = $this->redactor->redact([
            'customer' => [
                'access_token' => 'customer-secret',
            ],
            'access_token' => 'visible-at-root',
        ], ['customer.access_token']);

        $this->assertSame([
            'customer' => [
                'access_token' => SensitiveMetadataRedactor::MARKER,
            ],
            'access_token' => 'visible-at-root',
        ], $redacted);
    }

    public function test_metadata_prefix_is_optional(): void
    {
        $redacted = $this->redactor->redact([
            'credentials' => [
                'private_key' => 'secret',
            ],
        ], ['metadata.credentials.private_key']);

        $this->assertSame(
            SensitiveMetadataRedactor::MARKER,
            $redacted['credentials']['private_key'],
        );
    }

    public function test_absent_path_remains_absent(): void
    {
        $redacted = $this->redactor->redact(
            ['visible' => true],
            ['missing.value'],
        );

        $this->assertSame(['visible' => true], $redacted);
        $this->assertArrayNotHasKey('missing', $redacted);
    }

    public function test_invalid_sensitive_path_is_rejected(): void
    {
        $this->expectException(InvalidAuditMetadata::class);
        $this->expectExceptionMessage('non-empty strings');

        $this->redactor->redact([], ['metadata.']);
    }
}

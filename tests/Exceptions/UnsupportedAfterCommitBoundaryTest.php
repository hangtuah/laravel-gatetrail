<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Exceptions;

use HangTuah\GateTrail\Exceptions\UnsupportedAfterCommitBoundary;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class UnsupportedAfterCommitBoundaryTest extends TestCase
{
    public function test_a_named_connection_is_reported_safely(): void
    {
        $exception = UnsupportedAfterCommitBoundary::forConnection('testing');

        $this->assertSame('testing', $exception->connection);
        $this->assertSame(
            'GateTrail connection [testing] cannot defer after-commit work until its outermost commit.',
            $exception->getMessage(),
        );
    }

    public function test_an_unnamed_connection_reports_no_invented_name(): void
    {
        $exception = UnsupportedAfterCommitBoundary::forConnection(null);

        $this->assertNull($exception->connection);
        $this->assertSame(
            'The GateTrail connection cannot defer after-commit work until its outermost commit.',
            $exception->getMessage(),
        );
    }

    public function test_the_message_no_longer_claims_to_be_audit_specific(): void
    {
        // The boundary now protects deferred audit persistence, the reserved
        // cache invalidation, and the mutation event, in every audit mode.
        foreach ([null, 'testing'] as $connection) {
            $message = UnsupportedAfterCommitBoundary::forConnection(
                $connection,
            )->getMessage();

            $this->assertStringNotContainsString('non-strict', $message);
            $this->assertStringNotContainsString('audit', $message);
            $this->assertStringContainsString(
                'after-commit work',
                $message,
            );
        }
    }

    public function test_the_exception_keeps_its_safe_construction_contract(): void
    {
        $reflection = new ReflectionClass(
            UnsupportedAfterCommitBoundary::class,
        );

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue(
            $reflection->getConstructor()?->isPrivate() ?? false,
        );
        $this->assertNull(
            UnsupportedAfterCommitBoundary::forConnection('testing')
                ->getPrevious(),
        );
    }
}

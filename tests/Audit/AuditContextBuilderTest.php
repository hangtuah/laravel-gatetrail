<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Audit;

use HangTuah\GateTrail\Audit\AuditContextBuilder;
use HangTuah\GateTrail\Audit\MetadataNormalizer;
use HangTuah\GateTrail\Tests\TestCase;
use InvalidArgumentException;

final class AuditContextBuilderTest extends TestCase
{
    public function test_role_assignment_context_has_exact_version_one_shape(): void
    {
        $metadata = app(MetadataNormalizer::class)->normalize([
            'ratio' => 1.0,
            'path' => 'dokumen/semakan',
        ]);

        $context = app(AuditContextBuilder::class)->roleAssignment(
            'web',
            'reviewer',
            $metadata,
        );

        $this->assertSame(
            '{"schema_version":1,"guard_name":"web",'
            .'"role_name":"reviewer",'
            .'"metadata":{"ratio":1.0,"path":"dokumen/semakan"}}',
            $context,
        );
    }

    public function test_empty_metadata_remains_an_object(): void
    {
        $metadata = app(MetadataNormalizer::class)->normalize([]);

        $context = app(AuditContextBuilder::class)->roleAssignment(
            'web',
            'reviewer',
            $metadata,
        );

        $this->assertSame(
            '{"schema_version":1,"guard_name":"web",'
            .'"role_name":"reviewer","metadata":{}}',
            $context,
        );
    }

    public function test_empty_context_name_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('guard name cannot be empty');

        app(AuditContextBuilder::class)->roleAssignment(
            '',
            'reviewer',
            app(MetadataNormalizer::class)->normalize([]),
        );
    }

    public function test_role_permission_context_has_both_natural_names(): void
    {
        $context = app(AuditContextBuilder::class)
            ->rolePermissionAssignment(
                'web',
                'reviewer',
                'documents.approve',
                app(MetadataNormalizer::class)->normalize([
                    'ticket' => 'SEC-42',
                ]),
            );

        $this->assertSame(
            '{"schema_version":1,"guard_name":"web",'
            .'"role_name":"reviewer",'
            .'"permission_name":"documents.approve",'
            .'"metadata":{"ticket":"SEC-42"}}',
            $context,
        );
    }
}

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

    public function test_role_definition_context_has_exact_version_one_shape(): void
    {
        $context = app(AuditContextBuilder::class)->roleDefinition(
            'web',
            'reviewer',
            app(MetadataNormalizer::class)->normalize([
                'ticket' => 'SEC-42',
            ]),
        );

        $this->assertSame(
            '{"schema_version":1,"guard_name":"web",'
            .'"role_name":"reviewer",'
            .'"metadata":{"ticket":"SEC-42"}}',
            $context,
        );
    }

    public function test_empty_role_definition_name_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('role name cannot be empty');

        app(AuditContextBuilder::class)->roleDefinition(
            'web',
            '',
            app(MetadataNormalizer::class)->normalize([]),
        );
    }

    public function test_permission_definition_context_has_exact_version_one_shape(): void
    {
        $context = app(AuditContextBuilder::class)->permissionDefinition(
            'web',
            'documents.approve',
            app(MetadataNormalizer::class)->normalize([
                'ticket' => 'SEC-42',
            ]),
        );

        $this->assertSame(
            '{"schema_version":1,"guard_name":"web",'
            .'"permission_name":"documents.approve",'
            .'"metadata":{"ticket":"SEC-42"}}',
            $context,
        );
    }

    public function test_empty_permission_definition_metadata_remains_an_object(): void
    {
        $context = app(AuditContextBuilder::class)->permissionDefinition(
            'web',
            'documents.approve',
            app(MetadataNormalizer::class)->normalize([]),
        );

        $this->assertSame(
            '{"schema_version":1,"guard_name":"web",'
            .'"permission_name":"documents.approve","metadata":{}}',
            $context,
        );
    }

    public function test_empty_permission_definition_guard_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('guard name cannot be empty');

        app(AuditContextBuilder::class)->permissionDefinition(
            '',
            'documents.approve',
            app(MetadataNormalizer::class)->normalize([]),
        );
    }

    public function test_empty_permission_definition_name_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('permission name cannot be empty');

        app(AuditContextBuilder::class)->permissionDefinition(
            'web',
            '',
            app(MetadataNormalizer::class)->normalize([]),
        );
    }

    public function test_permission_definition_context_carries_no_role_name(): void
    {
        $context = app(AuditContextBuilder::class)->permissionDefinition(
            'web',
            'documents.approve',
            app(MetadataNormalizer::class)->normalize([]),
        );

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($context, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(
            ['schema_version', 'guard_name', 'permission_name', 'metadata'],
            array_keys($decoded),
        );
        $this->assertArrayNotHasKey('role_name', $decoded);
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

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Assignments;

use HangTuah\GateTrail\Assignments\AssignmentKind;
use HangTuah\GateTrail\Assignments\AssignmentScope;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AssignmentScopeTest extends TestCase
{
    public function test_role_scope_has_a_stable_golden_vector(): void
    {
        $scope = AssignmentScope::forRole('user', 42, 7);
        $key = 'v1|4:role|12:subject_type|4:user|10:subject_id|2:42|7:role_id|1:7';

        $this->assertSame(AssignmentKind::Role, $scope->kind());
        $this->assertSame($key, $scope->key());
        $this->assertSame(hash('sha256', $key), $scope->hash());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $scope->hash());
    }

    public function test_permission_scope_has_a_stable_golden_vector(): void
    {
        $scope = AssignmentScope::forPermission('service|account', '0042', 9);
        $key = 'v1|10:permission|12:subject_type|15:service|account|10:subject_id|2:42|13:permission_id|1:9';

        $this->assertSame(AssignmentKind::Permission, $scope->kind());
        $this->assertSame($key, $scope->key());
        $this->assertSame(hash('sha256', $key), $scope->hash());
    }

    public function test_role_permission_scope_has_a_stable_golden_vector(): void
    {
        $scope = AssignmentScope::forRolePermission(7, '0009');
        $key = 'v1|15:role_permission|7:role_id|1:7|13:permission_id|1:9';

        $this->assertSame(AssignmentKind::RolePermission, $scope->kind());
        $this->assertSame($key, $scope->key());
        $this->assertSame(hash('sha256', $key), $scope->hash());
    }

    public function test_empty_subject_type_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('subject type cannot be empty');

        AssignmentScope::forRole('', 42, 7);
    }

    public function test_non_numeric_identifier_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'subject ID must be an unsigned decimal integer',
        );

        AssignmentScope::forPermission('user', '42a', 9);
    }
}

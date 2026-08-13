<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Assignments;

use HangTuah\GateTrail\Assignments\AssignmentKind;
use HangTuah\GateTrail\Assignments\AssignmentScope;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssignmentScopeTest extends TestCase
{
    /**
     * Every identifier position on every factory, each paired with the label
     * the failure must report.
     *
     * @return array<string, array{callable(string): AssignmentScope, string}>
     */
    public static function identifierPositions(): array
    {
        return [
            'role scope subject ID' => [
                static fn (string $id): AssignmentScope => AssignmentScope::forRole('user', $id, 7),
                'subject ID',
            ],
            'role scope role ID' => [
                static fn (string $id): AssignmentScope => AssignmentScope::forRole('user', 42, $id),
                'role ID',
            ],
            'permission scope subject ID' => [
                static fn (string $id): AssignmentScope => AssignmentScope::forPermission('user', $id, 9),
                'subject ID',
            ],
            'permission scope permission ID' => [
                static fn (string $id): AssignmentScope => AssignmentScope::forPermission('user', 42, $id),
                'permission ID',
            ],
            'role-permission scope role ID' => [
                static fn (string $id): AssignmentScope => AssignmentScope::forRolePermission($id, 9),
                'role ID',
            ],
            'role-permission scope permission ID' => [
                static fn (string $id): AssignmentScope => AssignmentScope::forRolePermission(7, $id),
                'permission ID',
            ],
        ];
    }

    /**
     * Only a bare trailing `"\n"` was ever accepted — PCRE's `$` matches
     * before one final newline and nothing else. The remaining cases are
     * regression guards proving no line ending of any shape is treated as
     * part of an identifier.
     *
     * @return array<string, array{string}>
     */
    public static function trailingLineEndings(): array
    {
        return [
            'line feed' => ["42\n"],
            'carriage return line feed' => ["42\r\n"],
            'carriage return' => ["42\r"],
            'repeated line feed' => ["42\n\n"],
            'leading line feed' => ["\n42"],
            'embedded line feed' => ["4\n2"],
            'line feed only' => ["\n"],
        ];
    }

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

    /**
     * @param  callable(string): AssignmentScope  $factory
     */
    #[DataProvider('identifierPositions')]
    public function test_a_trailing_newline_identifier_is_rejected(
        callable $factory,
        string $label,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            sprintf('%s must be an unsigned decimal integer', $label),
        );

        $factory("42\n");
    }

    /**
     * @param  callable(string): AssignmentScope  $factory
     */
    #[DataProvider('identifierPositions')]
    public function test_a_trailing_carriage_return_line_feed_is_rejected(
        callable $factory,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        $factory("42\r\n");
    }

    #[DataProvider('trailingLineEndings')]
    public function test_no_line_ending_is_accepted_in_an_identifier(
        string $identifier,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        AssignmentScope::forRole('user', $identifier, 7);
    }

    /**
     * The correction narrows the accepted set only. Valid identifiers, their
     * canonical form and the resulting key must be untouched.
     */
    public function test_valid_identifiers_are_unaffected_by_the_stricter_anchor(): void
    {
        $scope = AssignmentScope::forRole('user', '0042', '7');

        $this->assertSame(
            'v1|4:role|12:subject_type|4:user|10:subject_id|2:42|7:role_id|1:7',
            $scope->key(),
        );
        $this->assertSame(
            AssignmentScope::forRole('user', 42, 7)->hash(),
            $scope->hash(),
        );
        $this->assertSame(
            '0',
            AssignmentScope::forRole('user', '000', 7)->components()['subject_id'],
        );
    }
}

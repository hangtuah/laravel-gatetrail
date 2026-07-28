<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Database;

use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class ConstraintTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);
    }

    public function test_role_names_are_unique_by_exact_name_and_guard(): void
    {
        $this->insertRole('Administrator');
        $this->insertRole('administrator');

        $this->expectException(QueryException::class);

        $this->insertRole('Administrator');
    }

    public function test_invalid_assignment_period_is_rejected(): void
    {
        $roleId = $this->insertRole('reviewer');

        $this->expectException(QueryException::class);

        DB::table('acl_role_assignments')->insert(
            $this->roleAssignment($roleId, [
                'starts_at' => '2026-07-29 10:00:00',
                'expires_at' => '2026-07-29 09:59:59',
            ]),
        );
    }

    public function test_partial_revocation_tuple_is_rejected(): void
    {
        $roleId = $this->insertRole('reviewer');

        $this->expectException(QueryException::class);

        DB::table('acl_role_assignments')->insert(
            $this->roleAssignment($roleId, [
                'revoked_at' => '2026-07-29 12:00:00',
                'revoked_by_type' => null,
                'revoked_by_id' => null,
            ]),
        );
    }

    public function test_role_delete_does_not_cascade_assignment_history(): void
    {
        $roleId = $this->insertRole('reviewer');

        DB::table('acl_role_assignments')->insert(
            $this->roleAssignment($roleId),
        );

        try {
            DB::table('acl_roles')->where('id', $roleId)->delete();
            $this->fail('Expected the role foreign key to prevent deletion.');
        } catch (QueryException) {
            $this->assertSame(
                1,
                DB::table('acl_role_assignments')->count(),
            );
        }
    }

    public function test_partial_audit_subject_pair_is_rejected(): void
    {
        $this->expectException(QueryException::class);

        DB::table('acl_access_audits')->insert([
            'event' => 'role.created',
            'actor_type' => 'gatetrail.system',
            'actor_id' => 'test-suite',
            'subject_type' => 'user',
            'subject_id' => null,
            'target_type' => 'role',
            'target_id' => 1,
            'created_at' => '2026-07-29 12:00:00',
        ]);
    }

    private function insertRole(string $name): int
    {
        return (int) DB::table('acl_roles')->insertGetId([
            'name' => $name,
            'guard_name' => 'web',
            'description' => null,
            'created_at' => '2026-07-29 08:00:00',
            'updated_at' => '2026-07-29 08:00:00',
            'deleted_at' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function roleAssignment(int $roleId, array $overrides = []): array
    {
        return array_replace([
            'subject_type' => 'user',
            'subject_id' => 42,
            'role_id' => $roleId,
            'assigned_at' => '2026-07-29 08:00:00',
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'test-suite',
            'assignment_reason' => 'Test assignment',
            'starts_at' => null,
            'expires_at' => null,
            'revoked_at' => null,
            'revoked_by_type' => null,
            'revoked_by_id' => null,
            'revocation_reason' => null,
            'created_at' => '2026-07-29 08:00:00',
            'updated_at' => '2026-07-29 08:00:00',
        ], $overrides);
    }
}

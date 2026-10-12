<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Diagnostics;

use Carbon\CarbonImmutable;
use HangTuah\GateTrail\Assignments\AssignmentScope;
use HangTuah\GateTrail\Diagnostics\AssignmentDataScanner;
use HangTuah\GateTrail\Diagnostics\DiagnosticFinding;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\PermissionAssignment;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\Models\RoleAssignment;
use HangTuah\GateTrail\Models\RolePermission;
use HangTuah\GateTrail\Support\ModelRegistry;
use HangTuah\GateTrail\Tests\Concerns\AssertsReadOnlySql;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

final class AssignmentDataScannerTest extends TestCase
{
    use AssertsReadOnlySql;

    private CarbonImmutable $now;

    private Role $role;

    private Permission $permission;

    private TestSubject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);

        $this->now = CarbonImmutable::parse('2026-10-12 12:00:00');
        $this->role = Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'web',
        ]);
        $this->permission = Permission::query()->create([
            'name' => 'documents.review',
            'guard_name' => 'web',
        ]);
        $this->subject = TestSubject::query()->create([
            'name' => 'Reviewer',
        ]);
    }

    public function test_clean_complete_scan_is_read_only(): void
    {
        $role = $this->createRoleAssignment();
        $permission = $this->createPermissionAssignment();
        $rolePermission = $this->createRolePermission();

        $this->createLock(AssignmentScope::forRole(
            TestSubject::class,
            $this->subject->getKey(),
            $this->role->getKey(),
        ));
        $this->createLock(AssignmentScope::forPermission(
            TestSubject::class,
            $this->subject->getKey(),
            $this->permission->getKey(),
        ));
        $this->createLock(AssignmentScope::forRolePermission(
            $this->role->getKey(),
            $this->permission->getKey(),
        ));

        $statements = [];
        DB::listen(static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $report = app(AssignmentDataScanner::class)->scan();

        $this->assertTrue($report->isClean());
        $this->assertSame([], $report->findings);
        $this->assertNotEmpty($statements);
        $this->assertReadOnlyStatements($statements);
        $this->assertDatabaseHas('acl_role_assignments', ['id' => $role->getKey()]);
        $this->assertDatabaseHas('acl_permission_assignments', ['id' => $permission->getKey()]);
        $this->assertDatabaseHas('acl_role_permissions', ['id' => $rolePermission->getKey()]);
        $this->assertDatabaseCount('acl_assignment_locks', 3);
    }

    public function test_overlap_and_missing_coordination_are_errors(): void
    {
        $this->createRoleAssignment([
            'starts_at' => $this->now,
            'expires_at' => $this->now->addHours(2),
        ]);
        $this->createRoleAssignment([
            'starts_at' => $this->now->addHour(),
            'expires_at' => $this->now->addHours(3),
        ]);

        $report = app(AssignmentDataScanner::class)->scan();

        $this->assertSame([
            'assignment.overlap',
            'coordination.missing_scope',
        ], $this->codes($report->findings));
        $this->assertTrue($report->hasErrors());
        $this->assertSame(1, $report->exitCode());
    }

    public function test_coordination_hash_and_duplicate_scope_are_detected(): void
    {
        $this->createRoleAssignment();
        $scope = AssignmentScope::forRole(
            TestSubject::class,
            $this->subject->getKey(),
            $this->role->getKey(),
        );
        $this->createLock($scope);

        DB::table('acl_assignment_locks')->insert([
            'assignment_kind' => $scope->kind()->value,
            'scope_hash' => str_repeat('a', 64),
            'scope_key' => $scope->key(),
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ]);

        $report = app(AssignmentDataScanner::class)->scan();

        $this->assertSame([
            'coordination.duplicate_scope',
            'coordination.hash_mismatch',
        ], $this->codes($report->findings));
    }

    public function test_invalid_period_and_partial_revocation_are_detected(): void
    {
        DB::statement('PRAGMA ignore_check_constraints = ON');

        try {
            $assignment = $this->createRoleAssignment([
                'assigned_at' => $this->now,
                'expires_at' => $this->now,
                'revoked_at' => $this->now,
                'revoked_by_type' => null,
                'revoked_by_id' => null,
            ]);
        } finally {
            DB::statement('PRAGMA ignore_check_constraints = OFF');
        }

        $this->createLock(AssignmentScope::forRole(
            TestSubject::class,
            $this->subject->getKey(),
            $this->role->getKey(),
        ));

        $report = app(AssignmentDataScanner::class)->scan();

        $this->assertNotNull($assignment->getKey());
        $this->assertSame([
            'assignment.invalid_period',
            'assignment.partial_revocation',
        ], $this->codes($report->findings));
    }

    public function test_missing_definition_is_detected_without_exposing_identifier(): void
    {
        $assignment = $this->createRoleAssignment();
        $scope = AssignmentScope::forRole(
            TestSubject::class,
            $this->subject->getKey(),
            $this->role->getKey(),
        );
        $this->createLock($scope);

        DB::statement('PRAGMA foreign_keys = OFF');

        try {
            DB::table('acl_roles')->where('id', $this->role->getKey())->delete();
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }

        $report = app(AssignmentDataScanner::class)->scan();
        $finding = $this->finding($report->findings, 'definition.missing_role');

        $this->assertSame($scope->hash(), $finding->reference);
        $this->assertStringNotContainsString(
            (string) $assignment->getAttribute('role_id'),
            $finding->message,
        );
    }

    public function test_subject_and_role_permission_guard_mismatches_are_detected(): void
    {
        $this->role->forceFill(['guard_name' => 'api'])->save();
        $this->createRoleAssignment();
        $this->createRolePermission();
        $this->createLock(AssignmentScope::forRole(
            TestSubject::class,
            $this->subject->getKey(),
            $this->role->getKey(),
        ));
        $this->createLock(AssignmentScope::forRolePermission(
            $this->role->getKey(),
            $this->permission->getKey(),
        ));

        $report = app(AssignmentDataScanner::class)->scan();

        $this->assertSame([
            'assignment.subject_guard_mismatch',
            'definition.guard_mismatch',
        ], $this->codes($report->findings));
    }

    public function test_deactivated_definition_is_informational_not_error(): void
    {
        $this->createRoleAssignment([
            'assigned_at' => $this->now->subHours(2),
            'expires_at' => $this->now,
        ]);
        $this->role->delete();
        $this->createLock(AssignmentScope::forRole(
            TestSubject::class,
            $this->subject->getKey(),
            $this->role->getKey(),
        ));

        $report = app(AssignmentDataScanner::class)->scan();

        $this->assertTrue($report->isComplete());
        $this->assertFalse($report->hasErrors());
        $this->assertSame(0, $report->exitCode());
        $this->assertSame(
            ['definition.deactivated_role'],
            $this->codes($report->findings),
        );
    }

    public function test_expired_scheduled_and_revoked_rows_are_valid_lifecycle_states(): void
    {
        $this->createRoleAssignment([
            'assigned_at' => $this->now->subHours(2),
            'expires_at' => $this->now,
        ]);
        $this->createRoleAssignment([
            'assigned_at' => $this->now->subHour(),
            'starts_at' => $this->now,
            'expires_at' => $this->now->addHour(),
        ]);
        $this->createRoleAssignment([
            'assigned_at' => $this->now->subHours(3),
            'expires_at' => $this->now->addHours(3),
            'revoked_at' => $this->now->subMinute(),
            'revoked_by_type' => 'gatetrail.system',
            'revoked_by_id' => 'test-suite',
            'revocation_reason' => 'Normal revocation',
        ]);
        $this->createLock(AssignmentScope::forRole(
            TestSubject::class,
            $this->subject->getKey(),
            $this->role->getKey(),
        ));

        $report = app(AssignmentDataScanner::class)->scan();

        $this->assertTrue($report->isClean());
        $this->assertSame([], $report->findings);
    }

    public function test_unavailable_subject_makes_guard_coverage_incomplete(): void
    {
        $assignment = $this->createRoleAssignment();
        $this->createLock(AssignmentScope::forRole(
            TestSubject::class,
            $this->subject->getKey(),
            $this->role->getKey(),
        ));
        $this->subject->delete();

        $report = app(AssignmentDataScanner::class)->scan();

        $this->assertNotNull($assignment->getKey());
        $this->assertFalse($report->coverage['data.subject_guards']);
        $this->assertSame('incomplete', $report->status());
        $this->assertSame(
            ['subject.inspection_unavailable'],
            $this->codes($report->findings),
        );
    }

    public function test_custom_assignment_global_scope_cannot_hide_an_anomaly(): void
    {
        $this->createRoleAssignment();
        config()->set(
            'gatetrail.models.role_assignment',
            HiddenDiagnosticRoleAssignment::class,
        );
        $this->app->forgetInstance(ModelRegistry::class);

        $report = app(AssignmentDataScanner::class)->scan();

        $this->assertSame(
            ['coordination.missing_scope'],
            $this->codes($report->findings),
        );
    }

    /** @param array<string, mixed> $overrides */
    private function createRoleAssignment(array $overrides = []): RoleAssignment
    {
        return RoleAssignment::query()->create(array_merge(
            $this->assignmentAttributes(),
            [
                'subject_type' => TestSubject::class,
                'subject_id' => $this->subject->getKey(),
                'role_id' => $this->role->getKey(),
            ],
            $overrides,
        ));
    }

    /** @param array<string, mixed> $overrides */
    private function createPermissionAssignment(array $overrides = []): PermissionAssignment
    {
        return PermissionAssignment::query()->create(array_merge(
            $this->assignmentAttributes(),
            [
                'subject_type' => TestSubject::class,
                'subject_id' => $this->subject->getKey(),
                'permission_id' => $this->permission->getKey(),
            ],
            $overrides,
        ));
    }

    /** @param array<string, mixed> $overrides */
    private function createRolePermission(array $overrides = []): RolePermission
    {
        return RolePermission::query()->create(array_merge(
            $this->assignmentAttributes(),
            [
                'role_id' => $this->role->getKey(),
                'permission_id' => $this->permission->getKey(),
            ],
            $overrides,
        ));
    }

    /** @return array<string, mixed> */
    private function assignmentAttributes(): array
    {
        return [
            'assigned_at' => $this->now->subHour(),
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'test-suite',
            'assignment_reason' => 'Diagnostic test',
            'starts_at' => null,
            'expires_at' => null,
            'revoked_at' => null,
            'revoked_by_type' => null,
            'revoked_by_id' => null,
            'revocation_reason' => null,
        ];
    }

    private function createLock(AssignmentScope $scope): void
    {
        DB::table('acl_assignment_locks')->insert([
            'assignment_kind' => $scope->kind()->value,
            'scope_hash' => $scope->hash(),
            'scope_key' => $scope->key(),
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ]);
    }

    /**
     * @param  list<DiagnosticFinding>  $findings
     * @return list<string>
     */
    private function codes(array $findings): array
    {
        return array_map(
            static fn (DiagnosticFinding $finding): string => $finding->code,
            $findings,
        );
    }

    /** @param list<DiagnosticFinding> $findings */
    private function finding(array $findings, string $code): DiagnosticFinding
    {
        foreach ($findings as $finding) {
            if ($finding->code === $code) {
                return $finding;
            }
        }

        $this->fail('Expected diagnostic finding ['.$code.'].');
    }
}

final class HiddenDiagnosticRoleAssignment extends RoleAssignment
{
    protected static function booted(): void
    {
        self::addGlobalScope(
            'hide-diagnostic-rows',
            static fn (Builder $builder): Builder => $builder->whereRaw('1 = 0'),
        );
    }
}

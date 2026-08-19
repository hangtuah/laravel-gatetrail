<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Integration\Support;

use DateTimeImmutable;
use DateTimeZone;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

/**
 * One authorization dataset, one expected answer, three engines.
 *
 * ADR 0012 section 12.2 makes parity the load-bearing item of the acceptance
 * matrix: per-engine runs only become evidence that **one algorithm behaves
 * identically everywhere** if every engine is asked the same question and
 * checked against the same answer. Three hand-copied fixtures could not do
 * that — they would drift, and the first drift would silently turn a parity
 * claim into three unrelated smoke tests.
 *
 * So the dataset, the expected effective roles, the expected effective
 * permissions and the expected denials all live here, once. SQLite, live MySQL
 * and live Oracle each seed **this** fixture and assert **these** expectations.
 *
 * # What makes it driver-neutral
 *
 * - **Explicit identifiers.** Nothing depends on an auto-generated key, so the
 *   expected identifiers are literals rather than whatever each engine's
 *   sequence, identity column or `AUTOINCREMENT` happened to produce.
 * - **Bound `DateTimeImmutable` instants.** Every lifecycle column is bound as
 *   a date object and formatted by the connection's own grammar, so MySQL
 *   `DATETIME`, Oracle `DATE` and SQLite text each receive their engine's
 *   representation. No string literal is compared as a string.
 * - **Names resolved through `SchemaContext`.** Every table is looked up by
 *   manifest key, so a configured prefix (MySQL) or schema qualifier (Oracle)
 *   applies to the fixture exactly as it applies to the resolver.
 * - **Ordering asserted in raw bytes.** The expected order is the snapshot's
 *   `strcmp()` order, never an engine's collation order, which is precisely
 *   the difference the acceptance matrix exists to detect.
 *
 * # What the dataset covers
 *
 * Everything reachable without a second subject: direct grants, role-derived
 * grants, a permission reachable by both paths, a role that grants nothing,
 * cross-guard exclusion on both sides, case- and pad-widened guards, all three
 * revocation shapes, both deactivation shapes, and every half-open lifecycle
 * boundary. A second, role-less subject exists purely so the two-statement
 * cold budget has a subject to be measured against.
 *
 * @see docs/decisions/0012-effective-access-resolution.md sections 12.2 and 17
 */
final class ResolverAcceptanceFixture
{
    public const GUARD = 'web';

    public const SUBJECT_ID = 1;

    public const ROLELESS_SUBJECT_ID = 2;

    /**
     * The one evaluation instant every engine is asked about.
     *
     * Every lifecycle boundary below is expressed relative to it, so "at
     * `expires_at`" means the same thing on every engine.
     */
    public const EVALUATED_AT = '2026-08-19 12:00:00';

    // Roles. 1xx, so a role identifier can never be mistaken for a
    // permission identifier in a failure message.
    public const ROLE_EDITOR = 101;

    public const ROLE_AUDITOR = 102;

    public const ROLE_ARCHIVIST = 103;

    public const ROLE_API_ADMIN = 104;

    public const ROLE_REVOKED_ASSIGNMENT = 105;

    public const ROLE_SCHEDULED = 106;

    public const ROLE_REVOKED_GRANT = 107;

    public const ROLE_CASE_GUARD = 108;

    public const ROLE_PAD_GUARD = 109;

    public const ROLE_UNHELD = 110;

    // Permissions. 2xx.
    public const PERMISSION_APPROVE = 201;

    public const PERMISSION_PUBLISH = 202;

    public const PERMISSION_REVIEW = 203;

    public const PERMISSION_ARCHIVE = 204;

    public const PERMISSION_VIA_DEACTIVATED_ROLE = 205;

    public const PERMISSION_API = 206;

    public const PERMISSION_VIA_REVOKED_ASSIGNMENT = 207;

    public const PERMISSION_VIA_SCHEDULED_ROLE = 208;

    public const PERMISSION_VIA_REVOKED_GRANT = 209;

    public const PERMISSION_API_DIRECT = 210;

    public const PERMISSION_REVOKED_DIRECT = 211;

    public const PERMISSION_CASE_GUARD = 212;

    public const PERMISSION_PAD_GUARD = 213;

    public const PERMISSION_UNASSIGNED = 214;

    public const PERMISSION_VIA_UNHELD_ROLE = 215;

    public const PERMISSION_AT_START = 220;

    public const PERMISSION_BEFORE_START = 221;

    public const PERMISSION_EXPIRES_NEXT = 222;

    public const PERMISSION_EXPIRES_NOW = 223;

    public const PERMISSION_EXPIRED = 224;

    public const PERMISSION_NULL_START = 225;

    public const PERMISSION_NULL_START_FUTURE = 226;

    public function __construct(
        private readonly Connection $connection,
        private readonly SchemaContext $schema,
    ) {}

    public static function evaluatedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            self::EVALUATED_AT,
            new DateTimeZone('UTC'),
        );
    }

    /**
     * The canonical effective roles: identifier, name and guard, in the
     * snapshot's raw-byte name order.
     *
     * `auditor` grants no permission at all and is still effective, which is
     * the distinction between "holds a role" and "gains something from it".
     * `revoked-grant` makes the same point from the other side: the role is
     * held, and the permission behind it is revoked.
     *
     * @return list<array{id: int, name: string, guardName: string}>
     */
    public static function expectedRoles(): array
    {
        return [
            ['id' => self::ROLE_AUDITOR, 'name' => 'auditor', 'guardName' => self::GUARD],
            ['id' => self::ROLE_EDITOR, 'name' => 'editor', 'guardName' => self::GUARD],
            // Held and active. Only the *grant* hanging off it is revoked, so
            // the role is effective while its permission is not — the two
            // lifecycles are separate and this is where they part company.
            ['id' => self::ROLE_REVOKED_GRANT, 'name' => 'revoked-grant', 'guardName' => self::GUARD],
        ];
    }

    /**
     * The canonical effective permissions, in raw-byte name order.
     *
     * `documents.approve` is reachable **both** directly and through `editor`
     * and appears exactly once.
     *
     * @return list<array{id: int, name: string, guardName: string}>
     */
    public static function expectedPermissions(): array
    {
        return [
            ['id' => self::PERMISSION_APPROVE, 'name' => 'documents.approve', 'guardName' => self::GUARD],
            ['id' => self::PERMISSION_PUBLISH, 'name' => 'documents.publish', 'guardName' => self::GUARD],
            ['id' => self::PERMISSION_REVIEW, 'name' => 'documents.review', 'guardName' => self::GUARD],
            ['id' => self::PERMISSION_AT_START, 'name' => 'lifecycle.at-start', 'guardName' => self::GUARD],
            ['id' => self::PERMISSION_EXPIRES_NEXT, 'name' => 'lifecycle.expires-next', 'guardName' => self::GUARD],
            ['id' => self::PERMISSION_NULL_START, 'name' => 'lifecycle.null-start', 'guardName' => self::GUARD],
        ];
    }

    /**
     * Every permission name that must be denied, with the reason it is denied.
     *
     * Kept as one table rather than scattered through the assertions, so a
     * reader can see the whole exclusion surface at once and an engine that
     * widens any one of them fails with a message naming the case.
     *
     * @return array<string, string>
     */
    public static function deniedPermissions(): array
    {
        return [
            'documents.archive' => 'the permission definition is deactivated',
            'documents.via-deactivated-role' => 'the granting role definition is deactivated',
            'documents.api' => 'the permission belongs to another guard',
            'documents.api-direct' => 'the direct grant belongs to another guard',
            'documents.via-revoked-assignment' => 'the subject role assignment is revoked',
            'documents.via-scheduled-role' => 'the subject role assignment has not started',
            'documents.via-revoked-grant' => 'the role permission grant is revoked',
            'documents.revoked-direct' => 'the direct permission assignment is revoked',
            'documents.case-guard' => 'the guard differs only by case, which a case-insensitive collation would widen',
            'documents.pad-guard' => 'the guard differs only by a trailing space, which a PAD SPACE collation would widen',
            'documents.unassigned' => 'the definition exists but nothing grants it',
            'documents.via-unheld-role' => 'the granting role exists but the subject does not hold it',
            'lifecycle.before-start' => 'starts_at is one second in the future',
            'lifecycle.expires-now' => 'expires_at is exactly the evaluation instant, and the window is half-open',
            'lifecycle.expired' => 'expires_at was one second ago',
            'lifecycle.null-start-future' => 'starts_at is null and assigned_at is one second in the future',
            'documents.no-such-permission' => 'no definition of that name exists at all',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function deniedRoles(): array
    {
        return [
            'archivist' => 'the role definition is deactivated',
            'api-admin' => 'the role belongs to another guard',
            'revoked-assignment' => 'the subject role assignment is revoked',
            'scheduled' => 'the subject role assignment has not started',
            'case-guard' => 'the guard differs only by case',
            'pad-guard' => 'the guard differs only by a trailing space',
            'unheld' => 'the role exists but is not assigned to the subject',
            'no-such-role' => 'no definition of that name exists at all',
        ];
    }

    /**
     * Names that must not match, proving the comparison is byte-exact rather
     * than collation-dependent — `documents.approve` **is** effective, so a
     * widened comparison would return true for every one of these.
     *
     * @return list<string>
     */
    public static function byteExactMisses(): array
    {
        return [
            'DOCUMENTS.APPROVE',
            'Documents.Approve',
            'documents.Approve',
            ' documents.approve',
            'documents.approve ',
            ' documents.approve ',
            "documents.approve\t",
        ];
    }

    // ------------------------------------------------------------- Schema

    /**
     * The subject table, on the same connection GateTrail is installed on.
     *
     * Explicit identifiers and no auto-increment, so the subject key is the
     * same literal on every engine rather than whatever each one generates.
     */
    public function installSubjectTable(): void
    {
        $this->dropSubjectTable();

        $this->connection->getSchemaBuilder()->create(
            AcceptanceSubject::TABLE,
            static function (Blueprint $table): void {
                $table->unsignedBigInteger('id');
                $table->string('name', 100);
                $table->primary('id');
            },
        );
    }

    public function dropSubjectTable(): void
    {
        $this->connection->getSchemaBuilder()
            ->dropIfExists(AcceptanceSubject::TABLE);
    }

    // -------------------------------------------------------------- Data

    /**
     * Seeds the whole dataset.
     *
     * Written with the query builder against `SchemaContext`-resolved tables
     * rather than through the mutation managers, deliberately: this is fixture
     * construction, not a managed mutation. **No `MutationCommitted` event is
     * dispatched**, so nothing here flushes a memo, and a caller that seeds
     * between two evaluations must clear the memo or move the clock itself.
     */
    public function seed(): void
    {
        $this->seedSubjects();
        $this->seedRoles();
        $this->seedPermissions();
        $this->seedRoleAssignments();
        $this->seedRolePermissions();
        $this->seedDirectPermissions();
    }

    private function seedSubjects(): void
    {
        $this->connection->table(AcceptanceSubject::TABLE)->insert([
            ['id' => self::SUBJECT_ID, 'name' => 'Acceptance Subject'],
            ['id' => self::ROLELESS_SUBJECT_ID, 'name' => 'Role-less Subject'],
        ]);
    }

    private function seedRoles(): void
    {
        $this->insertDefinitions('roles', [
            [self::ROLE_EDITOR, 'editor', self::GUARD, null],
            [self::ROLE_AUDITOR, 'auditor', self::GUARD, null],
            // Deactivated: an active assignment to it must still grant nothing.
            [self::ROLE_ARCHIVIST, 'archivist', self::GUARD, $this->at('-1 day')],
            [self::ROLE_API_ADMIN, 'api-admin', 'api', null],
            [self::ROLE_REVOKED_ASSIGNMENT, 'revoked-assignment', self::GUARD, null],
            [self::ROLE_SCHEDULED, 'scheduled', self::GUARD, null],
            [self::ROLE_REVOKED_GRANT, 'revoked-grant', self::GUARD, null],
            // Guards that a case-insensitive or PAD SPACE collation widens into
            // `web`. Distinct names, because the (name, guard_name) unique index
            // is itself collation-sensitive on MySQL.
            [self::ROLE_CASE_GUARD, 'case-guard', 'WEB', null],
            [self::ROLE_PAD_GUARD, 'pad-guard', 'web ', null],
            [self::ROLE_UNHELD, 'unheld', self::GUARD, null],
        ]);
    }

    private function seedPermissions(): void
    {
        $this->insertDefinitions('permissions', [
            [self::PERMISSION_APPROVE, 'documents.approve', self::GUARD, null],
            [self::PERMISSION_PUBLISH, 'documents.publish', self::GUARD, null],
            [self::PERMISSION_REVIEW, 'documents.review', self::GUARD, null],
            [self::PERMISSION_ARCHIVE, 'documents.archive', self::GUARD, $this->at('-1 day')],
            [self::PERMISSION_VIA_DEACTIVATED_ROLE, 'documents.via-deactivated-role', self::GUARD, null],
            [self::PERMISSION_API, 'documents.api', 'api', null],
            [self::PERMISSION_VIA_REVOKED_ASSIGNMENT, 'documents.via-revoked-assignment', self::GUARD, null],
            [self::PERMISSION_VIA_SCHEDULED_ROLE, 'documents.via-scheduled-role', self::GUARD, null],
            [self::PERMISSION_VIA_REVOKED_GRANT, 'documents.via-revoked-grant', self::GUARD, null],
            [self::PERMISSION_API_DIRECT, 'documents.api-direct', 'api', null],
            [self::PERMISSION_REVOKED_DIRECT, 'documents.revoked-direct', self::GUARD, null],
            [self::PERMISSION_CASE_GUARD, 'documents.case-guard', 'WEB', null],
            [self::PERMISSION_PAD_GUARD, 'documents.pad-guard', 'web ', null],
            [self::PERMISSION_UNASSIGNED, 'documents.unassigned', self::GUARD, null],
            [self::PERMISSION_VIA_UNHELD_ROLE, 'documents.via-unheld-role', self::GUARD, null],
            [self::PERMISSION_AT_START, 'lifecycle.at-start', self::GUARD, null],
            [self::PERMISSION_BEFORE_START, 'lifecycle.before-start', self::GUARD, null],
            [self::PERMISSION_EXPIRES_NEXT, 'lifecycle.expires-next', self::GUARD, null],
            [self::PERMISSION_EXPIRES_NOW, 'lifecycle.expires-now', self::GUARD, null],
            [self::PERMISSION_EXPIRED, 'lifecycle.expired', self::GUARD, null],
            [self::PERMISSION_NULL_START, 'lifecycle.null-start', self::GUARD, null],
            [self::PERMISSION_NULL_START_FUTURE, 'lifecycle.null-start-future', self::GUARD, null],
        ]);
    }

    private function seedRoleAssignments(): void
    {
        $rows = [];

        foreach ([
            self::ROLE_EDITOR,
            self::ROLE_AUDITOR,
            self::ROLE_ARCHIVIST,
            self::ROLE_API_ADMIN,
            self::ROLE_REVOKED_GRANT,
            self::ROLE_CASE_GUARD,
            self::ROLE_PAD_GUARD,
        ] as $roleId) {
            $rows[] = $this->assignment(['role_id' => $roleId]);
        }

        $rows[] = $this->assignment(
            ['role_id' => self::ROLE_REVOKED_ASSIGNMENT],
            $this->revoked(),
        );
        $rows[] = $this->assignment(
            ['role_id' => self::ROLE_SCHEDULED],
            ['starts_at' => $this->at('+1 second')],
        );

        $this->connection
            ->table($this->schema->table('role_assignments'))
            ->insert($rows);
    }

    private function seedRolePermissions(): void
    {
        $grants = [
            [self::ROLE_EDITOR, self::PERMISSION_APPROVE, []],
            [self::ROLE_EDITOR, self::PERMISSION_REVIEW, []],
            [self::ROLE_EDITOR, self::PERMISSION_ARCHIVE, []],
            [self::ROLE_ARCHIVIST, self::PERMISSION_VIA_DEACTIVATED_ROLE, []],
            [self::ROLE_API_ADMIN, self::PERMISSION_API, []],
            [self::ROLE_REVOKED_ASSIGNMENT, self::PERMISSION_VIA_REVOKED_ASSIGNMENT, []],
            [self::ROLE_SCHEDULED, self::PERMISSION_VIA_SCHEDULED_ROLE, []],
            [self::ROLE_REVOKED_GRANT, self::PERMISSION_VIA_REVOKED_GRANT, $this->revoked()],
            [self::ROLE_CASE_GUARD, self::PERMISSION_CASE_GUARD, []],
            [self::ROLE_PAD_GUARD, self::PERMISSION_PAD_GUARD, []],
            [self::ROLE_UNHELD, self::PERMISSION_VIA_UNHELD_ROLE, []],
        ];

        $rows = [];

        foreach ($grants as [$roleId, $permissionId, $overrides]) {
            $rows[] = $this->grant(
                ['role_id' => $roleId, 'permission_id' => $permissionId],
                $overrides,
            );
        }

        $this->connection
            ->table($this->schema->table('role_permissions'))
            ->insert($rows);
    }

    private function seedDirectPermissions(): void
    {
        $rows = [
            $this->assignment(['permission_id' => self::PERMISSION_APPROVE]),
            $this->assignment(['permission_id' => self::PERMISSION_PUBLISH]),
            $this->assignment(['permission_id' => self::PERMISSION_ARCHIVE]),
            $this->assignment(['permission_id' => self::PERMISSION_API_DIRECT]),
            $this->assignment(
                ['permission_id' => self::PERMISSION_REVOKED_DIRECT],
                $this->revoked(),
            ),
            // The half-open lifecycle boundaries, all relative to one instant.
            $this->assignment(
                ['permission_id' => self::PERMISSION_AT_START],
                ['starts_at' => $this->at('now')],
            ),
            $this->assignment(
                ['permission_id' => self::PERMISSION_BEFORE_START],
                ['starts_at' => $this->at('+1 second')],
            ),
            $this->assignment(
                ['permission_id' => self::PERMISSION_EXPIRES_NEXT],
                ['expires_at' => $this->at('+1 second')],
            ),
            $this->assignment(
                ['permission_id' => self::PERMISSION_EXPIRES_NOW],
                ['expires_at' => $this->at('now')],
            ),
            $this->assignment(
                ['permission_id' => self::PERMISSION_EXPIRED],
                ['expires_at' => $this->at('-1 second')],
            ),
            // starts_at is null, so assigned_at carries the window's start.
            $this->assignment(
                ['permission_id' => self::PERMISSION_NULL_START],
                ['assigned_at' => $this->at('now')],
            ),
            $this->assignment(
                ['permission_id' => self::PERMISSION_NULL_START_FUTURE],
                ['assigned_at' => $this->at('+1 second')],
            ),
        ];

        $this->connection
            ->table($this->schema->table('permission_assignments'))
            ->insert($rows);
    }

    /**
     * @param  list<array{int, string, string, DateTimeImmutable|null}>  $definitions
     */
    private function insertDefinitions(string $tableKey, array $definitions): void
    {
        $rows = [];

        foreach ($definitions as [$id, $name, $guard, $deletedAt]) {
            $rows[] = [
                'id' => $id,
                'name' => $name,
                'guard_name' => $guard,
                'description' => null,
                'created_at' => $this->at('-1 day'),
                'updated_at' => $this->at('-1 day'),
                'deleted_at' => $deletedAt,
            ];
        }

        $this->connection->table($this->schema->table($tableKey))->insert($rows);
    }

    /**
     * A subject-scoped assignment row: active by default, one hour old, so an
     * `expires_at` of "now" or "one second ago" still satisfies the period
     * check constraint.
     *
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function assignment(array $identity, array $overrides = []): array
    {
        return [
            ...$identity,
            'subject_type' => (new AcceptanceSubject)->getMorphClass(),
            'subject_id' => self::SUBJECT_ID,
            'assigned_at' => $this->at('-1 hour'),
            'assigned_by_type' => 'gatetrail.system',
            'assigned_by_id' => 'acceptance',
            'assignment_reason' => null,
            'starts_at' => null,
            'expires_at' => null,
            'revoked_at' => null,
            'revoked_by_type' => null,
            'revoked_by_id' => null,
            'revocation_reason' => null,
            'created_at' => $this->at('-1 hour'),
            'updated_at' => $this->at('-1 hour'),
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function grant(array $identity, array $overrides = []): array
    {
        $row = $this->assignment($identity, $overrides);

        // Role-permission grants are not subject-scoped.
        unset($row['subject_type'], $row['subject_id']);

        return $row;
    }

    /**
     * The revoke check constraint requires all three columns together.
     *
     * @return array<string, mixed>
     */
    private function revoked(): array
    {
        return [
            'revoked_at' => $this->at('-1 minute'),
            'revoked_by_type' => 'gatetrail.system',
            'revoked_by_id' => 'acceptance',
            'revocation_reason' => 'Acceptance fixture',
        ];
    }

    /**
     * A `DateTimeImmutable` relative to the shared instant, bound as a date
     * object so each engine's grammar formats it for that engine's column
     * type.
     */
    private function at(string $modifier): DateTimeImmutable
    {
        $instant = self::evaluatedAt();

        return $modifier === 'now' ? $instant : $instant->modify($modifier);
    }
}

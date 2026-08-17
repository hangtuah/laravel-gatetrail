<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Access;

use DateTimeImmutable;
use HangTuah\GateTrail\Contracts\PermissionAssignmentContract;
use HangTuah\GateTrail\Contracts\RoleAssignmentContract;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\PermissionAssignment;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\Models\RoleAssignment;
use HangTuah\GateTrail\Subjects\SubjectIdentity;
use HangTuah\GateTrail\Support\ModelRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Builds the joined-definition queries the snapshot is made of.
 *
 * Every method here **returns an unexecuted builder**. Nothing in this class
 * calls `get()`, `first()`, `exists()`, `count()`, `cursor()`, `lazy()` or
 * `paginate()`, so constructing a query issues no statement, opens no
 * transaction and takes no lock. Increment 5 owns execution and row mapping.
 *
 * Three filtering layers produce one answer, and only the first is inherited:
 *
 * | Layer | Excludes | Mechanism |
 * |---|---|---|
 * | `activeAt()` on the assignment root | Revoked, not yet started, expired | The existing trait scope |
 * | `deleted_at IS NULL` on the joined definition | Deactivated definitions | **Written here** |
 * | `guard_name = ?` on the joined definition | Cross-guard definitions | **Written here** |
 *
 * The second and third layers are written rather than inherited because
 * neither is available to inherit, and getting that wrong **fails open**. A
 * `SoftDeletes` global scope applies to the model a query is *built from* —
 * here always an assignment model, none of which soft-delete — so joining
 * `roles` brings in the table, not its global scopes. Escaping to the
 * `role()` / `permission()` relations would be worse: those are deliberately
 * `withTrashed()` so audit snapshots can still reach a deactivated
 * definition, and `whereHas()` over them would **include** deactivated rows.
 * Those relations are correct and are not changed.
 *
 * Every column this class writes is qualified against its resolved table. The
 * only unqualified lifecycle columns are the ones `scopeActiveAt()` itself
 * emits, which is safe here because each query scope contains exactly one
 * lifecycle-bearing table — the assignment root. `roles` and `permissions`
 * carry no `revoked_at`, `starts_at`, `assigned_at` or `expires_at`, so those
 * unqualified names can only bind to the root.
 *
 * Table and column names come from `ModelRegistry`-resolved model instances,
 * never from literals, so a configured prefix, a configured schema, a
 * replacement model, an overridden `DELETED_AT` and a custom primary-key name
 * all apply without a change here.
 *
 * Laravel builder methods only: no raw SQL, no `DB::raw()`, no driver branch,
 * no `ORDER BY`, no `DISTINCT`, no lock, no pagination. Ordering and
 * deduplication happen in PHP, because collation makes engine ordering
 * non-portable.
 *
 * @internal
 *
 * @see docs/decisions/0012-effective-access-resolution.md
 */
final readonly class EffectiveAccessQueryBuilder
{
    /**
     * Fixed internal aliases for the three selected definition columns.
     *
     * The selected identifier is the definition's primary key, whose *column*
     * name a replacement model may change. Aliasing gives Increment 5 three
     * stable keys to read regardless of how the definition model is
     * configured, so the mapping never has to ask the root assignment model
     * about attributes it does not own.
     *
     * These are **internal names, not public API**. They are short enough to
     * stay inside the 30-character identifier bound the package already
     * enforces for portability.
     */
    public const ID_ALIAS = 'gatetrail_id';

    public const NAME_ALIAS = 'gatetrail_name';

    public const GUARD_ALIAS = 'gatetrail_guard';

    public function __construct(
        private ModelRegistry $models,
    ) {}

    /**
     * ADR query 1 — the subject's effective roles, as an unexecuted builder.
     *
     * ```text
     * root: role_assignments
     *   .activeAt(evaluatedAt)
     *   .where(role_assignments.subject_type, subjectType)
     *   .where(role_assignments.subject_id,   subjectId)
     *   join roles on roles.id = role_assignments.role_id
     *   .whereNull(roles.deleted_at)
     *   .where(roles.guard_name, guard)
     *   select roles.id, roles.name, roles.guard_name
     * ```
     *
     * @return Builder<RoleAssignment&RoleAssignmentContract>
     */
    public function effectiveRolesQuery(
        SubjectIdentity $subject,
        string $guardName,
        DateTimeImmutable $evaluatedAt,
    ): Builder {
        $assignmentClass = $this->models->roleAssignment();
        $assignment = new $assignmentClass;

        $definitionClass = $this->models->role();
        $definition = new $definitionClass;

        return $this->joinDefinition(
            $assignment->newQuery()->activeAt($evaluatedAt),
            $assignment,
            $definition,
            'role_id',
            $subject,
            $guardName,
        );
    }

    /**
     * ADR query 2 — the subject's direct permission grants, as an unexecuted
     * builder. Role-derived permissions are query 3 and are not built here.
     *
     * ```text
     * root: permission_assignments
     *   .activeAt(evaluatedAt)
     *   .where(permission_assignments.subject_type, subjectType)
     *   .where(permission_assignments.subject_id,   subjectId)
     *   join permissions on permissions.id = permission_assignments.permission_id
     *   .whereNull(permissions.deleted_at)
     *   .where(permissions.guard_name, guard)
     *   select permissions.id, permissions.name, permissions.guard_name
     * ```
     *
     * @return Builder<PermissionAssignment&PermissionAssignmentContract>
     */
    public function directPermissionsQuery(
        SubjectIdentity $subject,
        string $guardName,
        DateTimeImmutable $evaluatedAt,
    ): Builder {
        $assignmentClass = $this->models->permissionAssignment();
        $assignment = new $assignmentClass;

        $definitionClass = $this->models->permission();
        $definition = new $definitionClass;

        return $this->joinDefinition(
            $assignment->newQuery()->activeAt($evaluatedAt),
            $assignment,
            $definition,
            'permission_id',
            $subject,
            $guardName,
        );
    }

    /**
     * The shape both queries share, applied to a root builder that already
     * carries its own `activeAt()` scope.
     *
     * `activeAt()` is applied by the caller rather than here so the scope is
     * invoked on a builder whose model type is known, and so this method can
     * never be mistaken for a second copy of the half-open rule.
     *
     * @template TAssignment of Model
     *
     * @param  Builder<TAssignment>  $query
     * @param  Role|Permission  $definition
     * @return Builder<TAssignment>
     */
    private function joinDefinition(
        Builder $query,
        Model $assignment,
        Model $definition,
        string $foreignKey,
        SubjectIdentity $subject,
        string $guardName,
    ): Builder {
        return $query
            ->where($assignment->qualifyColumn('subject_type'), $subject->type)
            ->where($assignment->qualifyColumn('subject_id'), $subject->id)
            ->join(
                $definition->getTable(),
                $definition->getQualifiedKeyName(),
                '=',
                $assignment->qualifyColumn($foreignKey),
            )
            // Explicit, because nothing on a joined table is inherited. Read
            // from the model so an overridden DELETED_AT is honoured.
            ->whereNull($definition->getQualifiedDeletedAtColumn())
            ->where($definition->qualifyColumn('guard_name'), $guardName)
            ->select([
                $definition->getQualifiedKeyName().' as '.self::ID_ALIAS,
                $definition->qualifyColumn('name').' as '.self::NAME_ALIAS,
                $definition->qualifyColumn('guard_name').' as '.self::GUARD_ALIAS,
            ]);
    }
}

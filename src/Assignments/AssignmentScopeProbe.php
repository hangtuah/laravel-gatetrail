<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Assignments;

use HangTuah\GateTrail\AssignmentReference;
use HangTuah\GateTrail\Contracts\PermissionAssignmentContract;
use HangTuah\GateTrail\Contracts\RoleAssignmentContract;
use HangTuah\GateTrail\Contracts\RolePermissionContract;
use HangTuah\GateTrail\Exceptions\AssignmentNotActive;
use HangTuah\GateTrail\Mutations\AssignmentIdentifierNormalizer;
use HangTuah\GateTrail\Support\ModelRegistry;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Derives the canonical coordination scope of an existing assignment from the
 * database, without locking the assignment row.
 *
 * # Why this exists
 *
 * ADR 0001 coordinates every managed assignment creation *and revocation*
 * through `acl_assignment_locks`. A revocation therefore needs the scope before
 * it can take the coordination row — but it only knows the assignment's
 * identifier, and the scope lives in the assignment row's own columns.
 *
 * Reading those columns under a row lock would invert the lock order. The
 * creation path takes the coordination row first and equivalent assignment rows
 * second; a revocation that locked its assignment row first and then reached for
 * the coordination row would let the two paths deadlock against each other on
 * the same scope. So the probe reads **without** a lock, purely to learn which
 * coordination row to take. The authoritative, locked read happens afterwards,
 * through the existing input resolvers, and the caller then re-derives the scope
 * from that locked row and compares. A mismatch fails closed.
 *
 * # Why it never trusts the caller's model
 *
 * A consumer may hand in a detached, stale or deliberately modified Eloquent
 * model. Only the identifier is taken from it; every scope component is read
 * back from the database by primary key. `subject_type`, `subject_id`,
 * `role_id` and `permission_id` on the caller's instance are ignored entirely,
 * so a modified in-memory attribute cannot steer GateTrail to coordinate on —
 * and therefore serialize against — the wrong scope.
 *
 * Identifiers are normalized through the same
 * {@see AssignmentIdentifierNormalizer} the resolvers use, so a malformed
 * numeric identifier is rejected before it can reach a PostgreSQL, Oracle or
 * SQL Server binding as a type the column cannot compare.
 *
 * @internal
 *
 * @see docs/decisions/0001-unique-active-assignments.md
 * @see docs/decisions/0011-bounded-concurrency-retry.md
 */
final readonly class AssignmentScopeProbe
{
    public function __construct(
        private ModelRegistry $models,
        private AssignmentIdentifierNormalizer $identifiers,
    ) {}

    /**
     * Reads one assignment's persisted identity by key, without locking it, and
     * returns the coordination scope it belongs to.
     *
     *
     * @throws AssignmentNotActive When the assignment cannot be identified or
     *                             no longer exists. This is the same outcome
     *                             the locked resolvers produce, so the probe
     *                             never turns a missing assignment into a
     *                             different failure.
     */
    public function probe(
        AssignmentKind $kind,
        RoleAssignmentContract|PermissionAssignmentContract|RolePermissionContract|AssignmentReference|string|int $input,
    ): AssignmentScope {
        $class = $this->modelFor($kind);
        $instance = new $class;

        if ($instance->getConnection()->transactionLevel() === 0) {
            throw new LogicException(
                'GateTrail assignment scopes must be probed inside a database transaction.',
            );
        }

        $reference = $this->reference($kind, $input);
        $key = $this->identifiers->normalize($instance, $reference);

        if ($key === null) {
            throw $this->notActive($kind, $reference);
        }

        // No lockForUpdate(): this read exists only to choose the coordination
        // row, and locking here would invert the package's lock order.
        $row = $class::query()
            ->whereKey($key)
            ->first($this->columnsFor($kind));

        if (! $row instanceof Model) {
            throw $this->notActive($kind, $reference);
        }

        return $this->fromModel($kind, $row);
    }

    /**
     * Derives the same canonical scope from an already-loaded assignment.
     *
     * The caller uses this on the row it reloaded under `SELECT ... FOR UPDATE`
     * and compares the result with the probe, so the comparison runs through
     * one derivation rather than two that could drift.
     */
    public function fromModel(
        AssignmentKind $kind,
        Model $assignment,
    ): AssignmentScope {
        return match ($kind) {
            AssignmentKind::Role => AssignmentScope::forRole(
                $this->subjectType($assignment),
                $this->component($assignment, 'subject_id'),
                $this->component($assignment, 'role_id'),
            ),
            AssignmentKind::Permission => AssignmentScope::forPermission(
                $this->subjectType($assignment),
                $this->component($assignment, 'subject_id'),
                $this->component($assignment, 'permission_id'),
            ),
            AssignmentKind::RolePermission => AssignmentScope::forRolePermission(
                $this->component($assignment, 'role_id'),
                $this->component($assignment, 'permission_id'),
            ),
        };
    }

    /**
     * The identity columns of each assignment kind, as ADR 0001 defines them.
     *
     * @return list<string>
     */
    private function columnsFor(AssignmentKind $kind): array
    {
        return match ($kind) {
            AssignmentKind::Role => ['subject_type', 'subject_id', 'role_id'],
            AssignmentKind::Permission => [
                'subject_type',
                'subject_id',
                'permission_id',
            ],
            AssignmentKind::RolePermission => ['role_id', 'permission_id'],
        };
    }

    /**
     * @return class-string<Model>
     */
    private function modelFor(AssignmentKind $kind): string
    {
        return match ($kind) {
            AssignmentKind::Role => $this->models->roleAssignment(),
            AssignmentKind::Permission => $this->models->permissionAssignment(),
            AssignmentKind::RolePermission => $this->models->rolePermission(),
        };
    }

    /**
     * Takes only the identifier from the caller's input, never its attributes.
     */
    private function reference(
        AssignmentKind $kind,
        RoleAssignmentContract|PermissionAssignmentContract|RolePermissionContract|AssignmentReference|string|int $input,
    ): int|string {
        if ($input instanceof AssignmentReference) {
            return $input->id;
        }

        if ($input instanceof Model) {
            if (! $input->exists) {
                throw $this->notActive($kind, 'model');
            }

            $id = $input->getKey();

            if (! is_int($id) && ! is_string($id)) {
                throw $this->notActive($kind, 'model');
            }

            return $id;
        }

        if (! is_int($input) && ! is_string($input)) {
            throw $this->notActive($kind, 'model');
        }

        return $input;
    }

    private function notActive(
        AssignmentKind $kind,
        int|string $reference,
    ): AssignmentNotActive {
        return match ($kind) {
            AssignmentKind::Role => AssignmentNotActive::forRole($reference),
            AssignmentKind::Permission => AssignmentNotActive::forPermission(
                $reference,
            ),
            AssignmentKind::RolePermission => AssignmentNotActive::forRolePermission(
                $reference,
            ),
        };
    }

    private function subjectType(Model $assignment): string
    {
        $value = $assignment->getAttribute('subject_type');

        if (! is_string($value) || $value === '') {
            throw new LogicException(
                'GateTrail assignment subject type is missing from storage.',
            );
        }

        return $value;
    }

    /**
     * Reads one persisted identity column.
     *
     * The value is handed to {@see AssignmentScope}, which rejects anything
     * that is not an unsigned decimal integer, so a corrupted column cannot
     * produce a scope hash and cannot reach a coordination row.
     */
    private function component(
        Model $assignment,
        string $attribute,
    ): int|string {
        $value = $assignment->getAttribute($attribute);

        if (! is_int($value) && ! is_string($value)) {
            throw new LogicException(
                sprintf(
                    'GateTrail assignment identity column [%s] is missing from storage.',
                    $attribute,
                ),
            );
        }

        return $value;
    }
}

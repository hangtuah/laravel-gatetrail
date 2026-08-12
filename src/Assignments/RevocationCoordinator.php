<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Assignments;

use Closure;
use DateTimeInterface;
use HangTuah\GateTrail\AssignmentReference;
use HangTuah\GateTrail\Contracts\PermissionAssignmentContract;
use HangTuah\GateTrail\Contracts\RoleAssignmentContract;
use HangTuah\GateTrail\Contracts\RolePermissionContract;
use HangTuah\GateTrail\Exceptions\AssignmentScopeCollision;
use Illuminate\Database\Eloquent\Model;

/**
 * Serializes a revocation against the same coordination row its creation used.
 *
 * ADR 0001 coordinates every managed assignment creation *and revocation*
 * through `acl_assignment_locks`. Creation knows its scope from the caller's
 * arguments; revocation has to learn it from the assignment row itself, which
 * is what makes the ordering delicate enough to be worth one shared
 * implementation rather than three near-identical ones.
 *
 * # The order, and why it is this way
 *
 * 1. probe the assignment's persisted identity **without** locking it;
 * 2. derive the canonical {@see AssignmentScope} from those persisted values;
 * 3. acquire the coordination row for that scope;
 * 4. reload the assignment with `SELECT ... FOR UPDATE`;
 * 5. re-derive the scope from the locked row and confirm it did not move;
 * 6. hand the locked assignment back for revocation, audit and result work.
 *
 * Steps 1 and 4 are deliberately separate reads. Locking the assignment row
 * first — the obvious single-read version — would invert the lock order against
 * the creation path, which takes the coordination row and *then* locks
 * equivalent assignment rows. Two writers working on one scope, one creating
 * and one revoking, would then hold each other's next lock. Reading unlocked
 * first costs one extra `SELECT` and removes that cycle entirely.
 *
 * The unlocked probe is only a hint about *which* coordination row to take. It
 * is never trusted as truth: step 5 re-derives the scope from the locked row,
 * and a mismatch fails closed rather than picking either answer. Assignment
 * identity is immutable in GateTrail, so a mismatch means the row was deleted
 * and recreated under the same key, or was edited outside the package — both
 * integrity violations, and neither safe to guess through.
 *
 * @internal
 *
 * @see docs/decisions/0001-unique-active-assignments.md
 */
final readonly class RevocationCoordinator
{
    public function __construct(
        private AssignmentScopeProbe $scopes,
        private AssignmentLockStore $locks,
    ) {}

    /**
     * Runs steps 1 to 5 and returns the locked assignment.
     *
     * @template TAssignment of Model
     *
     * @param  Closure(): TAssignment  $reload  The existing locked resolver for
     *                                          this assignment kind, called only
     *                                          once coordination is held.
     * @return TAssignment
     *
     * @throws AssignmentScopeCollision When the locked row's scope differs from
     *                                  the probed scope.
     */
    public function coordinate(
        AssignmentKind $kind,
        RoleAssignmentContract|PermissionAssignmentContract|RolePermissionContract|AssignmentReference|string|int $input,
        DateTimeInterface $timestamp,
        Closure $reload,
    ): Model {
        $probed = $this->scopes->probe($kind, $input);

        $this->locks->acquire($probed, $timestamp);

        $assignment = $reload();

        if ($this->scopes->fromModel($kind, $assignment)->key() !== $probed->key()) {
            // Fail closed. The exception carries the hashed scope only, so no
            // subject identifier, role, permission or caller input reaches its
            // message.
            throw AssignmentScopeCollision::forScope($probed);
        }

        return $assignment;
    }
}

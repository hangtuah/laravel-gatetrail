<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations;

use HangTuah\GateTrail\AssignmentReference;
use HangTuah\GateTrail\Contracts\PermissionAssignmentContract;
use HangTuah\GateTrail\Exceptions\AssignmentNotActive;
use HangTuah\GateTrail\Models\PermissionAssignment;
use HangTuah\GateTrail\Support\ModelRegistry;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @internal
 */
final readonly class PermissionAssignmentInputResolver
{
    public function __construct(private ModelRegistry $models, private AssignmentIdentifierNormalizer $identifiers) {}

    public function resolve(PermissionAssignmentContract|AssignmentReference|string|int $input): PermissionAssignment&PermissionAssignmentContract
    {
        $class = $this->models->permissionAssignment();
        $instance = new $class;
        if ($instance->getConnection()->transactionLevel() === 0) {
            throw new LogicException('GateTrail permission assignments must be resolved inside a database transaction.');
        }
        $id = $input instanceof AssignmentReference ? $input->id : $input;
        if ($input instanceof PermissionAssignmentContract) {
            if (! $input instanceof Model || ! $input->exists) {
                throw AssignmentNotActive::forPermission('model');
            } $key = $input->getKey();
            if (! is_int($key) && ! is_string($key)) {
                throw AssignmentNotActive::forPermission('model');
            } $id = $key;
        }
        if (! is_int($id) && ! is_string($id)) {
            throw AssignmentNotActive::forPermission('model');
        }
        $key = $this->identifiers->normalize($instance, $id);
        if ($key === null) {
            throw AssignmentNotActive::forPermission($id);
        }
        $assignment = $class::query()->whereKey($key)->lockForUpdate()->first();
        if (! $assignment instanceof PermissionAssignmentContract) {
            throw AssignmentNotActive::forPermission($id);
        }

        return $assignment;
    }
}

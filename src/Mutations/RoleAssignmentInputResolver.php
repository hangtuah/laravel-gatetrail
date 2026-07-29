<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations;

use HangTuah\GateTrail\AssignmentReference;
use HangTuah\GateTrail\Contracts\RoleAssignmentContract;
use HangTuah\GateTrail\Exceptions\AssignmentNotActive;
use HangTuah\GateTrail\Models\RoleAssignment;
use HangTuah\GateTrail\Support\ModelRegistry;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final readonly class RoleAssignmentInputResolver
{
    public function __construct(
        private ModelRegistry $models,
        private AssignmentIdentifierNormalizer $identifiers,
    ) {}

    public function resolve(
        RoleAssignmentContract|AssignmentReference|string|int $input,
    ): RoleAssignment&RoleAssignmentContract {
        $class = $this->models->roleAssignment();
        $instance = new $class;

        if ($instance->getConnection()->transactionLevel() === 0) {
            throw new LogicException(
                'GateTrail role assignments must be resolved inside a database transaction.',
            );
        }

        $id = $this->identifier($input);
        $key = $this->identifiers->normalize($instance, $id);

        if ($key === null) {
            throw AssignmentNotActive::forRole($id);
        }

        $assignment = $class::query()
            ->whereKey($key)
            ->lockForUpdate()
            ->first();

        if (! $assignment instanceof RoleAssignmentContract) {
            throw AssignmentNotActive::forRole($id);
        }

        return $assignment;
    }

    private function identifier(
        RoleAssignmentContract|AssignmentReference|string|int $input,
    ): int|string {
        if ($input instanceof AssignmentReference) {
            return $input->id;
        }

        if ($input instanceof RoleAssignmentContract) {
            if (! $input instanceof Model || ! $input->exists) {
                throw AssignmentNotActive::forRole('model');
            }

            $id = $input->getKey();

            if (! is_int($id) && ! is_string($id)) {
                throw AssignmentNotActive::forRole('model');
            }

            return $id;
        }

        return $input;
    }
}

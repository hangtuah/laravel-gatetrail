<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations;

use HangTuah\GateTrail\AssignmentReference;
use HangTuah\GateTrail\Contracts\RolePermissionContract;
use HangTuah\GateTrail\Exceptions\AssignmentNotActive;
use HangTuah\GateTrail\Models\RolePermission;
use HangTuah\GateTrail\Support\ModelRegistry;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final readonly class RolePermissionInputResolver
{
    public function __construct(
        private ModelRegistry $models,
    ) {}

    public function resolve(
        RolePermissionContract|AssignmentReference|string|int $input,
    ): RolePermission&RolePermissionContract {
        $class = $this->models->rolePermission();
        $instance = new $class;

        if ($instance->getConnection()->transactionLevel() === 0) {
            throw new LogicException(
                'GateTrail role-permission assignments must be resolved inside a database transaction.',
            );
        }

        $id = $this->identifier($input);
        $assignment = $class::query()
            ->whereKey($id)
            ->lockForUpdate()
            ->first();

        if (! $assignment instanceof RolePermissionContract) {
            throw AssignmentNotActive::forRolePermission($id);
        }

        return $assignment;
    }

    private function identifier(
        RolePermissionContract|AssignmentReference|string|int $input,
    ): int|string {
        if ($input instanceof AssignmentReference) {
            return $input->id;
        }

        if ($input instanceof RolePermissionContract) {
            if (! $input instanceof Model || ! $input->exists) {
                throw AssignmentNotActive::forRolePermission('model');
            }

            $id = $input->getKey();

            if (! is_int($id) && ! is_string($id)) {
                throw AssignmentNotActive::forRolePermission('model');
            }

            return $id;
        }

        return $input;
    }
}

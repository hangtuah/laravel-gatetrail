<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Contracts;

use HangTuah\GateTrail\AssignmentPeriod;
use HangTuah\GateTrail\AssignmentReference;
use HangTuah\GateTrail\Models\PermissionAssignment;
use HangTuah\GateTrail\Models\RoleAssignment;
use HangTuah\GateTrail\Models\RolePermission;
use HangTuah\GateTrail\MutationResult;
use HangTuah\GateTrail\PermissionReference;
use HangTuah\GateTrail\RoleReference;
use HangTuah\GateTrail\SystemActor;
use Illuminate\Database\Eloquent\Model;

interface AssignmentManager
{
    /**
     * @param  array<string, mixed>  $metadata
     * @return MutationResult<Model&RoleAssignmentContract>
     */
    public function assignRole(
        Model $subject,
        RoleContract|RoleReference|string|int $role,
        Model|SystemActor $actor,
        string $reason,
        ?AssignmentPeriod $period = null,
        array $metadata = [],
    ): MutationResult;

    /**
     * @param  array<string, mixed>  $metadata
     * @return MutationResult<RoleAssignment>
     */
    public function revokeRole(
        RoleAssignmentContract|AssignmentReference|string|int $assignment,
        Model|SystemActor $actor,
        string $reason,
        array $metadata = [],
    ): MutationResult;

    /** @param array<string,mixed> $metadata
     * @return MutationResult<Model&PermissionAssignmentContract> */
    public function grantDirectPermission(Model $subject, PermissionContract|PermissionReference|string|int $permission, Model|SystemActor $actor, string $reason, ?AssignmentPeriod $period = null, array $metadata = []): MutationResult;

    /** @param array<string,mixed> $metadata
     * @return MutationResult<PermissionAssignment> */
    public function revokeDirectPermission(PermissionAssignmentContract|AssignmentReference|string|int $assignment, Model|SystemActor $actor, string $reason, array $metadata = []): MutationResult;

    /**
     * @param  array<string, mixed>  $metadata
     * @return MutationResult<Model&RolePermissionContract>
     */
    public function grantPermissionToRole(
        RoleContract|RoleReference|string|int $role,
        PermissionContract|PermissionReference|string|int $permission,
        Model|SystemActor $actor,
        string $reason,
        ?AssignmentPeriod $period = null,
        array $metadata = [],
    ): MutationResult;

    /**
     * @param  array<string, mixed>  $metadata
     * @return MutationResult<RolePermission>
     */
    public function revokePermissionFromRole(
        RolePermissionContract|AssignmentReference|string|int $assignment,
        Model|SystemActor $actor,
        string $reason,
        array $metadata = [],
    ): MutationResult;
}

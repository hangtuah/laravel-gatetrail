<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Managers;

use HangTuah\GateTrail\AssignmentPeriod;
use HangTuah\GateTrail\AssignmentReference;
use HangTuah\GateTrail\Contracts\AssignmentManager;
use HangTuah\GateTrail\Contracts\PermissionAssignmentContract;
use HangTuah\GateTrail\Contracts\PermissionContract;
use HangTuah\GateTrail\Contracts\RoleAssignmentContract;
use HangTuah\GateTrail\Contracts\RoleContract;
use HangTuah\GateTrail\Contracts\RolePermissionContract;
use HangTuah\GateTrail\Models\PermissionAssignment;
use HangTuah\GateTrail\Models\RoleAssignment;
use HangTuah\GateTrail\MutationResult;
use HangTuah\GateTrail\Mutations\Commands\AssignRoleCommand;
use HangTuah\GateTrail\Mutations\Commands\GrantDirectPermissionCommand;
use HangTuah\GateTrail\Mutations\Commands\GrantPermissionToRoleCommand;
use HangTuah\GateTrail\Mutations\Commands\RevokeDirectPermissionCommand;
use HangTuah\GateTrail\Mutations\Commands\RevokeRoleCommand;
use HangTuah\GateTrail\Mutations\Handlers\AssignRoleHandler;
use HangTuah\GateTrail\Mutations\Handlers\GrantDirectPermissionHandler;
use HangTuah\GateTrail\Mutations\Handlers\GrantPermissionToRoleHandler;
use HangTuah\GateTrail\Mutations\Handlers\RevokeDirectPermissionHandler;
use HangTuah\GateTrail\Mutations\Handlers\RevokeRoleHandler;
use HangTuah\GateTrail\PermissionReference;
use HangTuah\GateTrail\RoleReference;
use HangTuah\GateTrail\SystemActor;
use Illuminate\Database\Eloquent\Model;
use Psr\Clock\ClockInterface;

final readonly class DefaultAssignmentManager implements AssignmentManager
{
    public function __construct(
        private AssignRoleHandler $assignRole,
        private RevokeRoleHandler $revokeRole,
        private GrantDirectPermissionHandler $grantDirectPermission,
        private RevokeDirectPermissionHandler $revokeDirectPermission,
        private GrantPermissionToRoleHandler $grantPermissionToRole,
        private ClockInterface $clock,
    ) {}

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
    ): MutationResult {
        return $this->assignRole->handle(
            AssignRoleCommand::capture(
                subject: $subject,
                role: $role,
                actor: $actor,
                reason: $reason,
                period: $period ?? AssignmentPeriod::immediate(),
                metadata: $metadata,
                clock: $this->clock,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return MutationResult<RoleAssignment>
     */
    public function revokeRole(
        RoleAssignmentContract|AssignmentReference|string|int $assignment,
        Model|SystemActor $actor,
        string $reason,
        array $metadata = [],
    ): MutationResult {
        return $this->revokeRole->handle(
            RevokeRoleCommand::capture(
                assignment: $assignment,
                actor: $actor,
                reason: $reason,
                metadata: $metadata,
                clock: $this->clock,
            ),
        );
    }

    /** @param array<string,mixed> $metadata
     * @return MutationResult<Model&PermissionAssignmentContract> */
    public function grantDirectPermission(Model $subject, PermissionContract|PermissionReference|string|int $permission, Model|SystemActor $actor, string $reason, ?AssignmentPeriod $period = null, array $metadata = []): MutationResult
    {
        return $this->grantDirectPermission->handle(GrantDirectPermissionCommand::capture($subject, $permission, $actor, $reason, $period ?? AssignmentPeriod::immediate(), $metadata, $this->clock));
    }

    /** @param array<string,mixed> $metadata
     * @return MutationResult<PermissionAssignment> */
    public function revokeDirectPermission(PermissionAssignmentContract|AssignmentReference|string|int $assignment, Model|SystemActor $actor, string $reason, array $metadata = []): MutationResult
    {
        return $this->revokeDirectPermission->handle(RevokeDirectPermissionCommand::capture($assignment, $actor, $reason, $metadata, $this->clock));
    }

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
    ): MutationResult {
        return $this->grantPermissionToRole->handle(
            GrantPermissionToRoleCommand::capture(
                role: $role,
                permission: $permission,
                actor: $actor,
                reason: $reason,
                period: $period ?? AssignmentPeriod::immediate(),
                metadata: $metadata,
                clock: $this->clock,
            ),
        );
    }
}

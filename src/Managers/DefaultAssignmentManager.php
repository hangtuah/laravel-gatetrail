<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Managers;

use HangTuah\GateTrail\AssignmentPeriod;
use HangTuah\GateTrail\AssignmentReference;
use HangTuah\GateTrail\Contracts\AssignmentManager;
use HangTuah\GateTrail\Contracts\RoleAssignmentContract;
use HangTuah\GateTrail\Contracts\RoleContract;
use HangTuah\GateTrail\Models\RoleAssignment;
use HangTuah\GateTrail\MutationResult;
use HangTuah\GateTrail\Mutations\Commands\AssignRoleCommand;
use HangTuah\GateTrail\Mutations\Commands\RevokeRoleCommand;
use HangTuah\GateTrail\Mutations\Handlers\AssignRoleHandler;
use HangTuah\GateTrail\Mutations\Handlers\RevokeRoleHandler;
use HangTuah\GateTrail\RoleReference;
use HangTuah\GateTrail\SystemActor;
use Illuminate\Database\Eloquent\Model;
use Psr\Clock\ClockInterface;

final readonly class DefaultAssignmentManager implements AssignmentManager
{
    public function __construct(
        private AssignRoleHandler $assignRole,
        private RevokeRoleHandler $revokeRole,
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
}

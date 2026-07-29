<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Contracts;

use HangTuah\GateTrail\AssignmentPeriod;
use HangTuah\GateTrail\MutationResult;
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
}

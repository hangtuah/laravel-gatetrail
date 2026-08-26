<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Contracts;

use HangTuah\GateTrail\MutationResult;
use HangTuah\GateTrail\RoleChanges;
use HangTuah\GateTrail\RoleReference;
use HangTuah\GateTrail\SystemActor;
use Illuminate\Database\Eloquent\Model;

interface RoleManager
{
    /**
     * @param  array<string, mixed>  $metadata
     * @return MutationResult<Model&RoleContract>
     */
    public function create(
        string $name,
        string $guard,
        Model|SystemActor $actor,
        ?string $reason = null,
        ?string $description = null,
        array $metadata = [],
    ): MutationResult;

    /**
     * @param  array<string, mixed>  $metadata
     * @return MutationResult<Model&RoleContract>
     */
    public function update(
        RoleContract|RoleReference|string|int $role,
        RoleChanges $changes,
        Model|SystemActor $actor,
        ?string $reason = null,
        array $metadata = [],
    ): MutationResult;

    /**
     * Suspends one role definition globally while preserving every stored
     * subject-role and role-permission assignment.
     *
     * @param  array<string, mixed>  $metadata
     * @return MutationResult<Model&RoleContract>
     */
    public function deactivate(
        RoleContract|RoleReference|string|int $role,
        Model|SystemActor $actor,
        ?string $reason = null,
        array $metadata = [],
    ): MutationResult;

    /**
     * Reactivates one deactivated role definition. Preserved assignments
     * become eligible again on their own merits; revoked assignments stay
     * revoked.
     *
     * @param  array<string, mixed>  $metadata
     * @return MutationResult<Model&RoleContract>
     */
    public function restore(
        RoleContract|RoleReference|string|int $role,
        Model|SystemActor $actor,
        ?string $reason = null,
        array $metadata = [],
    ): MutationResult;
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Contracts;

use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\MutationResult;
use HangTuah\GateTrail\RoleChanges;
use HangTuah\GateTrail\RoleReference;
use HangTuah\GateTrail\SystemActor;
use Illuminate\Database\Eloquent\Model;

interface RoleManager
{
    /**
     * @param  array<string, mixed>  $metadata
     * @return MutationResult<Role>
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
}

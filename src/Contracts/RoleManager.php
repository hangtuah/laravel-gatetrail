<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Contracts;

use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\MutationResult;
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
}

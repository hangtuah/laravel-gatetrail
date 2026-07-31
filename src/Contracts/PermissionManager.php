<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Contracts;

use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\MutationResult;
use HangTuah\GateTrail\SystemActor;
use Illuminate\Database\Eloquent\Model;

interface PermissionManager
{
    /**
     * @param  array<string, mixed>  $metadata
     * @return MutationResult<Permission>
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

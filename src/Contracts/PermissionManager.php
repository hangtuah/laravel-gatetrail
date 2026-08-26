<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Contracts;

use HangTuah\GateTrail\MutationResult;
use HangTuah\GateTrail\PermissionChanges;
use HangTuah\GateTrail\PermissionReference;
use HangTuah\GateTrail\SystemActor;
use Illuminate\Database\Eloquent\Model;

interface PermissionManager
{
    /**
     * @param  array<string, mixed>  $metadata
     * @return MutationResult<Model&PermissionContract>
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
     * Applies an explicit permission-definition change. Name and guard are
     * immutable, so only the nullable description can change.
     *
     * @param  array<string, mixed>  $metadata
     * @return MutationResult<Model&PermissionContract>
     */
    public function update(
        PermissionContract|PermissionReference|string|int $permission,
        PermissionChanges $changes,
        Model|SystemActor $actor,
        ?string $reason = null,
        array $metadata = [],
    ): MutationResult;

    /**
     * Suspends one permission definition globally while preserving every
     * stored direct permission assignment and role-permission grant.
     *
     * @param  array<string, mixed>  $metadata
     * @return MutationResult<Model&PermissionContract>
     */
    public function deactivate(
        PermissionContract|PermissionReference|string|int $permission,
        Model|SystemActor $actor,
        ?string $reason = null,
        array $metadata = [],
    ): MutationResult;

    /**
     * Reactivates one deactivated permission definition. Preserved
     * assignments become eligible again on their own merits; revoked
     * assignments stay revoked.
     *
     * @param  array<string, mixed>  $metadata
     * @return MutationResult<Model&PermissionContract>
     */
    public function restore(
        PermissionContract|PermissionReference|string|int $permission,
        Model|SystemActor $actor,
        ?string $reason = null,
        array $metadata = [],
    ): MutationResult;
}

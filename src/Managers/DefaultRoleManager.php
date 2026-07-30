<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Managers;

use HangTuah\GateTrail\Contracts\RoleContract;
use HangTuah\GateTrail\Contracts\RoleManager;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\MutationResult;
use HangTuah\GateTrail\Mutations\Commands\CreateRoleCommand;
use HangTuah\GateTrail\Mutations\Commands\DeactivateRoleCommand;
use HangTuah\GateTrail\Mutations\Commands\RestoreRoleCommand;
use HangTuah\GateTrail\Mutations\Commands\UpdateRoleCommand;
use HangTuah\GateTrail\Mutations\Handlers\CreateRoleHandler;
use HangTuah\GateTrail\Mutations\Handlers\DeactivateRoleHandler;
use HangTuah\GateTrail\Mutations\Handlers\RestoreRoleHandler;
use HangTuah\GateTrail\Mutations\Handlers\UpdateRoleHandler;
use HangTuah\GateTrail\RoleChanges;
use HangTuah\GateTrail\RoleReference;
use HangTuah\GateTrail\SystemActor;
use Illuminate\Database\Eloquent\Model;
use Psr\Clock\ClockInterface;

final readonly class DefaultRoleManager implements RoleManager
{
    public function __construct(
        private CreateRoleHandler $createRole,
        private UpdateRoleHandler $updateRole,
        private DeactivateRoleHandler $deactivateRole,
        private RestoreRoleHandler $restoreRole,
        private ClockInterface $clock,
    ) {}

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
    ): MutationResult {
        return $this->createRole->handle(
            CreateRoleCommand::capture(
                name: $name,
                guard: $guard,
                actor: $actor,
                reason: $reason,
                description: $description,
                metadata: $metadata,
                clock: $this->clock,
            ),
        );
    }

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
    ): MutationResult {
        return $this->updateRole->handle(
            UpdateRoleCommand::capture(
                role: $role,
                changes: $changes,
                actor: $actor,
                reason: $reason,
                metadata: $metadata,
                clock: $this->clock,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return MutationResult<Model&RoleContract>
     */
    public function deactivate(
        RoleContract|RoleReference|string|int $role,
        Model|SystemActor $actor,
        ?string $reason = null,
        array $metadata = [],
    ): MutationResult {
        return $this->deactivateRole->handle(
            DeactivateRoleCommand::capture(
                role: $role,
                actor: $actor,
                reason: $reason,
                metadata: $metadata,
                clock: $this->clock,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return MutationResult<Model&RoleContract>
     */
    public function restore(
        RoleContract|RoleReference|string|int $role,
        Model|SystemActor $actor,
        ?string $reason = null,
        array $metadata = [],
    ): MutationResult {
        return $this->restoreRole->handle(
            RestoreRoleCommand::capture(
                role: $role,
                actor: $actor,
                reason: $reason,
                metadata: $metadata,
                clock: $this->clock,
            ),
        );
    }
}

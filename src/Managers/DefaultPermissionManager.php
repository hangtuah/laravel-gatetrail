<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Managers;

use HangTuah\GateTrail\Contracts\PermissionManager;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\MutationResult;
use HangTuah\GateTrail\Mutations\Commands\CreatePermissionCommand;
use HangTuah\GateTrail\Mutations\Handlers\CreatePermissionHandler;
use HangTuah\GateTrail\SystemActor;
use Illuminate\Database\Eloquent\Model;
use Psr\Clock\ClockInterface;

final readonly class DefaultPermissionManager implements PermissionManager
{
    public function __construct(
        private CreatePermissionHandler $createPermission,
        private ClockInterface $clock,
    ) {}

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
    ): MutationResult {
        return $this->createPermission->handle(
            CreatePermissionCommand::capture(
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
}

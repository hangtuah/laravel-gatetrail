<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations;

use HangTuah\GateTrail\Contracts\RoleContract;
use HangTuah\GateTrail\Exceptions\RoleNotFound;
use HangTuah\GateTrail\Guards\DefaultGuardResolver;
use HangTuah\GateTrail\RoleReference;
use HangTuah\GateTrail\Support\ModelRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;
use ReflectionMethod;

/**
 * Resolves and row-locks one deactivated role definition for restoration.
 *
 * This is the lifecycle mirror of {@see RoleDefinitionInputResolver}, which
 * returns active definitions only. Restore is the sole mutation that must
 * reach a soft-deleted row, so it owns a dedicated resolver instead of
 * weakening the active-only contract every other mutation depends on.
 *
 * @internal
 */
final readonly class DeactivatedRoleDefinitionInputResolver
{
    private const CONTEXT = 'role definition';

    public function __construct(
        private ModelRegistry $models,
        private DefaultGuardResolver $guards,
        private DefinitionIdentifierNormalizer $identifiers,
    ) {}

    /**
     * Only a string input is a name, and only that path consults the default
     * guard. Model, integer-ID, and explicit-reference inputs resolve directly
     * by primary key and derive their guard from the persisted row.
     */
    public function resolve(
        RoleContract|RoleReference|string|int $input,
    ): Model&RoleContract {
        $class = $this->models->role();
        $instance = new $class;

        if ($instance->getConnection()->transactionLevel() === 0) {
            throw new LogicException(
                'GateTrail role definitions must be resolved inside a database transaction.',
            );
        }

        if (is_string($input)) {
            return $this->byName(
                $input,
                $this->guards->resolve(self::CONTEXT),
            );
        }

        return $this->byKey($input, $instance);
    }

    private function byName(
        string $name,
        string $guard,
    ): Model&RoleContract {
        $class = $this->models->role();
        $role = $class::query()
            ->withTrashed()
            ->where('name', $name)
            ->where('guard_name', $guard)
            ->lockForUpdate()
            ->first();

        if (! $role instanceof RoleContract || ! $this->isDeactivated($role)) {
            throw RoleNotFound::forGuard($guard, 'name');
        }

        return $role;
    }

    private function byKey(
        RoleContract|RoleReference|int $input,
        Model $instance,
    ): Model&RoleContract {
        $class = $this->models->role();
        $referenceType = $input instanceof RoleContract ? 'model' : 'ID';
        $key = $this->identifiers->normalize(
            $instance,
            $this->identifier($input, $referenceType),
        );

        if ($key === null) {
            throw RoleNotFound::forReference($referenceType);
        }

        $role = $class::query()
            ->withTrashed()
            ->whereKey($key)
            ->lockForUpdate()
            ->first();

        if (! $role instanceof RoleContract) {
            throw RoleNotFound::forReference($referenceType);
        }

        if (! $this->isDeactivated($role)) {
            throw $this->active($role, $referenceType);
        }

        return $role;
    }

    private function identifier(
        RoleContract|RoleReference|int $input,
        string $referenceType,
    ): int|string {
        if ($input instanceof RoleReference) {
            return $input->id;
        }

        if (is_int($input)) {
            return $input;
        }

        if (! $input instanceof Model || ! $input->exists) {
            throw RoleNotFound::forReference($referenceType);
        }

        $id = $input->getKey();

        if (! is_int($id) && ! is_string($id)) {
            throw RoleNotFound::forReference($referenceType);
        }

        return $id;
    }

    private function active(
        Model $role,
        string $referenceType,
    ): RoleNotFound {
        $guard = $role->getAttribute('guard_name');

        return is_string($guard) && trim($guard) !== ''
            ? RoleNotFound::forGuard(trim($guard), $referenceType)
            : RoleNotFound::forReference($referenceType);
    }

    private function isDeactivated(Model $role): bool
    {
        if (! in_array(
            SoftDeletes::class,
            class_uses_recursive($role),
            true,
        )) {
            return false;
        }

        return (new ReflectionMethod($role, 'trashed'))
            ->invoke($role) === true;
    }
}

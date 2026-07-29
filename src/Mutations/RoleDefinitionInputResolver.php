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

final readonly class RoleDefinitionInputResolver
{
    public function __construct(
        private ModelRegistry $models,
        private DefaultGuardResolver $guards,
        private DefinitionIdentifierNormalizer $identifiers,
    ) {}

    /**
     * Resolves and row-locks one active role definition. String inputs are
     * role names resolved in GateTrail's default guard; model, integer-ID and
     * explicit-reference inputs resolve the identified definition directly.
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

        $guard = $this->guards->resolve();
        $role = is_string($input)
            ? $this->byName($input, $guard)
            : $this->byKey($input, $guard, $instance);

        if (! $role instanceof RoleContract || $this->isDeactivated($role)) {
            throw RoleNotFound::forGuard(
                $guard,
                $this->referenceType($input),
            );
        }

        return $role;
    }

    private function byName(string $name, string $guard): ?Model
    {
        $class = $this->models->role();

        return $class::query()
            ->withTrashed()
            ->where('name', $name)
            ->where('guard_name', $guard)
            ->lockForUpdate()
            ->first();
    }

    private function byKey(
        RoleContract|RoleReference|int $input,
        string $guard,
        Model $instance,
    ): ?Model {
        $class = $this->models->role();
        $id = $this->identifier($input, $guard);
        $key = $this->identifiers->normalize($instance, $id);

        if ($key === null) {
            throw RoleNotFound::forGuard(
                $guard,
                $this->referenceType($input),
            );
        }

        return $class::query()
            ->withTrashed()
            ->whereKey($key)
            ->lockForUpdate()
            ->first();
    }

    private function identifier(
        RoleContract|RoleReference|int $input,
        string $guard,
    ): int|string {
        if ($input instanceof RoleReference) {
            return $input->id;
        }

        if (is_int($input)) {
            return $input;
        }

        if (! $input instanceof Model || ! $input->exists) {
            throw RoleNotFound::forGuard($guard, 'model');
        }

        $id = $input->getKey();

        if (! is_int($id) && ! is_string($id)) {
            throw RoleNotFound::forGuard($guard, 'model');
        }

        return $id;
    }

    private function referenceType(
        RoleContract|RoleReference|string|int $input,
    ): string {
        if (is_string($input)) {
            return 'name';
        }

        return $input instanceof RoleContract ? 'model' : 'ID';
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

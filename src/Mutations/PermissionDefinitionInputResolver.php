<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations;

use HangTuah\GateTrail\Contracts\PermissionContract;
use HangTuah\GateTrail\Exceptions\PermissionNotFound;
use HangTuah\GateTrail\Guards\DefaultGuardResolver;
use HangTuah\GateTrail\PermissionReference;
use HangTuah\GateTrail\Support\ModelRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;
use ReflectionMethod;

final readonly class PermissionDefinitionInputResolver
{
    private const CONTEXT = 'permission definition';

    public function __construct(
        private ModelRegistry $models,
        private DefaultGuardResolver $guards,
        private DefinitionIdentifierNormalizer $identifiers,
    ) {}

    /**
     * Resolves and row-locks one active permission definition.
     *
     * Only a string input is a name, and only that path consults the default
     * guard. Model, integer-ID, and explicit-reference inputs resolve directly
     * by primary key and derive their guard from the persisted row.
     */
    public function resolve(
        PermissionContract|PermissionReference|string|int $input,
    ): Model&PermissionContract {
        $class = $this->models->permission();
        $instance = new $class;

        if ($instance->getConnection()->transactionLevel() === 0) {
            throw new LogicException(
                'GateTrail permission definitions must be resolved inside a database transaction.',
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
    ): Model&PermissionContract {
        $class = $this->models->permission();
        $permission = $class::query()
            ->withTrashed()
            ->where('name', $name)
            ->where('guard_name', $guard)
            ->lockForUpdate()
            ->first();

        if (
            ! $permission instanceof PermissionContract
            || $this->isDeactivated($permission)
        ) {
            throw PermissionNotFound::forGuard($guard, 'name');
        }

        return $permission;
    }

    private function byKey(
        PermissionContract|PermissionReference|int $input,
        Model $instance,
    ): Model&PermissionContract {
        $class = $this->models->permission();
        $referenceType = $input instanceof PermissionContract
            ? 'model'
            : 'ID';
        $key = $this->identifiers->normalize(
            $instance,
            $this->identifier($input, $referenceType),
        );

        if ($key === null) {
            throw PermissionNotFound::forReference($referenceType);
        }

        $permission = $class::query()
            ->withTrashed()
            ->whereKey($key)
            ->lockForUpdate()
            ->first();

        if (! $permission instanceof PermissionContract) {
            throw PermissionNotFound::forReference($referenceType);
        }

        if ($this->isDeactivated($permission)) {
            throw $this->deactivated($permission, $referenceType);
        }

        return $permission;
    }

    private function identifier(
        PermissionContract|PermissionReference|int $input,
        string $referenceType,
    ): int|string {
        if ($input instanceof PermissionReference) {
            return $input->id;
        }

        if (is_int($input)) {
            return $input;
        }

        if (! $input instanceof Model || ! $input->exists) {
            throw PermissionNotFound::forReference($referenceType);
        }

        $id = $input->getKey();

        if (! is_int($id) && ! is_string($id)) {
            throw PermissionNotFound::forReference($referenceType);
        }

        return $id;
    }

    private function deactivated(
        Model $permission,
        string $referenceType,
    ): PermissionNotFound {
        $guard = $permission->getAttribute('guard_name');

        return is_string($guard) && trim($guard) !== ''
            ? PermissionNotFound::forGuard(trim($guard), $referenceType)
            : PermissionNotFound::forReference($referenceType);
    }

    private function isDeactivated(Model $permission): bool
    {
        if (! in_array(
            SoftDeletes::class,
            class_uses_recursive($permission),
            true,
        )) {
            return false;
        }

        return (new ReflectionMethod($permission, 'trashed'))
            ->invoke($permission) === true;
    }
}

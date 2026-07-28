<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Assignments;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use HangTuah\GateTrail\Support\ModelRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final readonly class AssignmentConflictQuery
{
    public function __construct(
        private ModelRegistry $models,
        private AssignmentPeriodOverlap $overlap,
    ) {}

    /**
     * @return Collection<int, Model>
     */
    public function find(
        AssignmentScope $scope,
        EffectiveAssignmentPeriod $candidate,
    ): Collection {
        $model = $this->modelFor($scope->kind());
        $instance = new $model;

        if ($instance->getConnection()->transactionLevel() === 0) {
            throw new LogicException(
                'GateTrail assignment conflicts must be queried inside a database transaction.',
            );
        }

        $query = $model::query();

        foreach ($scope->components() as $column => $value) {
            $query->where($column, $value);
        }

        $query->whereNull('revoked_at');
        $this->applyOverlapWindow($query, $candidate);

        /** @var Collection<int, Model> $assignments */
        $assignments = $query->lockForUpdate()->get();

        return $assignments
            ->filter(
                fn (Model $assignment): bool => $this->overlap->overlaps(
                    $this->effectivePeriod($assignment),
                    $candidate,
                ),
            )
            ->values();
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applyOverlapWindow(
        Builder $query,
        EffectiveAssignmentPeriod $candidate,
    ): void {
        if ($candidate->expiresAt() !== null) {
            $query->where(
                function (Builder $query) use ($candidate): void {
                    $query
                        ->where('starts_at', '<', $candidate->expiresAt())
                        ->orWhere(
                            function (Builder $query) use ($candidate): void {
                                $query
                                    ->whereNull('starts_at')
                                    ->where(
                                        'assigned_at',
                                        '<',
                                        $candidate->expiresAt(),
                                    );
                            },
                        );
                },
            );
        }

        $query->where(
            function (Builder $query) use ($candidate): void {
                $query
                    ->whereNull('expires_at')
                    ->orWhere(
                        'expires_at',
                        '>',
                        $candidate->startsAt(),
                    );
            },
        );
    }

    private function effectivePeriod(
        Model $assignment,
    ): EffectiveAssignmentPeriod {
        return EffectiveAssignmentPeriod::fromStored(
            $this->timestamp($assignment, 'assigned_at'),
            $this->nullableTimestamp($assignment, 'starts_at'),
            $this->nullableTimestamp($assignment, 'expires_at'),
        );
    }

    private function timestamp(
        Model $assignment,
        string $attribute,
    ): DateTimeInterface {
        $timestamp = $this->nullableTimestamp($assignment, $attribute);

        if ($timestamp === null) {
            throw new LogicException(
                sprintf(
                    'GateTrail assignment attribute [%s] cannot be null.',
                    $attribute,
                ),
            );
        }

        return $timestamp;
    }

    private function nullableTimestamp(
        Model $assignment,
        string $attribute,
    ): ?DateTimeInterface {
        $value = $assignment->getAttribute($attribute);

        if ($value === null || $value instanceof DateTimeInterface) {
            return $value;
        }

        if (is_string($value)) {
            return new DateTimeImmutable(
                $value,
                new DateTimeZone('UTC'),
            );
        }

        throw new LogicException(
            sprintf(
                'GateTrail assignment attribute [%s] must be a date-time value.',
                $attribute,
            ),
        );
    }

    /**
     * @return class-string<Model>
     */
    private function modelFor(AssignmentKind $kind): string
    {
        return match ($kind) {
            AssignmentKind::Role => $this->models->roleAssignment(),
            AssignmentKind::Permission => $this->models->permissionAssignment(),
            AssignmentKind::RolePermission => $this->models->rolePermission(),
        };
    }
}

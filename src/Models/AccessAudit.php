<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

class AccessAudit extends GateTrailModel
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event',
        'actor_type',
        'actor_id',
        'subject_type',
        'subject_id',
        'target_type',
        'target_id',
        'before',
        'after',
        'reason',
        'context',
        'ip_address',
        'user_agent',
        'request_id',
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Audit rows are immutable after insertion through GateTrail's model API.
     *
     * @param  Builder<static>  $query
     */
    final protected function performUpdate(Builder $query): never
    {
        $this->rejectMutation();
    }

    final protected function performDeleteOnModel(): never
    {
        $this->rejectMutation();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    final protected function incrementOrDecrement(
        $column,
        $amount,
        $extra,
        $method,
    ): never {
        $this->rejectMutation();
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     */
    final protected function incrementOrDecrementEach(
        array $columns,
        array $extra,
        string $method,
    ): never {
        $this->rejectMutation();
    }

    /**
     * Laravel 12 does not route every multi-column increment through the
     * model hook above. These public shims preserve the append-only contract
     * before Laravel's dynamic call can reach an Eloquent builder instead.
     *
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     *
     * @internal
     */
    final public function incrementEach(array $columns, array $extra = []): never
    {
        $this->rejectMutation();
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     *
     * @internal
     */
    final public function decrementEach(array $columns, array $extra = []): never
    {
        $this->rejectMutation();
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     *
     * @internal
     */
    final public function incrementEachQuietly(array $columns, array $extra = []): never
    {
        $this->rejectMutation();
    }

    /**
     * @param  array<string, float|int>  $columns
     * @param  array<string, mixed>  $extra
     *
     * @internal
     */
    final public function decrementEachQuietly(array $columns, array $extra = []): never
    {
        $this->rejectMutation();
    }

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'context' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected function tableKey(): string
    {
        return 'access_audits';
    }

    private function rejectMutation(): never
    {
        throw new LogicException(
            'GateTrail access audit records are append-only and cannot be changed or deleted.',
        );
    }
}

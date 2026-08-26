<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Models\Concerns;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Stable lifecycle query surface inherited by all assignment models.
 *
 * All four scopes are Tier 2 extension API. Effective-access resolution also
 * depends on activeAt(), so its half-open lifecycle semantics constrain both
 * consumer queries and GateTrail's authorization result.
 */
trait HasAssignmentPeriodScopes
{
    /**
     * Limit the query to assignments that are active at the given time.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActiveAt(
        Builder $query,
        DateTimeInterface $time,
    ): Builder {
        return $query
            ->whereNull('revoked_at')
            ->where(function (Builder $query) use ($time): void {
                $query
                    ->where('starts_at', '<=', $time)
                    ->orWhere(function (Builder $query) use ($time): void {
                        $query
                            ->whereNull('starts_at')
                            ->where('assigned_at', '<=', $time);
                    });
            })
            ->where(function (Builder $query) use ($time): void {
                $query
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $time);
            });
    }

    /**
     * Limit the query to assignments that have not started at the given time.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeScheduledAt(
        Builder $query,
        DateTimeInterface $time,
    ): Builder {
        return $query
            ->whereNull('revoked_at')
            ->where(function (Builder $query) use ($time): void {
                $query
                    ->where('starts_at', '>', $time)
                    ->orWhere(function (Builder $query) use ($time): void {
                        $query
                            ->whereNull('starts_at')
                            ->where('assigned_at', '>', $time);
                    });
            });
    }

    /**
     * Limit the query to assignments that have expired at the given time.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeExpiredAt(
        Builder $query,
        DateTimeInterface $time,
    ): Builder {
        return $query
            ->whereNull('revoked_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $time);
    }

    /**
     * Limit the query to revoked assignments.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeRevoked(Builder $query): Builder
    {
        return $query->whereNotNull('revoked_at');
    }
}

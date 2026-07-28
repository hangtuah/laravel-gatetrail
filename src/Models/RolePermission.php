<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Models;

use HangTuah\GateTrail\Contracts\RolePermissionContract;
use HangTuah\GateTrail\Models\Concerns\HasAssignmentPeriodScopes;
use HangTuah\GateTrail\Support\ModelRegistry;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RolePermission extends GateTrailModel implements RolePermissionContract
{
    use HasAssignmentPeriodScopes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'role_id',
        'permission_id',
        'assigned_at',
        'assigned_by_type',
        'assigned_by_id',
        'assignment_reason',
        'starts_at',
        'expires_at',
        'revoked_at',
        'revoked_by_type',
        'revoked_by_id',
        'revocation_reason',
    ];

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        $relation = $this->belongsTo(
            app(ModelRegistry::class)->role(),
            'role_id',
        );

        $relation->withTrashed();

        return $relation;
    }

    /**
     * @return BelongsTo<Permission, $this>
     */
    public function permission(): BelongsTo
    {
        $relation = $this->belongsTo(
            app(ModelRegistry::class)->permission(),
            'permission_id',
        );

        $relation->withTrashed();

        return $relation;
    }

    protected function casts(): array
    {
        return [
            'assigned_at' => 'immutable_datetime',
            'starts_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected function tableKey(): string
    {
        return 'role_permissions';
    }
}

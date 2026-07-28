<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Models;

use HangTuah\GateTrail\Contracts\PermissionContract;
use HangTuah\GateTrail\Support\ModelRegistry;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Permission extends GateTrailModel implements PermissionContract
{
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'guard_name',
        'description',
    ];

    /**
     * @return HasMany<PermissionAssignment, $this>
     */
    public function subjectAssignments(): HasMany
    {
        return $this->hasMany(
            app(ModelRegistry::class)->permissionAssignment(),
            'permission_id',
        );
    }

    /**
     * @return HasMany<RolePermission, $this>
     */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(
            app(ModelRegistry::class)->rolePermission(),
            'permission_id',
        );
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    protected function tableKey(): string
    {
        return 'permissions';
    }
}

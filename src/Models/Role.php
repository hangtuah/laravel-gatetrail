<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Models;

use HangTuah\GateTrail\Contracts\RoleContract;
use HangTuah\GateTrail\Support\ModelRegistry;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Role extends GateTrailModel implements RoleContract
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
     * @return HasMany<RoleAssignment, $this>
     */
    public function subjectAssignments(): HasMany
    {
        return $this->hasMany(
            app(ModelRegistry::class)->roleAssignment(),
            'role_id',
        );
    }

    /**
     * @return HasMany<RolePermission, $this>
     */
    public function permissionAssignments(): HasMany
    {
        return $this->hasMany(
            app(ModelRegistry::class)->rolePermission(),
            'role_id',
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
        return 'roles';
    }
}

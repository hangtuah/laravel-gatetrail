<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Models;

use HangTuah\GateTrail\Contracts\RoleAssignmentContract;
use HangTuah\GateTrail\Support\ModelRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class RoleAssignment extends GateTrailModel implements RoleAssignmentContract
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'subject_type',
        'subject_id',
        'role_id',
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
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

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

    protected function casts(): array
    {
        return $this->assignmentCasts();
    }

    protected function tableKey(): string
    {
        return 'role_assignments';
    }

    /**
     * @return array<string, string>
     */
    private function assignmentCasts(): array
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
}

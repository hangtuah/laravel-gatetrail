<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

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
}

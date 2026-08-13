<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A subject whose primary key is a non-incrementing string.
 *
 * It exists so the identifier rules can be exercised against the key shapes a
 * real application produces — numeric strings, leading zeros, UUIDs, ULIDs and
 * out-of-range values — without weakening the production types.
 */
final class StringKeyedSubject extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'string_keyed_subjects';

    protected $keyType = 'string';

    protected $guarded = [];
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A persisted subject whose morph alias is controlled by the test.
 *
 * `$morphType` is settable so one fixture covers an empty alias, a
 * whitespace-only alias and a valid alias that must survive untouched — all
 * three of which would otherwise be written straight into `subject_type`.
 *
 * The key is a fixed valid value, so a morph-type case can never fail for an
 * identifier reason instead.
 */
final class EmptyMorphSubject extends Model
{
    public $timestamps = false;

    public string $morphType = '';

    protected $table = 'test_subjects';

    protected $guarded = [];

    public static function persistedWith(string $morphType): self
    {
        $subject = new self;
        $subject->morphType = $morphType;
        $subject->exists = true;

        return $subject;
    }

    public function getKey(): mixed
    {
        return 5;
    }

    public function getMorphClass(): string
    {
        return $this->morphType;
    }
}

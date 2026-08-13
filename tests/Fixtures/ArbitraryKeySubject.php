<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A persisted subject whose primary key can be any PHP value.
 *
 * Eloquent's `getKey()` is declared `mixed`, so a model with a cast, an
 * accessor or a custom key strategy really can return a float, a bool or an
 * object. This fixture reproduces those shapes directly, which keeps the
 * production types strict instead of widening them to make a key type
 * testable.
 *
 * The key is returned from a property rather than the attribute bag, so
 * identifier-shape cases stay independent of dirty-key detection.
 */
final class ArbitraryKeySubject extends Model
{
    public $timestamps = false;

    public mixed $keyValue = null;

    protected $table = 'test_subjects';

    protected $guarded = [];

    public static function persistedWith(mixed $key): self
    {
        $subject = new self;
        $subject->keyValue = $key;
        $subject->exists = true;

        return $subject;
    }

    public function getKey(): mixed
    {
        return $this->keyValue;
    }
}

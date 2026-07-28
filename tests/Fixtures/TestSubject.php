<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Fixtures;

use HangTuah\GateTrail\Concerns\HasGateTrail;
use Illuminate\Database\Eloquent\Model;

final class TestSubject extends Model
{
    use HasGateTrail;

    public $timestamps = false;

    protected $table = 'test_subjects';

    protected $guarded = [];

    protected string $gateTrailGuardName = 'web';
}

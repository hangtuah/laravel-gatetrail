<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Fixtures;

use HangTuah\GateTrail\Concerns\HasGateTrail;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\Authorizable;

final class AuthorizableSubject extends Model implements AuthenticatableContract, AuthorizableContract
{
    use Authenticatable;
    use Authorizable;
    use HasGateTrail;

    public $timestamps = false;

    protected $table = 'test_subjects';

    protected $guarded = [];

    protected string $gateTrailGuardName = 'web';
}

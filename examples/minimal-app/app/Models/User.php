<?php

declare(strict_types=1);

namespace App\Models;

use HangTuah\GateTrail\Concerns\HasGateTrail;
use Illuminate\Foundation\Auth\User as Authenticatable;

final class User extends Authenticatable
{
    use HasGateTrail;

    protected string $gateTrailGuardName = 'web';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}

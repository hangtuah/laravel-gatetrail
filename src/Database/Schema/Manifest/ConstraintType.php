<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Manifest;

enum ConstraintType: string
{
    case Check = 'check';
    case Foreign = 'foreign';
    case Primary = 'primary';
    case Unique = 'unique';
}

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Manifest;

enum ColumnType: string
{
    case BigInteger = 'big_integer';
    case DateTime = 'date_time';
    case FixedString = 'fixed_string';
    case String = 'string';
    case Text = 'text';
}

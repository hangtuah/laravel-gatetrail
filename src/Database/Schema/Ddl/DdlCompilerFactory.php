<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Ddl;

use HangTuah\GateTrail\Database\Schema\Ddl\MySql\MySqlDdlCompiler;
use HangTuah\GateTrail\Exceptions\DdlCompilerUnavailable;

final class DdlCompilerFactory
{
    public function forDriver(string $driver): DdlCompiler
    {
        return match ($driver) {
            'mysql' => new MySqlDdlCompiler,
            default => throw DdlCompilerUnavailable::forDriver($driver),
        };
    }
}

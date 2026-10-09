<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Laravel\Middleware;

/** @internal */
enum MiddlewareMatchMode
{
    case One;
    case Any;
    case All;
}

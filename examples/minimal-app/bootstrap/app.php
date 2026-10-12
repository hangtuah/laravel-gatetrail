<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(static function (Middleware $middleware): void {
        // GateTrail's middleware aliases are registered by package discovery.
    })
    ->withExceptions(static function (Exceptions $exceptions): void {
        // The example intentionally uses Laravel's default exception handling.
    })
    ->create();

<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;

final readonly class RequireRole
{
    public function __construct(
        private RoleMiddlewareEvaluator $evaluator,
    ) {}

    public function handle(
        Request $request,
        Closure $next,
        string ...$roles,
    ): mixed {
        $this->evaluator->authorize(
            $request,
            'gatetrail.role',
            MiddlewareMatchMode::One,
            array_values($roles),
        );

        return $next($request);
    }
}

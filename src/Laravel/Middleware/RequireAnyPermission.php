<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;

final readonly class RequireAnyPermission
{
    public function __construct(
        private PermissionMiddlewareEvaluator $evaluator,
    ) {}

    public function handle(
        Request $request,
        Closure $next,
        string ...$permissions,
    ): mixed {
        $this->evaluator->authorize(
            $request,
            'gatetrail.permission.any',
            MiddlewareMatchMode::Any,
            array_values($permissions),
        );

        return $next($request);
    }
}

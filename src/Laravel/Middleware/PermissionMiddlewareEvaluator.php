<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Laravel\Middleware;

use HangTuah\GateTrail\Contracts\PermissionResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

/** @internal */
final readonly class PermissionMiddlewareEvaluator
{
    public function __construct(
        private PermissionResolver $permissions,
        private MiddlewareParameterNormalizer $parameters,
        private MiddlewareSubjectResolver $subjects,
        private MiddlewareSetMatcher $matcher,
    ) {}

    /** @param list<string> $parameters */
    public function authorize(
        Request $request,
        string $alias,
        MiddlewareMatchMode $mode,
        array $parameters,
    ): void {
        $names = $this->parameters->normalize($alias, $mode, $parameters);
        $subject = $this->subjects->resolve($request);
        /** @var list<string> $effective */
        $effective = $this->permissions
            ->effectivePermissions($subject)
            ->map(static fn ($permission): string => $permission->name)
            ->values()
            ->all();

        if (! $this->matcher->matches($mode, $names, $effective)) {
            throw new AuthorizationException;
        }
    }
}

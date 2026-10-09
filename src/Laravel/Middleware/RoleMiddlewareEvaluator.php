<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Laravel\Middleware;

use HangTuah\GateTrail\Contracts\RoleResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

/** @internal */
final readonly class RoleMiddlewareEvaluator
{
    public function __construct(
        private RoleResolver $roles,
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
        $effective = $this->roles
            ->effectiveRoles($subject)
            ->map(static fn ($role): string => $role->name)
            ->values()
            ->all();

        if (! $this->matcher->matches($mode, $names, $effective)) {
            throw new AuthorizationException;
        }
    }
}

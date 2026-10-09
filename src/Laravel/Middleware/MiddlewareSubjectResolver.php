<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Laravel\Middleware;

use HangTuah\GateTrail\Exceptions\InvalidSubject;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** @internal */
final class MiddlewareSubjectResolver
{
    public function resolve(Request $request): Model
    {
        $subject = ($request->getUserResolver())();

        if ($subject === null) {
            throw new AuthenticationException;
        }

        if (! $subject instanceof Model) {
            throw InvalidSubject::invalidMorphType(get_debug_type($subject));
        }

        return $subject;
    }
}

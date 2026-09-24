<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Restrict a route to one or more roles.
     *
     * Administrators always pass this check.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        abort_unless($user, 401);

        if ($user->isAdministrator() || $user->hasRole(...$roles)) {
            return $next($request);
        }

        abort(403, 'This action is unauthorized.');
    }
}

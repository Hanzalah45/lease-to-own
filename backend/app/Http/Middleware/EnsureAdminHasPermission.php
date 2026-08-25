<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminHasPermission
{
    /**
     * Usage: ->middleware('permission:risk_assessment')
     * Any one of the listed permissions is enough to pass.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isAdmin()) {
            abort(403, 'You do not have access to this resource.');
        }

        $hasAny = collect($permissions)->contains(fn (string $permission) => $user->hasAdminPermission($permission));

        if (! $hasAny) {
            abort(403, 'You do not have permission to perform this action.');
        }

        return $next($request);
    }
}

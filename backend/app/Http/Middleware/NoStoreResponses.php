<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every answer from the API carries customer, payment or QuickBooks data, so no browser or proxy keeps a copy of it.
 * Intuit's security requirements for apps that connect to QuickBooks ask for "no-cache" and "no-store" on pages
 * that hold sensitive data, in place of Laravel's default "no-cache, private".
 */
class NoStoreResponses
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->is('api/*')) {
            $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate, private');
        }

        return $response;
    }
}

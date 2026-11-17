<?php

use App\Http\Middleware\EnsureAdminHasPermission;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\NoStoreResponses;
use App\Services\EmailLogger;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'permission' => EnsureAdminHasPermission::class,
        ]);

        // Global rather than in the api group: a refused sign-in (401) is answered before the group's own middleware gets to run.
        $middleware->append(NoStoreResponses::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // An email the service refused or could not be reached for: settle the email log
        // row with the service's own message, then carry on reporting as usual.
        $exceptions->report(function (TransportExceptionInterface $e) {
            app(EmailLogger::class)->failed($e);
        });
    })->create();

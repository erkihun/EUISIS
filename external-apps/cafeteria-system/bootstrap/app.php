<?php

declare(strict_types=1);

use CafeteriaSystem\Http\Middleware\HandleInertiaRequests;
use CafeteriaSystem\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            // Before Inertia, so the shared props carry the applied locale.
            SetLocale::class,
            HandleInertiaRequests::class,
        ]);

        // This application authenticates against its own guard only.
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

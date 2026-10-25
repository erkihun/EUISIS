<?php

use App\Http\Middleware\EnsureApiClient;
use App\Http\Middleware\EnsureCoreUser;
use App\Http\Middleware\EnsureOrganizationScope;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RecordSlowRequests;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\RequireMfa;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetClientLocale;
use App\Http\Middleware\TrackSessionActivity;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(RequestId::class);
        $middleware->append(SecurityHeaders::class);
        // Slow requests are logged and counted for System Health (ADR 0011).
        $middleware->append(RecordSlowRequests::class);
        $middleware->alias(['core.user' => EnsureCoreUser::class, 'core.client' => EnsureApiClient::class]);
        // The EUISIS screens: the page's language, the idle sign-out and the shared props, on the browser routes.
        $middleware->encryptCookies(except: [SetClientLocale::COOKIE]);
        $middleware->web(append: [SetClientLocale::class, TrackSessionActivity::class, EnsurePasswordChanged::class, RequireMfa::class, EnsureOrganizationScope::class, HandleInertiaRequests::class]);
        // An account without a scope is told so (403) before any record is resolved by its route.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureCoreUser::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: RequireMfa::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureOrganizationScope::class);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('core.dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || ($request->expectsJson() && ! $request->header('X-Inertia')),
        );

        // The integration API error contract: {message, code, errors}, never internals.
        $integration = fn (Request $request): bool => $request->is('api/v1/integration/*');
        $exceptions->render(fn (ValidationException $e, Request $request) => $integration($request)
            ? response()->json(['message' => $e->getMessage(), 'code' => 'VALIDATION_FAILED', 'errors' => $e->errors()], 422) : null);
        $exceptions->render(fn (NotFoundHttpException $e, Request $request) => $integration($request)
            ? response()->json(['message' => 'Not found.', 'code' => 'NOT_FOUND', 'errors' => (object) []], 404) : null);
        $exceptions->render(fn (AuthenticationException $e, Request $request) => $integration($request)
            ? response()->json(['message' => 'Unauthenticated.', 'code' => 'UNAUTHENTICATED', 'errors' => (object) []], 401) : null);
        $exceptions->render(fn (ThrottleRequestsException $e, Request $request) => $integration($request)
            ? response()->json(['message' => 'Too many requests.', 'code' => 'RATE_LIMITED', 'errors' => (object) []], 429, $e->getHeaders()) : null);
    })->create();

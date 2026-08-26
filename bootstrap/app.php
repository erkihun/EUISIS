<?php

use App\Http\Middleware\EnforceIdempotencyKey;
use App\Http\Middleware\EnsureAdminAccess;
use App\Http\Middleware\EnsureApiScope;
use App\Http\Middleware\EnsureCafeteriaPortalUser;
use App\Http\Middleware\EnsureCafeteriaProviderAssigned;
use App\Http\Middleware\EnsureMfaNotRequired;
use App\Http\Middleware\EnsureProviderHasActiveAssignment;
use App\Http\Middleware\EnsureProviderPortalUser;
use App\Http\Middleware\EnsureProviderServiceEnabled;
use App\Http\Middleware\EnsureProviderServiceScope;
use App\Http\Middleware\ExternalApplicationGate;
use App\Http\Middleware\ForcePasswordChange;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequestCorrelationId;
use App\Http\Middleware\RequireMfa;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetCafeteriaPortalContext;
use App\Http\Middleware\SetProviderPortalContext;
use App\Services\ErrorLoggingService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend(RequestCorrelationId::class);
        $middleware->prepend(SecurityHeaders::class);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'api.scope' => EnsureApiScope::class,
            'api.external' => ExternalApplicationGate::class,
            'api.idempotency' => EnforceIdempotencyKey::class,
            'provider.scope' => EnsureProviderServiceScope::class,
            'provider.portal' => EnsureProviderPortalUser::class,
            'provider.service' => EnsureProviderServiceEnabled::class,
            'provider.portal.context' => SetProviderPortalContext::class,
            'provider.assigned' => EnsureProviderHasActiveAssignment::class,
            'cafeteria.portal' => EnsureCafeteriaPortalUser::class,
            'cafeteria.provider.assigned' => EnsureCafeteriaProviderAssigned::class,
            'cafeteria.portal.context' => SetCafeteriaPortalContext::class,
            'admin.access' => EnsureAdminAccess::class,
            'mfa' => RequireMfa::class,
            'force.password' => ForcePasswordChange::class,
            'mfa.setup' => EnsureMfaNotRequired::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {

        // ── Helpers ─────────────────────────────────────────────────────────

        $isApi = static fn (Request $request): bool => $request->is('api/*') || $request->expectsJson();

        $apiError = static fn (string $message, int $status, ?string $errorId = null): JsonResponse => response()->json(array_filter([
            'message' => $message,
            'status' => $status,
            'error_id' => $errorId,
        ]), $status);

        $inertiaError = static function (Request $request, int $status, string $messageKey, ?string $errorId = null) {
            $message = __("errors.{$messageKey}");

            return inertia('Error', [
                'status' => $status,
                'message' => $message,
                'error_id' => $errorId,
            ])->toResponse($request)->setStatusCode($status);
        };

        // ── Authentication ───────────────────────────────────────────────────

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($isApi, $apiError) {
            if ($isApi($request)) {
                return $apiError(__('errors.unauthorized'), 401);
            }

            if ($request->is('provider/portal*')) {
                return redirect()->guest(route('provider.portal.login'));
            }

            return redirect()->guest(route('login'));
        });

        // ── Authorization ────────────────────────────────────────────────────

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) use ($isApi, $apiError, $inertiaError) {
            if ($isApi($request)) {
                return $apiError(__('errors.forbidden'), 403);
            }

            return $inertiaError($request, 403, 'forbidden');
        });

        // ── Model / Route Not Found ──────────────────────────────────────────

        $exceptions->render(function (ModelNotFoundException $e, Request $request) use ($isApi, $apiError, $inertiaError) {
            if ($isApi($request)) {
                return $apiError(__('errors.not_found'), 404);
            }

            return $inertiaError($request, 404, 'not_found');
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($isApi, $apiError, $inertiaError) {
            if ($isApi($request)) {
                return $apiError(__('errors.not_found'), 404);
            }

            return $inertiaError($request, 404, 'not_found');
        });

        // ── Method Not Allowed ───────────────────────────────────────────────

        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) use ($isApi, $apiError, $inertiaError) {
            if ($isApi($request)) {
                return $apiError(__('errors.method_not_allowed'), 405);
            }

            return $inertiaError($request, 405, 'method_not_allowed');
        });

        // ── CSRF / Session Expired ───────────────────────────────────────────

        $exceptions->render(function (TokenMismatchException $e, Request $request) use ($isApi, $apiError, $inertiaError) {
            if ($isApi($request)) {
                return $apiError(__('errors.session_expired'), 419);
            }

            return $inertiaError($request, 419, 'session_expired');
        });

        // ── Rate Limiting ────────────────────────────────────────────────────

        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) use ($isApi, $apiError, $inertiaError) {
            if ($isApi($request)) {
                return $apiError(__('errors.too_many_requests'), 429);
            }

            return $inertiaError($request, 429, 'too_many_requests');
        });

        // ── Generic HTTP Exceptions (abort(4xx/5xx)) ─────────────────────────
        // Handles cases where abort() is called with a plain status code rather
        // than a typed exception subclass (e.g. abort(403), abort(429)).

        $exceptions->render(function (HttpException $e, Request $request) use ($isApi, $apiError, $inertiaError) {
            $status = $e->getStatusCode();

            $messageKey = match (true) {
                $status === 401 => 'unauthorized',
                $status === 403 => 'forbidden',
                $status === 404 => 'not_found',
                $status === 405 => 'method_not_allowed',
                $status === 419 => 'session_expired',
                $status === 429 => 'too_many_requests',
                $status === 503 => 'service_unavailable',
                $status >= 500 => 'generic',
                default => 'generic',
            };

            if ($isApi($request)) {
                return $apiError(__("errors.{$messageKey}"), $status);
            }

            return $inertiaError($request, $status, $messageKey);
        });

        // ── Business Logic Violations (DomainException) ─────────────────────
        // Actions throw DomainException for lifecycle violations (e.g. issuing
        // a non-printed card). Return a 409 Conflict with the safe message.

        $exceptions->render(function (DomainException $e, Request $request) use ($isApi, $apiError) {
            if ($isApi($request)) {
                return $apiError($e->getMessage(), 409);
            }

            return back()->withErrors(['action' => $e->getMessage()])->withInput();
        });

        // ── Database Errors ──────────────────────────────────────────────────

        $exceptions->render(function (QueryException $e, Request $request) use ($isApi, $apiError, $inertiaError) {
            $errorId = app(ErrorLoggingService::class)->log($e, $request);

            if ($isApi($request)) {
                return $apiError(__('errors.api_generic'), 500, $errorId);
            }

            return $inertiaError($request, 500, 'generic', $errorId);
        });

        // ── Catchall Unexpected Exceptions ──────────────────────────────────

        $exceptions->render(function (Throwable $e, Request $request) use ($isApi, $apiError, $inertiaError) {
            // Let validation exceptions propagate normally.
            if ($e instanceof ValidationException) {
                return null;
            }

            $errorId = app(ErrorLoggingService::class)->log($e, $request);

            if ($isApi($request)) {
                return $apiError(__('errors.api_generic'), 500, $errorId);
            }

            // In debug mode, let Laravel's default handler take over for web requests
            // so developers still see Ignition/Whoops.
            if (config('app.debug')) {
                return null;
            }

            return $inertiaError($request, 500, 'generic', $errorId);
        });

        // ── Suppress default exception reporting for known/safe exceptions ───

        $exceptions->dontReport([
            AuthenticationException::class,
            AccessDeniedHttpException::class,
            ModelNotFoundException::class,
            NotFoundHttpException::class,
            MethodNotAllowedHttpException::class,
            TokenMismatchException::class,
            TooManyRequestsHttpException::class,
            ValidationException::class,
            DomainException::class,
        ]);

    })->create();

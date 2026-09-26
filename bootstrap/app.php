<?php

use App\Http\Middleware\AuthenticateSession;
use App\Http\Middleware\EnforceIdempotencyKey;
use App\Http\Middleware\EnforceSessionIdleTimeout;
use App\Http\Middleware\EnsureAdminAccess;
use App\Http\Middleware\EnsureApiScope;
use App\Http\Middleware\EnsureCafeteriaPortalUser;
use App\Http\Middleware\EnsureCafeteriaProviderAssigned;
use App\Http\Middleware\EnsureMfaNotRequired;
use App\Http\Middleware\EnsureProviderHasActiveAssignment;
use App\Http\Middleware\EnsureProviderPortalUser;
use App\Http\Middleware\EnsureProviderServiceEnabled;
use App\Http\Middleware\EnsureProviderServiceScope;
use App\Http\Middleware\EnsurePublicSiteEnabled;
use App\Http\Middleware\ExternalApplicationGate;
use App\Http\Middleware\ForcePasswordChange;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequestCorrelationId;
use App\Http\Middleware\RequireMfa;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetCafeteriaPortalContext;
use App\Http\Middleware\SetClientLocale;
use App\Http\Middleware\SetProviderPortalContext;
use App\Models\ProviderUser;
use App\Services\ErrorLoggingService;
use App\Services\ProviderPortal\ProviderPortalContext;
use App\Services\Security\SessionActivityService;
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

        /*
         * Session policy (docs/session-management.md):
         *  - EnforceSessionIdleTimeout ends idle sessions before any route
         *    middleware runs, for every guard;
         *  - AuthenticateSession signs out other sessions after a password
         *    change. It is last so the priority sort places route `auth`
         *    after HandleInertiaRequests, as before.
         */
        // Server text in the language chosen in the browser (a plain display-preference cookie).
        $middleware->encryptCookies(except: [SetClientLocale::COOKIE]);

        $middleware->web(append: [
            SetClientLocale::class,
            EnforceSessionIdleTimeout::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            AuthenticateSession::class,
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
            'public.site' => EnsurePublicSiteEnabled::class,
        ]);

        /*
         * `guest` sends a signed-in visitor to the admin dashboard. A provider
         * opening the portal login goes to their portal home instead: the
         * admin dashboard would bounce them on to the staff login.
         */
        $middleware->redirectUsersTo(static function (Request $request): string {
            $providerUser = $request->is('provider/portal*', 'cafeteria/portal*') ? auth('provider')->user() : null;

            return $providerUser instanceof ProviderUser
                ? app(ProviderPortalContext::class)->homeUrl($providerUser)
                : route('dashboard');
        });
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

        // ── CSRF token mismatch (419) ────────────────────────────────────────
        // Laravel turns TokenMismatchException into HttpException(419) BEFORE
        // render callbacks run, so a TokenMismatchException callback never
        // fires — which is why every 419 used to become "session expired".
        // The decision lives here and is called from the HttpException
        // handler below.
        //
        // A 419 is NOT automatically "your session expired". Three cases:
        //  1. signed-in session, stale token (e.g. another tab rotated it):
        //     the session is fine — send the user back to a fresh page; the
        //     submission is never replayed;
        //  2. the session is gone on a route that needs sign-in: it ended
        //     (inactivity, or the reason recorded when it ended) — go to the
        //     right login page with that reason;
        //  3. a public/guest page (login form left open): the page expired —
        //     back to it with a fresh token.

        $csrfMismatch = static function (Request $request) use ($isApi) {
            $activity = app(SessionActivityService::class);
            $guards = $request->hasSession() ? $activity->authenticatedGuards() : [];

            if ($guards !== [] && $activity->restoredFromRememberCookie($guards)) {
                $activity->end($request, SessionActivityService::REASON_IDLE, $guards);

                return $activity->expiredResponse($request, SessionActivityService::REASON_IDLE, $guards);
            }

            $route = $request->route();
            $needsSignIn = $route !== null && collect($route->gatherMiddleware())
                ->contains(static fn ($m): bool => is_string($m) && ($m === 'auth' || str_starts_with($m, 'auth:')));

            if ($guards === [] && $needsSignIn && $request->hasSession()) {
                $reason = (string) $request->session()->get(SessionActivityService::END_REASON_KEY, SessionActivityService::REASON_IDLE);
                if ($reason === SessionActivityService::REASON_IDLE) {
                    $request->session()->flash(SessionActivityService::NOTICE_KEY, SessionActivityService::REASON_IDLE);
                }

                return $activity->expiredResponse($request, $reason);
            }

            if ($isApi($request) && ! $request->header('X-Inertia')) {
                return response()->json([
                    'message' => __('errors.page_expired_retry'),
                    'status' => 419,
                    'reason' => SessionActivityService::REASON_PAGE_EXPIRED,
                ], 419);
            }

            return redirect()->back()
                ->with(SessionActivityService::NOTICE_KEY, SessionActivityService::REASON_PAGE_EXPIRED)
                ->with('warning', __('errors.page_expired_retry'));
        };

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

        $exceptions->render(function (HttpException $e, Request $request) use ($isApi, $apiError, $inertiaError, $csrfMismatch) {
            $status = $e->getStatusCode();

            if ($status === 419) {
                return $csrfMismatch($request);
            }

            $messageKey = match (true) {
                $status === 401 => 'unauthorized',
                $status === 403 => 'forbidden',
                $status === 404 => 'not_found',
                $status === 405 => 'method_not_allowed',
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

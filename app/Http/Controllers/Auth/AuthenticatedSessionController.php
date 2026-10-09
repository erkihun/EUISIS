<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\Dashboard\DashboardDataService;
use App\Services\Security\DefaultPasswordPolicyService;
use App\Services\Security\SessionActivityService;
use App\Services\SystemSettings\PublicSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function __construct(
        private readonly DefaultPasswordPolicyService $defaultPasswordPolicy,
        private readonly WriteAuditLogAction $writeAuditLog,
        private readonly SessionActivityService $sessionActivity,
    ) {}

    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
            // Why the last session ended (idle timeout, password changed,
            // page expired). A code; the page localizes it.
            'sessionNotice' => session(SessionActivityService::NOTICE_KEY),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request, DashboardDataService $dashboardService): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();
        $this->sessionActivity->startAuthenticatedSession($request->session());

        $user = $request->user();
        if ($user !== null) {
            $this->writeAuditLog->execute(AuditEventType::UserLoggedIn, $user, $user, request: $request);

            $loggedInWithDefaultPassword = $this->defaultPasswordPolicy->matches(
                (string) $request->validated('password'),
            );

            $user->forceFill([
                'last_login_at' => now(),
                'must_change_password' => $loggedInWithDefaultPassword
                    ? true
                    : $user->must_change_password,
                'password_changed_at' => $loggedInWithDefaultPassword
                    ? null
                    : $user->password_changed_at,
                'first_login_at' => ! $loggedInWithDefaultPassword && ! $user->mustChangePassword()
                    ? ($user->first_login_at ?? now())
                    : $user->first_login_at,
            ])->save();

            if ($loggedInWithDefaultPassword) {
                $this->writeAuditLog->execute(
                    AuditEventType::UserLoggedInWithDefaultPassword,
                    $user,
                    $user,
                    reason: 'configured_default_password_matched_at_login',
                    request: $request,
                );
            }

            if ($user->mustChangePassword()) {
                return redirect()->route('password.forced');
            }
        }

        $defaultRoute = $user !== null && $dashboardService->canViewDashboard($user)
            ? $this->configuredLandingRoute($user)
            : route('employee.portal', absolute: false);

        return redirect()->intended($defaultRoute);
    }

    /**
     * Where an administrator lands after signing in.
     *
     * Honours `general.default_dashboard_route`, which was configurable but
     * read by nothing — every user was sent to the dashboard regardless. An
     * office whose staff live in the employee register can now start there.
     *
     * Falls back to the dashboard whenever the configured route does not
     * exist or the user may not open it, so a stale or over-ambitious setting
     * can never lock somebody out of their own landing page.
     */
    private function configuredLandingRoute(User $user): string
    {
        $dashboard = route('dashboard', absolute: false);

        $configured = (string) (app(PublicSettingsService::class)
            ->shareableSettings()['general.default_dashboard_route'] ?? 'dashboard');

        if ($configured === '' || $configured === 'dashboard' || ! Route::has($configured)) {
            return $dashboard;
        }

        /*
         * The setting names a route, not a permission, so the gate is checked
         * against the permission that route's controller enforces.
         */
        $permission = match ($configured) {
            'employees.index' => 'employees.view',
            'organizations.index' => 'organizations.view',
            default => null,
        };

        if ($permission !== null && ! $user->can($permission)) {
            return $dashboard;
        }

        return route($configured, absolute: false);
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        // Signs out, destroys the session and its CSRF token, and records the
        // reason so other tabs are never told this was an inactivity timeout.
        $this->sessionActivity->end($request, SessionActivityService::REASON_LOGGED_OUT, ['web']);

        return redirect('/');
    }
}

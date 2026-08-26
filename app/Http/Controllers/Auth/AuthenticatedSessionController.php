<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Dashboard\DashboardDataService;
use App\Services\Security\DefaultPasswordPolicyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function __construct(
        private readonly DefaultPasswordPolicyService $defaultPasswordPolicy,
        private readonly WriteAuditLogAction $writeAuditLog,
    ) {}

    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request, DashboardDataService $dashboardService): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $user = $request->user();
        if ($user !== null) {
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
            ? route('dashboard', absolute: false)
            : route('employee.portal', absolute: false);

        return redirect()->intended($defaultRoute);
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}

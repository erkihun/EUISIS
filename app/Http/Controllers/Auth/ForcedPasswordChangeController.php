<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Security\Passwords\PasswordLifecycle;
use App\Security\Passwords\PasswordPolicy;
use App\Services\Dashboard\DashboardDataService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The forced password change a user meets on first login.
 *
 * Separate from PasswordController, which handles the voluntary change inside
 * the profile screen. This one is reached under duress: the user cannot go
 * anywhere else, so it renders on its own layout and redirects onward to the
 * dashboard once satisfied.
 */
class ForcedPasswordChangeController extends Controller
{
    public function __construct(
        private readonly PasswordPolicy $policy,
        private readonly PasswordLifecycle $lifecycle,
    ) {}

    public function create(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        // Nothing to force: send them where they were going.
        if ($user !== null && ! $user->mustChangePassword()) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Auth/ForcedPasswordChange');
    }

    public function update(Request $request, DashboardDataService $dashboardService): RedirectResponse
    {
        $user = $request->user();

        abort_if($user === null, 403);

        $validated = $request->validate([
            // The temporary password still has to be proved, so a hijacked
            // session cannot quietly take ownership of the account.
            'current_password' => ['required', 'current_password'],
            // The central policy also refuses the temporary password itself
            // (it is the current password) and the legacy shared default.
            'password' => $this->policy->rules($user),
        ]);

        $this->lifecycle->change($user, $validated['password'], AuditEventType::UserPasswordChanged, reason: 'forced_password_change_completed');

        /*
         * Rotate the session id so the identifier that existed while the shared
         * temporary password was in use is no longer valid.
         *
         * Other browsers/devices signed in with the temporary password are
         * signed out on their next request by the AuthenticateSession
         * middleware (the stored password hash no longer matches); this
         * session re-stores the new hash on the way out and stays signed in.
         */
        $request->session()->regenerate();

        $destination = $dashboardService->canViewDashboard($user)
            ? route('dashboard', absolute: false)
            : route('employee.portal', absolute: false);

        return redirect()->intended($destination)->with('flash', [
            'message' => __('auth.password_changed'),
            'type' => 'success',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardDataService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
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
    public function __construct(private readonly WriteAuditLogAction $writeAuditLog) {}

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
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        /*
         * Reusing the temporary password would leave the credential exactly as
         * shared as it was before, which is the whole thing this flow exists to
         * end. `current_password` above has already proved the old one, so this
         * comparison is a straight re-check.
         */
        if (Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => __('auth.password_must_differ'),
            ]);
        }

        // The model casts `password` as hashed, so assigning the plain value is
        // correct here; hashing it first would double-hash.
        $user->forceFill(['password' => $validated['password']])->save();
        $user->markPasswordChanged();

        /*
         * Rotate the session id so the identifier that existed while the shared
         * temporary password was in use is no longer valid.
         *
         * Laravel's `logoutOtherDevices()` is deliberately NOT called: it works
         * by rehashing the password and relies on the `AuthenticateSession`
         * middleware to compare hashes on every request. That middleware is not
         * enabled here, so the call would rehash the freshly saved password for
         * no benefit. Invalidating other devices properly needs that middleware
         * turned on first — see the handoff note.
         */
        $request->session()->regenerate();

        $this->writeAuditLog->execute(
            eventType: AuditEventType::UserPasswordChanged,
            actor: $user,
            auditable: $user,
            reason: 'Forced password change completed on first login',
            request: $request,
        );

        $destination = $dashboardService->canViewDashboard($user)
            ? route('dashboard', absolute: false)
            : route('employee.portal', absolute: false);

        return redirect()->intended($destination)->with('flash', [
            'message' => __('auth.password_changed'),
            'type' => 'success',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Security\Passwords\PasswordLifecycle;
use App\Security\Passwords\PasswordPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Voluntary password change from the profile screen. The current password is
 * verified server-side (the route is throttled), the new one goes through the
 * central policy, and the change through PasswordLifecycle.
 */
class PasswordController extends Controller
{
    public function __construct(
        private readonly PasswordPolicy $policy,
        private readonly PasswordLifecycle $lifecycle,
    ) {}

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => $this->policy->rules($user),
        ]);

        $this->lifecycle->change($user, $validated['password'], AuditEventType::UserPasswordChanged, reason: 'user_changed_own_password');

        // New session id for this browser; every other session is signed out
        // by AuthenticateSession on its next request.
        $request->session()->regenerate();

        return back();
    }
}

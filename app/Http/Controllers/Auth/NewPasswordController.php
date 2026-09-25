<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Security\Passwords\PasswordLifecycle;
use App\Security\Passwords\PasswordPolicy;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Forgot-password reset. Same central policy as every other flow, in two
 * stages so the form is never an oracle:
 *
 *  1. before the token is checked: only rules that know nothing about the
 *     account (length, common, breached) — anyone can submit this form with
 *     any email;
 *  2. after the broker has verified the token (inside its callback): the
 *     account-specific rules — personal information and the current/last N
 *     passwords. Asking "is this their current password?" therefore needs a
 *     valid, unexpired, single-use token for that account.
 *
 * Tokens are random, stored hashed, expire (auth.passwords.users.expire) and
 * are deleted on use by Laravel's broker; the route is throttled.
 */
class NewPasswordController extends Controller
{
    public function __construct(
        private readonly PasswordPolicy $policy,
        private readonly PasswordLifecycle $lifecycle,
    ) {}

    public function create(Request $request): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => $this->policy->rules(),
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) use ($request): void {
                Validator::make(
                    ['password' => (string) $request->password, 'password_confirmation' => (string) $request->password_confirmation],
                    ['password' => $this->policy->rules($user)],
                )->validate();

                $this->lifecycle->change(
                    $user,
                    (string) $request->password,
                    AuditEventType::PasswordResetCompleted,
                    PasswordLifecycle::KIND_RESET,
                    reason: 'password_reset_with_emailed_token',
                );

                event(new PasswordReset($user));
            }
        );

        if ($status == Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('status', __($status));
        }

        throw ValidationException::withMessages([
            'email' => [trans($status)],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]);
    }

    /**
     * Send a reset link — and answer identically whether or not the account
     * exists, whether the per-account resend throttle applied, and whether
     * the mail server took the message, so the form cannot be used to
     * discover which emails have accounts. The route is rate-limited per IP;
     * the broker throttles per account.
     *
     * Because the visitor always sees the same answer, what really happened
     * is written to the audit log (and a mail failure to the error log), so
     * an administrator can tell "sent", "throttled" and "mail failed" apart.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        // Phone keyboards often capitalise the first letter, and the stored
        // address may differ in case. Match regardless of case, then send to
        // the address as stored.
        $email = mb_strtolower(trim((string) $request->input('email')));
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user !== null) {
            try {
                $status = Password::sendResetLink(['email' => $user->email]);
            } catch (Throwable $exception) {
                // A mail server that refuses or cannot be reached must not
                // turn into an error page: that would answer differently for
                // an existing account than for an unknown one.
                $status = 'mail_failed';
                Log::error('Password reset email could not be sent.', ['user_id' => $user->getKey(), 'error' => $exception->getMessage()]);
            }

            $this->audit($request, $user, match ($status) {
                Password::RESET_LINK_SENT => 'password_reset_link_emailed',
                Password::RESET_THROTTLED => 'password_reset_throttled',
                'mail_failed' => 'password_reset_email_failed',
                default => 'password_reset_not_sent',
            });
        }

        return back()->with('status', __('password-policy.reset_link_sent'));
    }

    private function audit(Request $request, User $user, string $reason): void
    {
        try {
            app(WriteAuditLogAction::class)->execute(
                AuditEventType::PasswordResetRequested,
                null,
                $user,
                reason: $reason,
                request: $request,
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}

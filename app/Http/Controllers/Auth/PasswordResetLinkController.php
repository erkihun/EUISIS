<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
     * exists, and whether or not the per-account resend throttle applied, so
     * the form cannot be used to discover which emails have accounts.
     * The route is rate-limited per IP; the broker throttles per account.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $status = Password::sendResetLink($request->only('email'));

        if ($status === Password::RESET_LINK_SENT) {
            $this->audit($request);
        }

        return back()->with('status', __('password-policy.reset_link_sent'));
    }

    private function audit(Request $request): void
    {
        try {
            $user = User::query()->where('email', (string) $request->input('email'))->first();
            if ($user !== null) {
                app(WriteAuditLogAction::class)->execute(
                    AuditEventType::PasswordResetRequested,
                    null,
                    $user,
                    reason: 'password_reset_link_emailed',
                    request: $request,
                );
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}

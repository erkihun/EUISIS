<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Http\Middleware\TrackSessionActivity;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The EUISIS session-policy service, as the copied provider portal calls it:
 * it marks a session started and ends one on sign-out. The idle timeout itself
 * is enforced by TrackSessionActivity.
 */
final class SessionActivityService
{
    public const NOTICE_KEY = 'session_notice';

    public const END_REASON_KEY = 'session_policy.end_reason';

    public const REASON_IDLE = 'idle_timeout';

    public const REASON_LOGGED_OUT = 'logged_out';

    public const REASON_PASSWORD_CHANGED = 'password_changed';

    public const REASON_ACCOUNT_DISABLED = 'account_disabled';

    public function startAuthenticatedSession(Session $session): void
    {
        $session->put(TrackSessionActivity::KEY, time());
    }

    /** @param  list<string>  $guards */
    public function end(Request $request, string $reason, array $guards): void
    {
        foreach ($guards as $guard) {
            Auth::guard($guard)->logout();
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put(self::END_REASON_KEY, $reason);

        if (in_array($reason, [self::REASON_IDLE, self::REASON_PASSWORD_CHANGED], true)) {
            $request->session()->flash(self::NOTICE_KEY, $reason);
        }
    }
}

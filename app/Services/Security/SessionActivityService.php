<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Actions\Audit\WriteAuditLogAction;
use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * The one place that decides when an authenticated session is idle.
 *
 *   idle timeout      System Settings -> Security -> Session Timeout Minutes,
 *                     measured from the last MEANINGFUL request of THIS
 *                     session (server clock, never a client timestamp)
 *   storage lifetime  Laravel's `session.lifetime` = idle + grace, so the
 *                     session outlives the policy and is ended here, with a
 *                     reason, rather than vanishing underneath it
 *
 * There is no absolute session lifetime. MFA-protected roles are re-challenged
 * on their own clock (`security.mfa_session_lifetime_minutes`).
 *
 * Activity is stored in the session itself, so every browser/device session
 * has its own idle clock and all tabs of one browser share one.
 */
class SessionActivityService
{
    public const LAST_ACTIVITY_KEY = 'session_policy.last_activity_at';

    /** Why the previous authentication ended; survives until the next login. */
    public const END_REASON_KEY = 'session_policy.end_reason';

    /** Flashed for the login page, which localizes it. */
    public const NOTICE_KEY = 'session_notice';

    public const REASON_IDLE = 'idle_timeout';

    public const REASON_LOGGED_OUT = 'logged_out';

    public const REASON_PASSWORD_CHANGED = 'password_changed';

    public const REASON_ACCOUNT_DISABLED = 'account_disabled';

    public const REASON_PAGE_EXPIRED = 'page_expired';

    /** Session guards that can hold a signed-in identity in the one session. */
    public const GUARDS = ['web', 'provider', 'cafeteria_provider'];

    public function __construct(private readonly WriteAuditLogAction $writeAuditLog) {}

    public function idleTimeoutMinutes(): int
    {
        return max(5, min(1440, (int) config('security.session.idle_timeout_minutes', 120)));
    }

    public function idleTimeoutSeconds(): int
    {
        return $this->idleTimeoutMinutes() * 60;
    }

    /** Storage must outlive the idle policy; see the class comment. */
    public static function storageLifetimeMinutes(int $idleTimeoutMinutes): int
    {
        return $idleTimeoutMinutes + max(5, (int) config('security.session.storage_grace_minutes', 30));
    }

    /** How long before expiry the warning appears: 20% of the timeout, 1–5 min. */
    public function warningSeconds(): int
    {
        return max(60, min(300, (int) round($this->idleTimeoutSeconds() * 0.2)));
    }

    /** At most one interaction heartbeat per this many seconds. */
    public function heartbeatSeconds(): int
    {
        return max(30, min(300, intdiv($this->idleTimeoutSeconds(), 4)));
    }

    /**
     * Guards signed in on this request.
     *
     * @return list<string>
     */
    public function authenticatedGuards(): array
    {
        return array_values(array_filter(self::GUARDS, static fn (string $guard): bool => Auth::guard($guard)->check()));
    }

    /** True when any guard was restored from a remember-me cookie on this request. */
    public function restoredFromRememberCookie(array $guards): bool
    {
        foreach ($guards as $guard) {
            $instance = Auth::guard($guard);
            if (method_exists($instance, 'viaRemember') && $instance->viaRemember()) {
                return true;
            }
        }

        return false;
    }

    public function lastActivityAt(Session $session): ?int
    {
        $value = $session->get(self::LAST_ACTIVITY_KEY);

        return is_int($value) ? $value : null;
    }

    public function isIdleExpired(Session $session): bool
    {
        $last = $this->lastActivityAt($session);

        return $last !== null && (now()->timestamp - $last) >= $this->idleTimeoutSeconds();
    }

    public function remainingSeconds(Session $session): int
    {
        $last = $this->lastActivityAt($session) ?? now()->timestamp;

        return max(0, $this->idleTimeoutSeconds() - (now()->timestamp - $last));
    }

    public function touch(Session $session): void
    {
        $session->put(self::LAST_ACTIVITY_KEY, now()->timestamp);
    }

    /** A fresh authentication starts a fresh idle clock and forgets old reasons. */
    public function startAuthenticatedSession(Session $session): void
    {
        $this->touch($session);
        $session->forget(self::END_REASON_KEY);
    }

    /**
     * Whether this request is the user doing something.
     *
     * Decided on the server. Routes listed in `security.session.passive_routes`
     * are passive whatever the client claims; a client may additionally mark a
     * request passive (`X-Activity: passive`), which can only ever make a
     * request count LESS, so it cannot be used to extend a session.
     */
    public function isMeaningful(Request $request): bool
    {
        if (in_array($request->method(), ['HEAD', 'OPTIONS'], true)) {
            return false;
        }

        $route = $request->route();
        $name = is_object($route) && method_exists($route, 'getName') ? $route->getName() : null;
        if ($name !== null && in_array($name, (array) config('security.session.passive_routes', []), true)) {
            return false;
        }

        if (strtolower((string) $request->header('X-Activity')) === 'passive') {
            return false;
        }

        // Link prefetching is the browser guessing, not the user acting.
        return ! $request->headers->has('Purpose') && ! $request->headers->has('X-Inertia-Prefetch');
    }

    /**
     * End the session for a reason: sign out every guard, destroy the session
     * (so its id and CSRF token are dead), and leave the reason behind.
     *
     * Nothing secret is logged; the audit row carries the reason only.
     *
     * @param  list<string>  $guards
     */
    public function end(Request $request, string $reason, array $guards): void
    {
        $users = [];
        foreach ($guards as $guard) {
            $users[] = Auth::guard($guard)->user();
            // Also forgets any remember-me cookie, so it cannot revive the session.
            Auth::guard($guard)->logout();
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put(self::END_REASON_KEY, $reason);

        if (in_array($reason, [self::REASON_IDLE, self::REASON_PASSWORD_CHANGED], true)) {
            $request->session()->flash(self::NOTICE_KEY, $reason);
        }

        foreach (array_filter($users) as $user) {
            $this->audit($reason, $user, $request);
        }
    }

    /** Record why a session ended. */
    public function audit(string $reason, Authenticatable $user, Request $request): void
    {
        $event = match ($reason) {
            self::REASON_IDLE => AuditEventType::SessionIdleTimeout,
            self::REASON_PASSWORD_CHANGED => AuditEventType::SessionRevoked,
            self::REASON_LOGGED_OUT => AuditEventType::UserLoggedOut,
            default => null,
        };

        if ($event === null) {
            return;
        }

        try {
            $this->writeAuditLog->execute(
                eventType: $event,
                actor: $user instanceof User ? $user : null,
                auditable: $user instanceof Model ? $user : null,
                reason: $reason,
                request: $request,
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Sign-in page for whoever was signed in. Provider accounts have their
     * own portal login; everyone else (administrators and employees) signs
     * in at /login.
     *
     * @param  list<string>  $guards
     */
    public function loginUrl(Request $request, array $guards = []): string
    {
        $providerOnly = $guards !== [] && ! in_array('web', $guards, true);

        return $providerOnly || $request->is('provider/portal*', 'cafeteria/portal*')
            ? route('provider.portal.login')
            : route('login');
    }

    /**
     * The response for a request that arrived on an expired session: JSON
     * callers get 401 with the reason and where to sign in; page requests
     * (including Inertia) are redirected to the right login page.
     *
     * @param  list<string>  $guards
     */
    public function expiredResponse(Request $request, string $reason, array $guards = []): JsonResponse|RedirectResponse
    {
        $login = $this->loginUrl($request, $guards);

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'message' => __('security.session_expired_idle'),
                'reason' => $reason,
                'redirect' => $login,
            ], 401);
        }

        // Return to the page after signing in again — for page views only; a
        // form submission is never replayed.
        if ($request->isMethod('GET') && $this->isMeaningful($request)) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return redirect()->to($login);
    }
}

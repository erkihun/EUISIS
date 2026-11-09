<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Security\SessionActivityService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Session\Middleware\AuthenticateSession as BaseAuthenticateSession;

/**
 * Ends every other session of an account whose password has changed.
 *
 * Laravel's middleware keeps a hash of the password in each session and signs
 * the session out when it no longer matches. The session that made the change
 * re-stores the new hash on the way out, so it stays signed in; every other
 * browser or device is signed out on its next request.
 *
 * This subclass only records why, so the login page can say so and the other
 * tabs are not told their session "expired due to inactivity".
 */
class AuthenticateSession extends BaseAuthenticateSession
{
    protected function logout($request)
    {
        $user = $request->user();

        $this->guard()->logoutCurrentDevice();

        $request->session()->flush();
        $request->session()->regenerateToken();
        $request->session()->put(SessionActivityService::END_REASON_KEY, SessionActivityService::REASON_PASSWORD_CHANGED);
        $request->session()->flash(SessionActivityService::NOTICE_KEY, SessionActivityService::REASON_PASSWORD_CHANGED);

        if ($user !== null) {
            app(SessionActivityService::class)->audit(SessionActivityService::REASON_PASSWORD_CHANGED, $user, $request);
        }

        throw new AuthenticationException(
            'Unauthenticated.', [$this->auth->getDefaultDriver()], $this->redirectTo($request)
        );
    }
}

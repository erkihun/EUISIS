<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Security\SessionActivityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends a signed-in session that has been idle past the configured timeout.
 *
 * Runs in the web group after the session has started and CSRF has been
 * verified, before any route middleware or controller, for every guard.
 *
 * Order matters: the expiry check happens BEFORE this request's activity is
 * recorded, so a request arriving on an already-expired session can never
 * revive it. Only meaningful requests move the idle clock.
 */
class EnforceSessionIdleTimeout
{
    public function __construct(private readonly SessionActivityService $activity) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();
        $guards = $this->activity->authenticatedGuards();

        if ($guards !== []) {
            /*
             * A remember-me cookie only acts once the session is gone, and the
             * session only goes after idle timeout + grace. Honouring it would
             * silently defeat the idle policy, so it ends the session instead.
             */
            if ($this->activity->restoredFromRememberCookie($guards) || $this->activity->isIdleExpired($session)) {
                $this->activity->end($request, SessionActivityService::REASON_IDLE, $guards);

                return $this->activity->expiredResponse($request, SessionActivityService::REASON_IDLE, $guards);
            }

            if ($this->activity->lastActivityAt($session) === null || $this->activity->isMeaningful($request)) {
                $this->activity->touch($session);
            }
        }

        $response = $next($request);

        // Signed in during this request (login, provider login): start the clock.
        if ($guards === [] && $this->activity->authenticatedGuards() !== []) {
            $this->activity->startAuthenticatedSession($request->session());
        }

        return $response;
    }
}

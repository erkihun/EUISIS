<?php

declare(strict_types=1);

namespace Modules\Court\Http\Middleware;

use Modules\Court\Domain\Models\Applicant;
use Modules\Court\Application\Services\CaseParties;
use Modules\Court\Application\Support\EuisisParties;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Flow 6: with EUISIS_PORTAL_IN_EUISIS on, employees file, follow and answer
 * their cases in the EUISIS employee portal, so the applicant and respondent
 * portal is gone from the web: every applicant, respondent and apply URL
 * (sign-in, registration, password reset, the cases and replies) is not
 * found, and a session someone still holds is ended. The one exception is a
 * verified lawyer signed in at /lawyer/login (EUISIS ADR 0013 #13), who works
 * on the cases of those they represent in the portal's case pages. The route names stay
 * registered for the portal's own controllers, which the integration API
 * runs for the employee (EuisisCaseWorkspaceController). Staff pages and the
 * integration API are untouched.
 */
final class CourtPortalInEuisis
{
    private const PATHS = ['court/applicant', 'court/applicant/*', 'court/respondent', 'court/respondent/*', 'court/apply', 'court/apply/*'];

    /** The portal's own sign-in, registration and password pages: gone for everyone. */
    private const GUEST_PATHS = ['court/applicant/login', 'court/applicant/register', 'court/applicant/forgot-password', 'court/applicant/password-otp*', 'court/applicant/new-password', 'court/respondent/*', 'court/apply/*'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! EuisisParties::portalClosed() || ! $request->is(...self::PATHS)) {
            return $next($request);
        }

        // A verified lawyer, signed in at /lawyer/login, works here on the cases they represent.
        $applicant = Auth::guard('applicant')->user();
        if ($applicant instanceof Applicant && CaseParties::isVerifiedLawyer($applicant)) {
            abort_if($request->is(...self::GUEST_PATHS), 404);

            return $next($request);
        }

        foreach (['applicant', 'respondent'] as $guard) {
            if (Auth::guard($guard)->check()) {
                Auth::guard($guard)->logout();
            }
        }

        abort(404);
    }
}

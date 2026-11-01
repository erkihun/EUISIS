<?php

declare(strict_types=1);

namespace Modules\Court\Http\Controllers\Applicant;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Application\Services\Euisis\EmployeeCourtAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Opens the applicant portal for an employee coming from the EUISIS employee
 * portal (Flow 4): a valid one-time ticket signs them in, as an applicant, and
 * sends them to the page they chose there. Anything else goes to the sign-in page.
 */
class EuisisSignInController extends Controller
{
    public function __invoke(Request $request, string $ticket, EmployeeCourtAccess $access): RedirectResponse
    {
        $result = $access->redeem($ticket, $request->ip());
        if ($result === null) {
            return redirect()->route('court.applicant.login')->with('error', __('court::applicant.euisis_sign_in_expired'));
        }

        // A different person may be signed in in this browser; the ticket's person replaces them.
        Auth::guard('applicant')->logout();
        Auth::guard('applicant')->login($result['applicant']);
        $request->session()->regenerate();
        $request->session()->forget('acting_as_respondent');
        $request->session()->put('signed_in_via_euisis', true);

        return redirect()->to($result['redirect']);
    }
}

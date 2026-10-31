<?php

namespace Modules\Court\Http\Controllers\Applicant;

use Modules\Court\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ApplicantRoleSwitchController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        // Mark session as acting respondent; use applicant guard/session
        $request->session()->regenerate();
        $request->session()->put('acting_as_respondent', true);

        return redirect()
            ->route('court.respondent.dashboard')
            ->with('success', __('court::app.switch_to_respondent_success'));
    }
}

<?php

declare(strict_types=1);

namespace Modules\Court\Http\Controllers\Admin;

use Modules\Court\Http\Controllers\Controller;
use Modules\Court\Application\Services\Security\UserSessionRegistry;
use Modules\Court\Application\Support\SessionRows;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ActiveSessionController extends Controller
{
    /** The profile page's Devices tab lists sessions now (ProfileController::edit). */
    public function index(): RedirectResponse
    {
        return redirect()->to(route('court.profile.edit').'#devices');
    }

    /** `{session}` is the row's keyed hash (SessionRows::publicKey), as the Devices tab names it. */
    public function destroy(Request $request, string $session, UserSessionRegistry $sessions): RedirectResponse
    {
        $currentId = $request->session()->getId();
        abort_if(hash_equals(SessionRows::publicKey($currentId), $session), 422, __('court::auth.session_current_cannot_revoke'));

        $sessions->revoke($request->user(), $session, $currentId);

        return back()->with('status', __('court::auth.session_revoked'));
    }

    /** Ends the account's other staff sessions; applicant/respondent sessions sharing the numeric id stay. */
    public function destroyOthers(Request $request, UserSessionRegistry $sessions): RedirectResponse
    {
        $sessions->revokeOthers($request->user(), $request->session()->getId());

        return back()->with('status', __('court::auth.other_sessions_revoked'));
    }
}
